<?php

namespace App\Http\Controllers\Api;

use App\Invoice\InvoiceAttachments;
use App\Invoice\InvoiceGuard;
use App\Invoice\InvoiceNumbers;
use App\Invoice\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 后台开票管理：审核、开票、红冲、账套与审核人配置、统计导出。
 * 所有接口先过 InvoiceGuard（权限点 + 账套范围），越权返回 403 并写审计。
 */
class InvoiceAdminController extends ApiController
{
    public function __construct(
        private InvoiceGuard $guard,
        private InvoiceService $service,
        private InvoiceAttachments $attachments,
        private InvoiceNumbers $numbers,
    ) {
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if (!$this->guard->has($account, 'invoice_view') && !$this->guard->has($account, 'invoice_stats') && !$this->guard->has($account, 'invoice_review')) {
            return response()->json(['ok' => false, 'error' => '无此操作权限', 'code' => 'PERMISSION_DENIED'], 403);
        }
        $ids = $this->guard->accessibleAccountSets($account);
        $rows = DB::table('invoice_account_sets')->whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'code', 'name', 'seller_name', 'status']);
        $primary = DB::table('invoice_account_set_reviewers as r')
            ->leftJoin('payroll_accounts as a', 'a.id', '=', 'r.account_id')
            ->whereIn('r.account_set_id', $ids ?: [0])
            ->where('r.is_primary', 1)
            ->get(['r.account_set_id', 'a.name'])
            ->keyBy('account_set_id');
        return response()->json([
            'ok' => true,
            'items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'seller_name' => $r->seller_name,
                'status' => $r->status,
                'primary_reviewer' => $primary[$r->id]->name ?? null,
            ])->all(),
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_view', null, $request)) {
            return $deny;
        }
        $ids = $this->guard->accessibleAccountSets($account);
        if ($request->filled('account_set_id')) {
            $accountSetId = (int) $request->query('account_set_id');
            if ($deny = $this->guard->authorize($account, 'invoice_view', $accountSetId, $request)) {
                return $deny;
            }
            $ids = [$accountSetId];
        }

        $query = DB::table('invoice_requests')->whereIn('account_set_id', $ids ?: [0]);
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->filled('keyword')) {
            $kw = '%' . trim((string) $request->query('keyword')) . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('request_no', 'like', $kw)
                    ->orWhere('house_no', 'like', $kw)
                    ->orWhere('payer_name', 'like', $kw)
                    ->orWhere('title', 'like', $kw)
                    ->orWhere('digital_invoice_no', 'like', $kw);
            });
        }
        if ($request->filled('flag')) {
            $query->where('amount_flag', 'like', '%' . $request->query('flag') . '%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage((int) $request->query('page', 1), $perPage)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => (int) $request->query('page', 1),
            'per_page' => $perPage,
            'items' => $rows->map(fn ($row) => $this->presentRequest($row))->all(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_view', (int) $row->account_set_id, $request)) {
            return $deny;
        }
        $accountSet = DB::table('invoice_account_sets')->where('id', $row->account_set_id)->first();
        $logs = DB::table('invoice_request_logs')
            ->where('request_id', $row->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($l) => [
                'action' => $l->action,
                'from' => $l->from_status,
                'to' => $l->to_status,
                'operator' => $l->operator_name,
                'type' => $l->operator_type,
                'at' => $l->created_at,
                'payload' => $l->payload ? json_decode($l->payload, true) : null,
            ])->all();

        return response()->json([
            'ok' => true,
            'request' => $this->presentRequest($row),
            'seller' => $accountSet ? $this->presentAccountSet($accountSet) : null,
            'attachments' => $this->attachments->forRequest((int) $row->id),
            'delivery_logs' => DB::table('invoice_delivery_logs')->where('request_id', $row->id)->orderByDesc('id')->limit(20)->get(),
            'logs' => $logs,
        ]);
    }

    public function myTodos(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_review', null, $request)) {
            return $deny;
        }
        $ids = $this->guard->accessibleAccountSets($account);
        $sets = DB::table('invoice_account_sets')->whereIn('id', $ids ?: [0])->orderBy('name')->get();
        $groups = [];
        foreach ($sets as $set) {
            $rows = DB::table('invoice_requests')
                ->where('account_set_id', $set->id)
                ->whereIn('status', ['submitted', 'issuing', 'failed', 'red_flushing'])
                ->orderBy('created_at')
                ->limit(50)
                ->get();
            $primary = DB::table('invoice_account_set_reviewers as r')
                ->leftJoin('payroll_accounts as a', 'a.id', '=', 'r.account_id')
                ->where('r.account_set_id', $set->id)
                ->where('r.is_primary', 1)
                ->value('a.name');
            $groups[] = [
                'account_set_id' => (int) $set->id,
                'account_set_name' => $set->name,
                'account_set_code' => $set->code,
                'seller_name' => $set->seller_name,
                'primary_reviewer' => $primary,
                'pending_count' => (int) DB::table('invoice_requests')->where('account_set_id', $set->id)->where('status', 'submitted')->count(),
                'items' => $rows->map(fn ($row) => $this->presentRequest($row))->all(),
            ];
        }
        return response()->json(['ok' => true, 'groups' => $groups, 'account_set_ids' => $ids]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_review', (int) $row->account_set_id, $request)) {
            return $deny;
        }
        $v = Validator::make($request->all(), [
            'verified_amount' => ['required', 'numeric', 'min:0.01'],
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'errors' => $v->errors()], 422);
        }
        $verified = round((float) $request->input('verified_amount'), 2);
        if (abs($verified - round((float) $row->declared_amount, 2)) > 0.009 && trim((string) $request->input('review_note', '')) === '') {
            return response()->json(['ok' => false, 'error' => '改额必须填写差异原因', 'code' => 'DIFF_REASON_REQUIRED'], 422);
        }
        try {
            $updated = $this->service->approve($row, $verified, $request->input('review_note'), $account, $request);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'code' => 'STATUS_INVALID'], 422);
        }
        return response()->json(['ok' => true, 'request' => $this->presentRequest($updated)]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_review', (int) $row->account_set_id, $request)) {
            return $deny;
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return response()->json(['ok' => false, 'error' => '退回原因必填'], 422);
        }
        try {
            $updated = $this->service->reject($row, $reason, $account, $request);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'code' => 'STATUS_INVALID'], 422);
        }
        return response()->json(['ok' => true, 'request' => $this->presentRequest($updated)]);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'invoice_issue', fn ($row, $account) => $this->service->issue($row, $account, $request));
    }

    public function redFlush(Request $request, int $id): JsonResponse
    {
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return response()->json(['ok' => false, 'error' => '红冲原因必填'], 422);
        }
        return $this->mutate($request, $id, 'invoice_issue', fn ($row, $account) => $this->service->redFlush($row, $reason, $account, $request));
    }

    public function reissue(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'invoice_issue', fn ($row, $account) => $this->service->reissue($row, $account, $request));
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_issue', (int) $row->account_set_id, $request)) {
            return $deny;
        }
        if ($row->status === 'delivered_failed' || $row->status === 'delivering') {
            $updated = $this->service->retryDelivery($row);
        } elseif ($row->status === 'delivered' || $row->status === 'issued') {
            try {
                $updated = $this->service->resend($row, $account, $request);
            } catch (\DomainException $e) {
                return response()->json(['ok' => false, 'error' => $e->getMessage(), 'code' => 'STATUS_INVALID'], 422);
            }
        } else {
            return response()->json(['ok' => false, 'error' => '当前状态不可重发'], 422);
        }
        return response()->json(['ok' => true, 'request' => $this->presentRequest($updated)]);
    }

    public function invoiceFile(Request $request, int $id): mixed
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_view', (int) $row->account_set_id, $request)) {
            return $deny;
        }
        return $this->publicServe($row, (string) $request->query('f', 'pdf'));
    }

    public function stats(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_stats', null, $request)) {
            return $deny;
        }
        $ids = $this->guard->accessibleAccountSets($account);
        if ($request->filled('account_set_id')) {
            $accountSetId = (int) $request->query('account_set_id');
            if ($deny = $this->guard->authorize($account, 'invoice_stats', $accountSetId, $request)) {
                return $deny;
            }
            $ids = [$accountSetId];
        }
        $base = DB::table('invoice_requests')->whereIn('account_set_id', $ids ?: [0]);
        if ($request->filled('from')) {
            $base->where('created_at', '>=', $request->query('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $base->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $summary = (clone $base)->selectRaw("COUNT(*) as total, COALESCE(SUM(verified_amount),0) as amount, COALESCE(SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END),0) as failed, COALESCE(SUM(CASE WHEN status IN ('red_flushed','red_flushing') THEN 1 ELSE 0 END),0) as red")->first();
        $statusRows = (clone $base)->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->get();
        $byAccount = (clone $base)
            ->select('account_set_id', 'account_set_code', 'seller_name_snapshot', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(verified_amount),0) as amount'))
            ->groupBy('account_set_id', 'account_set_code', 'seller_name_snapshot')
            ->get();
        $byProject = (clone $base)
            ->select('project_id', 'project_name', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(verified_amount),0) as amount'))
            ->groupBy('project_id', 'project_name')
            ->get();
        $monthly = (clone $base)
            ->selectRaw('substr(created_at,1,7) as month, COUNT(*) as count, COALESCE(SUM(verified_amount),0) as amount')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'ok' => true,
            'summary' => [
                'total' => (int) $summary->total,
                'amount' => round((float) $summary->amount, 2),
                'pending' => (int) $summary->pending,
                'failed' => (int) $summary->failed,
                'red' => (int) $summary->red,
            ],
            'status' => $statusRows->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->c])->all(),
            'by_account_set' => $byAccount->map(fn ($r) => [
                'account_set_id' => (int) $r->account_set_id,
                'account_set_code' => $r->account_set_code,
                'seller_name' => $r->seller_name_snapshot,
                'count' => (int) $r->count,
                'amount' => round((float) $r->amount, 2),
            ])->all(),
            'by_project' => $byProject->map(fn ($r) => [
                'project_id' => (int) $r->project_id,
                'project_name' => $r->project_name,
                'count' => (int) $r->count,
                'amount' => round((float) $r->amount, 2),
            ])->all(),
            'monthly' => $monthly->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->count, 'amount' => round((float) $r->amount, 2)])->all(),
        ]);
    }

    public function export(Request $request): mixed
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_view', null, $request)) {
            return $deny;
        }
        $ids = $this->guard->accessibleAccountSets($account);
        $query = DB::table('invoice_requests')->whereIn('account_set_id', $ids ?: [0]);
        if ($request->filled('account_set_id')) {
            $id = (int) $request->query('account_set_id');
            if ($deny = $this->guard->authorize($account, 'invoice_view', $id, $request)) {
                return $deny;
            }
            $query->where('account_set_id', $id);
        }
        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }
        $rows = $query->orderByDesc('id')->limit(10000)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = ['申请单号', '账套', '开票主体', '项目', '房号', '业主', '费项期间', '申报金额', '核定金额', '状态', '抬头类型', '抬头', '税号', '邮箱', '发票号码', '开票日期', '模拟', '提交时间'];
        $sheet->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row->request_no, $row->account_set_code, $row->seller_name_snapshot, $row->project_name,
                $row->house_no, $row->payer_name, $row->fee_period, (float) $row->declared_amount,
                $row->verified_amount !== null ? (float) $row->verified_amount : null,
                $row->status, $row->title_type, $row->title, $row->buyer_tax_no,
                $row->buyer_email, $row->digital_invoice_no, $row->invoice_date,
                $row->simulated ? '是' : '否', $row->created_at,
            ], null, 'A' . $line++);
        }
        foreach (range('A', 'R') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'invoice-requests-' . now()->format('YmdHis') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ---------------- 账套与审核人配置 ----------------

    public function accountSets(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', null, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $ids = $this->guard->accessibleAccountSets($account);
        $rows = DB::table('invoice_account_sets')->whereIn('id', $ids ?: [0])->orderBy('name')->get();
        return response()->json([
            'ok' => true,
            'items' => $rows->map(fn ($r) => $this->presentAccountSet($r))->all(),
            'bindings' => $this->bindingsPayload($ids),
            'scope' => $this->guard->scope($account),
        ]);
    }

    public function saveAccountSet(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = $id ? DB::table('invoice_account_sets')->where('id', $id)->first() : null;
        if ($id && !$row) {
            return response()->json(['ok' => false, 'error' => '账套不存在'], 404);
        }
        if ($id && ($deny = $this->guard->authorize($account, 'invoice_config', $id, $request))) {
            return $deny;
        }
        if (!$id && ($deny = $this->guard->authorize($account, 'invoice_config', null, $request))) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }

        $v = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'seller_name' => ['required', 'string', 'max:160'],
            'seller_tax_no' => ['required', 'string', 'max:32'],
            'seller_address' => ['nullable', 'string', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:32'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:64'],
            'default_goods_code' => ['nullable', 'string', 'max:64'],
            'default_goods_name' => ['nullable', 'string', 'max:160'],
            'default_tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'amount_limit' => ['required', 'numeric', 'min:0.01'],
            'email_from' => ['nullable', 'email', 'max:120'],
            'email_from_name' => ['nullable', 'string', 'max:120'],
            'email_reply_to' => ['nullable', 'email', 'max:120'],
            'email_provider' => ['required', 'in:smtp,nuonuo'],
            'smtp_host' => ['nullable', 'string', 'max:120'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:120'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,starttls'],
            'status' => ['required', 'in:enabled,disabled'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $duplicateCode = DB::table('invoice_account_sets')->where('code', $data['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($duplicateCode) {
            return response()->json(['ok' => false, 'error' => '账套编码已存在'], 422);
        }
        $duplicateTax = DB::table('invoice_account_sets')->where('seller_tax_no', $data['seller_tax_no'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($duplicateTax) {
            return response()->json(['ok' => false, 'error' => '该税号已存在账套'], 422);
        }

        $payload = [
            'code' => $data['code'],
            'name' => $data['name'],
            'seller_name' => $data['seller_name'],
            'seller_tax_no' => $data['seller_tax_no'],
            'seller_address' => $data['seller_address'] ?? null,
            'seller_phone' => $data['seller_phone'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'default_goods_code' => $data['default_goods_code'] ?? null,
            'default_goods_name' => $data['default_goods_name'] ?? null,
            'default_tax_rate' => $data['default_tax_rate'],
            'amount_limit' => $data['amount_limit'],
            'email_from' => $data['email_from'] ?? null,
            'email_from_name' => $data['email_from_name'] ?? null,
            'email_reply_to' => $data['email_reply_to'] ?? null,
            'email_provider' => $data['email_provider'],
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => $data['smtp_port'] ?? null,
            'smtp_username' => $data['smtp_username'] ?? null,
            'smtp_encryption' => $data['smtp_encryption'] ?? null,
            'status' => $data['status'],
            'updated_at' => now(),
        ];
        if (!empty($data['smtp_password'])) {
            $payload['smtp_password_encrypted'] = Crypt::encryptString($data['smtp_password']);
        }

        if ($id) {
            DB::table('invoice_account_sets')->where('id', $id)->update($payload);
        } else {
            $payload['created_at'] = now();
            $id = DB::table('invoice_account_sets')->insertGetId($payload);
        }
        $this->guard->audit(null, $id, $row ? 'account_set_update' : 'account_set_create', ['code' => $data['code']], 'staff', $account, null, null, $request);

        return response()->json(['ok' => true, 'item' => $this->presentAccountSet(DB::table('invoice_account_sets')->where('id', $id)->first())]);
    }

    public function saveCredentials(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', $id, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $row = DB::table('invoice_account_sets')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '账套不存在'], 404);
        }
        $v = Validator::make($request->all(), [
            'app_key' => ['nullable', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'org_id' => ['nullable', 'string', 'max:64'],
            'env' => ['required', 'in:sandbox,prod'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'errors' => $v->errors()], 422);
        }
        $payload = [
            'nuonuo_app_key' => $request->input('app_key') ?: null,
            'nuonuo_org_id' => $request->input('org_id') ?: null,
            'nuonuo_env' => $request->input('env', 'sandbox'),
            'updated_at' => now(),
        ];
        if ((string) $request->input('app_secret', '') !== '') {
            $payload['nuonuo_app_secret_prev_encrypted'] = $row->nuonuo_app_secret_encrypted;
            $payload['nuonuo_app_secret_encrypted'] = Crypt::encryptString((string) $request->input('app_secret'));
            $payload['nuonuo_secret_prev_expires_at'] = now()->addHours(24);
            $payload['nuonuo_secret_rotated_at'] = now();
        }
        DB::table('invoice_account_sets')->where('id', $id)->update($payload);
        $this->guard->audit(null, $id, 'credentials_update', ['rotated' => (string) $request->input('app_secret', '') !== ''], 'staff', $account, null, null, $request);
        return response()->json(['ok' => true, 'item' => $this->presentAccountSet(DB::table('invoice_account_sets')->where('id', $id)->first())]);
    }

    public function rollbackCredentials(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', $id, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $row = DB::table('invoice_account_sets')->where('id', $id)->first();
        if (!$row || !$row->nuonuo_app_secret_prev_encrypted || !$row->nuonuo_secret_prev_expires_at || now()->gt($row->nuonuo_secret_prev_expires_at)) {
            return response()->json(['ok' => false, 'error' => '没有可回滚的旧凭据或窗口已过期'], 422);
        }
        DB::table('invoice_account_sets')->where('id', $id)->update([
            'nuonuo_app_secret_encrypted' => $row->nuonuo_app_secret_prev_encrypted,
            'nuonuo_app_secret_prev_encrypted' => null,
            'nuonuo_secret_prev_expires_at' => null,
            'nuonuo_secret_rotated_at' => now(),
            'updated_at' => now(),
        ]);
        $this->guard->audit(null, $id, 'credentials_rollback', [], 'staff', $account, null, null, $request);
        return response()->json(['ok' => true]);
    }

    public function testConnection(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', $id, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $row = DB::table('invoice_account_sets')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '账套不存在'], 404);
        }
        if (empty($row->nuonuo_app_key) || empty($row->nuonuo_app_secret_encrypted)) {
            return response()->json([
                'ok' => true,
                'simulated' => true,
                'message' => '未配置诺诺凭据，当前自动使用本地模拟开票（不会生成真实发票）',
            ]);
        }
        try {
            $secret = Crypt::decryptString($row->nuonuo_app_secret_encrypted);
            $resp = Http::timeout((int) config('invoice.nuonuo.timeout', 15))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('invoice.nuonuo.base_url'), '/') . config('invoice.nuonuo.token_path'), [
                    'appKey' => $row->nuonuo_app_key,
                    'appSecret' => $secret,
                    'orgId' => $row->nuonuo_org_id,
                ]);
            $json = $resp->json() ?: [];
            if (!$resp->successful()) {
                return response()->json(['ok' => false, 'simulated' => false, 'error' => '诺诺鉴权失败：' . ($json['msg'] ?? $resp->status())]);
            }
            return response()->json(['ok' => true, 'simulated' => false, 'message' => '诺诺鉴权通过，销方授权标识：' . ($row->nuonuo_org_id ?: '未填写')]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'simulated' => false, 'error' => $e->getMessage()]);
        }
    }

    public function reviewers(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', $id, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        if ($request->isMethod('get')) {
            return response()->json(['ok' => true, 'items' => $this->reviewerPayload($id)]);
        }

        $v = Validator::make($request->all(), [
            'reviewers' => ['present', 'array'],
            'reviewers.*.account_id' => ['required', 'integer'],
            'reviewers.*.is_primary' => ['nullable', 'boolean'],
            'reviewers.*.can_review' => ['nullable', 'boolean'],
            'reviewers.*.can_issue' => ['nullable', 'boolean'],
            'reviewers.*.can_stats' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'errors' => $v->errors()], 422);
        }
        $list = (array) $request->input('reviewers', []);
        $primary = array_values(array_filter($list, fn ($r) => !empty($r['is_primary'])));
        if (count($primary) > 1) {
            return response()->json(['ok' => false, 'error' => '每账套最多 1 名主审人'], 422);
        }
        if (!$primary && count($list) > 0) {
            // 有审核人时必须保留 1 名主审：未指定时默认第一人
            $list[0]['is_primary'] = true;
            $primary = [$list[0]];
        }
        foreach ($list as $r) {
            if (!DB::table('payroll_accounts')->where('id', (int) $r['account_id'])->exists()) {
                return response()->json(['ok' => false, 'error' => '审核人账号不存在：' . $r['account_id']], 422);
            }
        }

        DB::transaction(function () use ($list, $id, $account) {
            DB::table('invoice_account_set_reviewers')->where('account_set_id', $id)->delete();
            foreach ($list as $r) {
                DB::table('invoice_account_set_reviewers')->insert([
                    'account_set_id' => $id,
                    'account_id' => (int) $r['account_id'],
                    'is_primary' => !empty($r['is_primary']) ? 1 : 0,
                    'can_view' => 1,
                    'can_review' => !empty($r['can_review']) ? 1 : 0,
                    'can_issue' => !empty($r['can_issue']) ? 1 : 0,
                    'can_stats' => !empty($r['can_stats']) ? 1 : 0,
                    'assigned_by' => $account->id,
                    'assigned_at' => now(),
                    'enabled' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        $this->guard->audit(null, $id, 'reviewers_update', ['count' => count($list), 'primary' => $primary[0]['account_id'] ?? null], 'staff', $account, null, null, $request);
        return response()->json(['ok' => true, 'items' => $this->reviewerPayload($id)]);
    }

    public function users(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', null, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $rows = DB::table('payroll_accounts')->select('id', 'username', 'name', 'role', 'project_name')->orderBy('name')->get();
        return response()->json(['ok' => true, 'items' => $rows]);
    }

    public function saveBinding(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', null, $request)) {
            return $deny;
        }
        if ($deny = $this->requireConfigScope($account, $request)) {
            return $deny;
        }
        $v = Validator::make($request->all(), [
            'project_id' => ['required', 'integer'],
            'account_set_id' => ['required', 'integer'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'errors' => $v->errors()], 422);
        }
        $project = DB::table('payroll_projects')->where('id', (int) $request->input('project_id'))->first();
        $set = DB::table('invoice_account_sets')->where('id', (int) $request->input('account_set_id'))->first();
        if (!$project || !$set) {
            return response()->json(['ok' => false, 'error' => '项目或账套不存在'], 422);
        }
        if ($deny = $this->guard->authorize($account, 'invoice_config', (int) $set->id, $request)) {
            return $deny;
        }
        $existing = DB::table('invoice_project_bindings')->where('project_id', $project->id)->first();
        if ($existing) {
            DB::table('invoice_project_bindings')->where('id', $existing->id)->update([
                'account_set_id' => $set->id,
                'project_name' => $project->name,
                'enabled' => $request->boolean('enabled', true) ? 1 : 0,
                'updated_at' => now(),
            ]);
            $token = $existing->entry_token;
        } else {
            $token = $this->numbers->entryToken();
            DB::table('invoice_project_bindings')->insert([
                'project_id' => $project->id,
                'project_name' => $project->name,
                'account_set_id' => $set->id,
                'entry_token' => $token,
                'enabled' => $request->boolean('enabled', true) ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->guard->audit(null, (int) $set->id, 'project_binding_save', ['project_id' => $project->id], 'staff', $account, null, null, $request);
        return response()->json(['ok' => true, 'entry_token' => $token, 'entry_url' => $this->entryUrl($token)]);
    }

    private function requireConfigScope(object $account, Request $request): ?JsonResponse
    {
        if ($this->guard->scope($account) === 'all') {
            return null;
        }
        $this->guard->logDenied(null, 'config_scope_denied', ['path' => $request->path()], $request, $account);
        return response()->json(['ok' => false, 'error' => '账套配置仅开票管理员可操作', 'code' => 'CONFIG_SCOPE_FORBIDDEN'], 403);
    }

    private function mutate(Request $request, int $id, string $perm, callable $fn): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $row = DB::table('invoice_requests')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '申请单不存在'], 404);
        }
        if ($deny = $this->guard->authorize($account, $perm, (int) $row->account_set_id, $request)) {
            return $deny;
        }
        try {
            $updated = $fn($row, $account);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'code' => 'STATUS_INVALID'], 422);
        }
        return response()->json(['ok' => true, 'request' => $this->presentRequest($updated)]);
    }

    private function presentRequest(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'request_no' => $row->request_no,
            'account_set_id' => (int) $row->account_set_id,
            'account_set_code' => $row->account_set_code,
            'seller_name' => $row->seller_name_snapshot,
            'seller_tax_no' => $row->seller_tax_no_snapshot,
            'project_id' => (int) $row->project_id,
            'project_name' => $row->project_name,
            'house_no' => $row->house_no,
            'payer_name' => $row->payer_name,
            'fee_period' => $row->fee_period,
            'declared_amount' => (float) $row->declared_amount,
            'verified_amount' => $row->verified_amount !== null ? (float) $row->verified_amount : null,
            'tax_amount' => $row->tax_amount !== null ? (float) $row->tax_amount : null,
            'title_type' => $row->title_type,
            'title' => $row->title,
            'buyer_tax_no' => $row->buyer_tax_no,
            'buyer_email' => $row->buyer_email,
            'status' => $row->status,
            'version' => (int) $row->version,
            'reject_reason' => $row->reject_reason,
            'review_note' => $row->review_note,
            'amount_flag' => $row->amount_flag,
            'attachment_count' => (int) $row->attachment_count,
            'issuer' => $row->issuer,
            'simulated' => (bool) $row->simulated,
            'digital_invoice_no' => $row->digital_invoice_no,
            'invoice_date' => $row->invoice_date,
            'has_pdf' => (bool) $row->pdf_path,
            'has_ofd' => (bool) $row->ofd_path,
            'fail_code' => $row->fail_code,
            'fail_msg' => $row->fail_msg,
            'deliver_attempts' => (int) $row->deliver_attempts,
            'next_delivery_at' => $row->next_delivery_at,
            'created_at' => $row->created_at,
            'reviewed_at' => $row->reviewed_at,
            'issued_at' => $row->issued_at,
            'delivered_at' => $row->delivered_at,
        ];
    }

    private function presentAccountSet(object $row): array
    {
        $reviewers = $this->reviewerPayload((int) $row->id);
        return [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'seller_name' => $row->seller_name,
            'seller_tax_no' => $row->seller_tax_no,
            'seller_address' => $row->seller_address,
            'seller_phone' => $row->seller_phone,
            'bank_name' => $row->bank_name,
            'bank_account' => $row->bank_account,
            'invoice_kind' => $row->invoice_kind,
            'default_goods_code' => $row->default_goods_code,
            'default_goods_name' => $row->default_goods_name,
            'default_tax_rate' => (float) $row->default_tax_rate,
            'amount_limit' => (float) $row->amount_limit,
            'email_from' => $row->email_from,
            'email_from_name' => $row->email_from_name,
            'email_reply_to' => $row->email_reply_to,
            'email_provider' => $row->email_provider,
            'smtp_host' => $row->smtp_host,
            'smtp_port' => $row->smtp_port,
            'smtp_username' => $row->smtp_username,
            'smtp_encryption' => $row->smtp_encryption,
            'has_smtp_password' => !empty($row->smtp_password_encrypted),
            'nuonuo_app_key' => $row->nuonuo_app_key,
            'nuonuo_app_key_masked' => $row->nuonuo_app_key ? mb_substr($row->nuonuo_app_key, 0, 6) . '****' : null,
            'has_nuonuo_secret' => !empty($row->nuonuo_app_secret_encrypted),
            'nuonuo_org_id' => $row->nuonuo_org_id,
            'nuonuo_env' => $row->nuonuo_env,
            'status' => $row->status,
            'remark' => $row->remark,
            'reviewers' => $reviewers,
        ];
    }

    private function reviewerPayload(int $accountSetId): array
    {
        return DB::table('invoice_account_set_reviewers as r')
            ->leftJoin('payroll_accounts as a', 'a.id', '=', 'r.account_id')
            ->where('r.account_set_id', $accountSetId)
            ->orderByDesc('r.is_primary')
            ->get([
                'r.account_id', 'r.is_primary', 'r.can_review', 'r.can_issue', 'r.can_stats',
                'a.name', 'a.username', 'a.project_name',
            ])
            ->map(fn ($r) => [
                'account_id' => (int) $r->account_id,
                'name' => $r->name ?: $r->username,
                'username' => $r->username,
                'project_name' => $r->project_name,
                'is_primary' => (bool) $r->is_primary,
                'can_review' => (bool) $r->can_review,
                'can_issue' => (bool) $r->can_issue,
                'can_stats' => (bool) $r->can_stats,
            ])->all();
    }

    private function bindingsPayload(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::table('invoice_project_bindings as b')
            ->leftJoin('invoice_account_sets as s', 's.id', '=', 'b.account_set_id')
            ->whereIn('b.account_set_id', $ids)
            ->orderBy('b.project_name')
            ->get(['b.project_id', 'b.project_name', 'b.account_set_id', 'b.entry_token', 'b.enabled', 's.name as account_set_name'])
            ->map(fn ($b) => [
                'project_id' => (int) $b->project_id,
                'project_name' => $b->project_name,
                'account_set_id' => (int) $b->account_set_id,
                'account_set_name' => $b->account_set_name,
                'entry_url' => $this->entryUrl($b->entry_token),
                'enabled' => (bool) $b->enabled,
            ])->all();
    }

    private function entryUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/invoice.html?p=' . $token;
    }

    private function publicServe(object $row, string $format = 'pdf'): mixed
    {
        $format = strtolower($format) === 'ofd' ? 'ofd' : 'pdf';
        $path = $format === 'ofd' ? $row->ofd_path : $row->pdf_path;
        if (!$path) {
            return response()->json(['ok' => false, 'error' => $format === 'ofd' ? 'OFD 文件尚未生成' : '发票文件尚未生成'], 404);
        }
        $disk = Storage::disk((string) config('invoice.storage.disk', 'local'));
        if (!$disk->exists($path)) {
            return response()->json(['ok' => false, 'error' => '发票文件缺失'], 404);
        }
        $mime = $format === 'ofd' ? 'application/ofd' : 'application/pdf';
        return response()->download($disk->path($path), 'invoice-' . $row->request_no . '.' . $format, ['Content-Type' => $mime]);
    }
}
