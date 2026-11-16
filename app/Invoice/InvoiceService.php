<?php

namespace App\Invoice;

use App\Invoice\Issuers\IssuerFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private InvoiceStateMachine $state = new InvoiceStateMachine(),
        private InvoiceNumbers $numbers = new InvoiceNumbers(),
        private InvoiceAttachments $attachments = new InvoiceAttachments(),
        private InvoiceGuard $guard = new InvoiceGuard(),
        private InvoiceDelivery $delivery = new InvoiceDelivery(),
        private IssuerFactory $issuers = new IssuerFactory(),
    ) {
    }

    /**
     * 业主提交/退回重提。
     *
     * @return array{0:object,1:?string,2:array,3:bool} [request, query_key, warnings, reused]
     */
    public function create(array $data, array $files, object $project, object $accountSet, ?Request $http = null): array
    {
        $houseNo = trim((string) $data['house_no']);
        $warnings = [];

        // 退回重提：原单 version+1，保留历史审计
        if (!empty($data['resubmit_no'])) {
            $row = DB::table('invoice_requests')
                ->where('request_no', $data['resubmit_no'])
                ->where('account_set_id', $accountSet->id)
                ->first();
            if (!$row) {
                throw new \DomainException('原申请单不存在');
            }
            if (!in_array($row->status, ['rejected'], true)) {
                throw new \DomainException('仅已退回的申请单可以修改重提');
            }
            if (!hash_equals((string) $row->query_key_hash, $this->numbers->hashKey((string) ($data['query_key'] ?? '')))) {
                throw new \DomainException('查询密钥不正确');
            }

            $this->attachments->deleteForRequest((int) $row->id);
            $update = $this->fillable($data, $project, $accountSet) + [
                'status' => 'submitted',
                'version' => ((int) $row->version) + 1,
                'reject_reason' => null,
                'review_note' => null,
                'updated_at' => now(),
            ];
            DB::table('invoice_requests')->where('id', $row->id)->update($update);
            $row = DB::table('invoice_requests')->where('id', $row->id)->first();
            if ($files) {
                $this->attachments->storeMany($files, $row, (string) $accountSet->code);
            }
            $this->refreshAttachmentCount((int) $row->id);
            $this->guard->audit((int) $row->id, (int) $row->account_set_id, 'owner_resubmit', [], 'owner', null, 'rejected', 'submitted', $http);

            return [DB::table('invoice_requests')->where('id', $row->id)->first(), null, [], false];
        }

        // 对外单号始终服务端生成（INV+日期+序号）；client_request_no 仅作幂等引用
        $clientRef = trim((string) ($data['client_request_no'] ?? ''));
        if ($clientRef === '' || !preg_match('/^[A-Za-z0-9_-]{6,32}$/', $clientRef)) {
            $clientRef = $this->numbers->requestNo();
        }
        $requestNo = $this->numbers->requestNo();
        $idempotencyKey = $this->numbers->idempotencyKey((int) $project->id, $houseNo, $clientRef);
        $existing = DB::table('invoice_requests')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [$existing, null, [], true];
        }

        $queryKey = $this->numbers->queryKey();
        $flags = $this->amountFlags($houseNo, (int) $accountSet->id, (float) $data['declared_amount'], $accountSet);
        if ($flags) {
            $warnings[] = '该房号近期已有开票申请或金额偏高，审核时会重点核对凭证。';
        }

        $row = array_merge($this->fillable($data, $project, $accountSet), [
            'request_no' => $requestNo,
            'status' => 'submitted',
            'version' => 1,
            'idempotency_key' => $idempotencyKey,
            'query_key_hash' => $this->numbers->hashKey($queryKey),
            'amount_flag' => $flags ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('invoice_requests')->insertGetId($row);
        $stored = DB::table('invoice_requests')->where('id', $id)->first();
        if ($files) {
            $this->attachments->storeMany($files, $stored, (string) $accountSet->code);
        }
        $this->refreshAttachmentCount($id);
        $this->guard->audit($id, (int) $accountSet->id, 'owner_submit', ['declared_amount' => $row['declared_amount']], 'owner', null, null, 'submitted', $http);

        return [DB::table('invoice_requests')->where('id', $id)->first(), $queryKey, $warnings, false];
    }

    public function approve(object $row, float $verifiedAmount, ?string $note, object $actor, ?Request $http = null): object
    {
        $this->state->assert((string) $row->status, 'approved');
        $limit = (float) ($this->accountSet((int) $row->account_set_id)->amount_limit ?? config('invoice.defaults.amount_limit'));
        if ($verifiedAmount <= 0 || $verifiedAmount > $limit) {
            throw new \DomainException('核定金额需大于 0 且不超过账套上限 ¥' . number_format($limit, 2));
        }
        $rate = (float) $row->tax_rate_snapshot;
        $tax = round($verifiedAmount - ($verifiedAmount / (1 + $rate)), 2);
        $withoutTax = round($verifiedAmount - $tax, 2);

        $row = $this->state->transition($row, 'approved', 'staff', $actor, [
            'verified_amount' => $verifiedAmount,
            'amount_ex_tax' => $withoutTax,
            'tax_amount' => $tax,
            'review_note' => $note,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ], ['verified_amount' => $verifiedAmount, 'note' => $note], $http, $this->guard);

        return $this->issue($row, $actor, $http);
    }

    public function reject(object $row, string $reason, object $actor, ?Request $http = null): object
    {
        $this->state->assert((string) $row->status, 'rejected');
        return $this->state->transition($row, 'rejected', 'staff', $actor, [
            'reject_reason' => mb_substr($reason, 0, 255),
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ], ['reason' => $reason], $http, $this->guard);
    }

    public function issue(object $row, ?object $actor = null, ?Request $http = null): object
    {
        $this->state->assert((string) $row->status, 'issuing');
        $accountSet = $this->accountSet((int) $row->account_set_id);
        if (!$accountSet) {
            throw new \DomainException('开票账套不存在');
        }

        $row = $this->state->transition($row, 'issuing', $actor ? 'staff' : 'system', $actor, [], [], $http, $this->guard);

        $issuer = $this->issuers->for($accountSet);
        $result = $issuer->issue((array) $row, $accountSet);

        if (!($result['ok'] ?? false)) {
            return $this->state->transition($row, 'failed', 'system', $actor, [
                'issuer' => $issuer->name(),
                'fail_code' => mb_substr((string) ($result['fail_code'] ?? 'ISSUE_FAILED'), 0, 64),
                'fail_msg' => mb_substr((string) ($result['fail_msg'] ?? '开票失败'), 0, 500),
                'retry_count' => ((int) $row->retry_count) + 1,
            ], ['fail_code' => $result['fail_code'] ?? null], $http, $this->guard);
        }

        $row = $this->state->transition($row, 'issued', 'system', $actor, [
            'issuer' => $issuer->name(),
            'simulated' => !empty($result['simulated']) ? 1 : 0,
            'nuonuo_order_no' => $result['order_no'] ?? null,
            'digital_invoice_no' => $result['invoice_no'] ?? null,
            'invoice_date' => now()->toDateString(),
            'pdf_path' => $result['pdf_path'] ?? null,
            'ofd_path' => $result['ofd_path'] ?? null,
            'fail_code' => null,
            'fail_msg' => null,
        ], ['issuer' => $issuer->name(), 'simulated' => !empty($result['simulated'])], $http, $this->guard);

        return $this->deliver($row, $actor, $http);
    }

    public function deliver(object $row, ?object $actor = null, ?Request $http = null): object
    {
        if (!in_array($row->status, ['issued'], true)) {
            return $row;
        }
        $row = $this->state->transition($row, 'delivering', 'system', $actor, [
            'deliver_attempts' => 1,
            'next_delivery_at' => null,
        ], [], $http, $this->guard);

        $accountSet = $this->accountSet((int) $row->account_set_id);
        $result = $this->delivery->send($row, $accountSet, 1);

        if ($result['ok']) {
            return $this->state->transition($row, 'delivered', 'system', $actor, [
                'delivered_at' => now(),
                'next_delivery_at' => null,
            ], [], $http, $this->guard);
        }

        $minutes = config('invoice.defaults.delivery_retry_minutes', [1, 5, 15])[0] ?? 1;
        return $this->state->transition($row, 'delivered_failed', 'system', $actor, [
            'fail_msg' => mb_substr((string) ($result['error'] ?? '邮件发送失败'), 0, 500),
            'next_delivery_at' => now()->addMinutes((int) $minutes),
        ], [], $http, $this->guard);
    }

    public function retryDelivery(object $row): object
    {
        if (!in_array($row->status, ['delivered_failed', 'delivering'], true)) {
            return $row;
        }
        $attempt = ((int) $row->deliver_attempts) + 1;
        $accountSet = $this->accountSet((int) $row->account_set_id);
        if (!$accountSet) {
            return $row;
        }

        if ($row->status === 'delivered_failed') {
            $row = $this->state->transition($row, 'delivering', 'system', null, [
                'deliver_attempts' => $attempt,
            ], ['retry' => $attempt], null, $this->guard);
        } else {
            DB::table('invoice_requests')->where('id', $row->id)->update([
                'deliver_attempts' => $attempt,
                'updated_at' => now(),
            ]);
        }

        $result = $this->delivery->send($row, $accountSet, $attempt);
        if ($result['ok']) {
            return $this->state->transition($row, 'delivered', 'system', null, [
                'delivered_at' => now(),
                'next_delivery_at' => null,
            ], ['retry' => $attempt], null, $this->guard);
        }

        $retryMinutes = config('invoice.defaults.delivery_retry_minutes', [1, 5, 15]);
        $max = (int) config('invoice.defaults.delivery_max_attempts', 3);
        $next = $attempt >= $max ? null : now()->addMinutes((int) ($retryMinutes[$attempt - 1] ?? end($retryMinutes)));
        $failMsg = mb_substr((string) ($result['error'] ?? '邮件发送失败'), 0, 500);
        if ($row->status === 'delivering') {
            // 重试仍失败：回到 delivered_failed，保持 12 态口径一致
            $row = $this->state->transition($row, 'delivered_failed', 'system', null, [
                'fail_msg' => $failMsg,
                'next_delivery_at' => $next,
            ], ['retry' => $attempt], null, $this->guard);
        } else {
            DB::table('invoice_requests')->where('id', $row->id)->update([
                'fail_msg' => $failMsg,
                'next_delivery_at' => $next,
                'updated_at' => now(),
            ]);
        }
        if ($attempt >= $max) {
            $this->guard->audit((int) $row->id, (int) $row->account_set_id, 'delivery_alert', [
                'attempt' => $attempt,
                'error' => $result['error'] ?? null,
            ], 'system', null, (string) $row->status, (string) $row->status);
        }
        return DB::table('invoice_requests')->where('id', $row->id)->first();
    }

    /**
     * 后台手动重发邮件：已交付/已开票直接再发；失败态走重试队列同一路径。
     */
    public function resend(object $row, ?object $actor = null, ?Request $http = null): object
    {
        if (in_array($row->status, ['delivered_failed', 'delivering'], true)) {
            return $this->retryDelivery($row);
        }
        if ($row->status === 'issued') {
            return $this->deliver($row, $actor, $http);
        }
        if ($row->status !== 'delivered') {
            throw new \DomainException('当前状态不可重发');
        }

        $accountSet = $this->accountSet((int) $row->account_set_id);
        if (!$accountSet) {
            throw new \DomainException('开票账套不存在');
        }
        $attempt = ((int) $row->deliver_attempts) + 1;
        $result = $this->delivery->send($row, $accountSet, $attempt);

        $update = ['deliver_attempts' => $attempt, 'updated_at' => now()];
        if ($result['ok']) {
            $update['delivered_at'] = now();
            $update['fail_msg'] = null;
            $update['next_delivery_at'] = null;
        } else {
            $retryMinutes = config('invoice.defaults.delivery_retry_minutes', [1, 5, 15]);
            $max = (int) config('invoice.defaults.delivery_max_attempts', 3);
            $update['fail_msg'] = mb_substr((string) ($result['error'] ?? '邮件发送失败'), 0, 500);
            $update['next_delivery_at'] = $attempt >= $max ? null : now()->addMinutes((int) ($retryMinutes[$attempt - 1] ?? end($retryMinutes)));
        }
        DB::table('invoice_requests')->where('id', $row->id)->update($update);
        $this->guard->audit(
            (int) $row->id,
            (int) $row->account_set_id,
            'delivery_resend',
            ['attempt' => $attempt, 'ok' => (bool) $result['ok'], 'error' => $result['error'] ?? null],
            $actor ? 'staff' : 'system',
            $actor,
            (string) $row->status,
            (string) $row->status,
            $http
        );

        return DB::table('invoice_requests')->where('id', $row->id)->first();
    }

    public function redFlush(object $row, string $reason, object $actor, ?Request $http = null): object
    {
        $this->state->assert((string) $row->status, 'red_flushing');
        $row = $this->state->transition($row, 'red_flushing', 'staff', $actor, [], ['reason' => $reason], $http, $this->guard);
        $accountSet = $this->accountSet((int) $row->account_set_id);
        $result = $this->issuers->for($accountSet)->redFlush((array) $row, $accountSet, $reason);

        if (!($result['ok'] ?? false)) {
            return $this->state->transition($row, 'red_flush_failed', 'system', $actor, [
                'fail_code' => mb_substr((string) ($result['fail_code'] ?? 'RED_FAILED'), 0, 64),
                'fail_msg' => mb_substr((string) ($result['fail_msg'] ?? '红冲失败'), 0, 500),
            ], [], $http, $this->guard);
        }

        return $this->state->transition($row, 'red_flushed', 'system', $actor, [
            'red_confirm_no' => $result['red_confirm_no'] ?? null,
            'red_invoice_no' => $result['red_invoice_no'] ?? null,
        ], [], $http, $this->guard);
    }

    public function reissue(object $row, object $actor, ?Request $http = null): object
    {
        if (!in_array($row->status, ['red_flushed', 'delivered', 'issued'], true)) {
            throw new \DomainException('当前状态不允许重开');
        }
        $requestNo = $this->numbers->requestNo();
        $queryKey = $this->numbers->queryKey();
        $copy = (array) $row;
        unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['issued_at'], $copy['delivered_at']);
        $copy = array_merge($copy, [
            'request_no' => $requestNo,
            'status' => 'submitted',
            'version' => 1,
            'idempotency_key' => $this->numbers->idempotencyKey((int) $row->project_id, (string) $row->house_no, $requestNo),
            'query_key_hash' => $this->numbers->hashKey($queryKey),
            'parent_id' => $row->id,
            'digital_invoice_no' => null,
            'invoice_date' => null,
            'pdf_path' => null,
            'ofd_path' => null,
            'red_confirm_no' => null,
            'red_invoice_no' => null,
            'fail_code' => null,
            'fail_msg' => null,
            'reject_reason' => null,
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'delivered_at' => null,
            'deliver_attempts' => 0,
            'next_delivery_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = DB::table('invoice_requests')->insertGetId($copy);

        $attachments = DB::table('invoice_attachments')->where('request_id', $row->id)->get();
        foreach ($attachments as $a) {
            DB::table('invoice_attachments')->insert([
                'request_id' => $id,
                'account_set_id' => $a->account_set_id,
                'file_name' => $a->file_name,
                'file_path' => $a->file_path,
                'mime' => $a->mime,
                'size' => $a->size,
                'md5' => $a->md5,
                'sort' => $a->sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->guard->audit($id, (int) $row->account_set_id, 'reissue', ['parent_id' => $row->id], 'staff', $actor, null, 'submitted', $http);
        return DB::table('invoice_requests')->where('id', $id)->first();
    }

    public function cancel(object $row, string $operatorType, ?object $actor = null, ?Request $http = null): object
    {
        return $this->state->transition($row, 'cancelled', $operatorType, $actor, [], [], $http, $this->guard);
    }

    public function accountSet(int $id): ?object
    {
        return DB::table('invoice_account_sets')->where('id', $id)->first();
    }

    public function refreshAttachmentCount(int $requestId): void
    {
        $count = DB::table('invoice_attachments')->where('request_id', $requestId)->count();
        DB::table('invoice_requests')->where('id', $requestId)->update([
            'attachment_count' => $count,
            'updated_at' => now(),
        ]);
    }

    private function fillable(array $data, object $project, object $accountSet): array
    {
        return [
            'account_set_id' => $accountSet->id,
            'account_set_code' => $accountSet->code,
            'seller_name_snapshot' => $accountSet->seller_name,
            'seller_tax_no_snapshot' => $accountSet->seller_tax_no,
            'tax_rate_snapshot' => $accountSet->default_tax_rate,
            'goods_code_snapshot' => $accountSet->default_goods_code,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'house_no' => trim((string) $data['house_no']),
            'payer_name' => trim((string) $data['payer_name']),
            'fee_period' => $data['fee_period'] ?? null,
            'declared_amount' => round((float) $data['declared_amount'], 2),
            'title_type' => $data['title_type'] ?? 'personal',
            'title' => trim((string) $data['title']),
            'buyer_tax_no' => $data['buyer_tax_no'] ?? null,
            'buyer_email' => trim((string) $data['buyer_email']),
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'remark' => $data['remark'] ?? null,
            'attachment_count' => 0,
            'retry_count' => 0,
            'deliver_attempts' => 0,
        ];
    }

    private function amountFlags(string $houseNo, int $accountSetId, float $amount, object $accountSet): string
    {
        $flags = [];
        $limit = (float) $accountSet->amount_limit;
        if ($limit > 0 && $amount > $limit * 0.8) {
            $flags[] = 'large';
        }
        $since = now()->subDays(30);
        $recent = DB::table('invoice_requests')
            ->where('account_set_id', $accountSetId)
            ->where('house_no', $houseNo)
            ->whereIn('status', ['submitted', 'approved', 'issuing', 'issued', 'delivering', 'delivered'])
            ->where('created_at', '>=', $since)
            ->exists();
        if ($recent) {
            $flags[] = 'duplicate';
        }
        $avg = DB::table('invoice_requests')
            ->where('account_set_id', $accountSetId)
            ->where('house_no', $houseNo)
            ->whereNotNull('verified_amount')
            ->avg('verified_amount');
        if ($avg && abs($amount - (float) $avg) / max(0.01, (float) $avg) > 2) {
            $flags[] = 'over_avg';
        }
        return implode(',', array_unique($flags));
    }
}
