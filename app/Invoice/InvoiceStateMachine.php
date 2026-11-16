<?php

namespace App\Invoice;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 12 态开票状态机：所有状态变更必须经过 assert + transition，禁止直接改 status。
 */
class InvoiceStateMachine
{
    public const STATUSES = [
        'draft', 'submitted', 'rejected', 'approved', 'issuing', 'issued',
        'delivering', 'delivered', 'delivered_failed', 'failed',
        'red_flushing', 'red_flushed', 'red_flush_failed', 'cancelled',
    ];

    public const TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['approved', 'rejected', 'cancelled'],
        'rejected' => ['submitted', 'cancelled'],
        'approved' => ['issuing'],
        'issuing' => ['issued', 'failed'],
        'issued' => ['delivering', 'red_flushing'],
        'delivering' => ['delivered', 'delivered_failed'],
        'delivered' => ['red_flushing'],
        'delivered_failed' => ['delivering'],
        'failed' => ['issuing'],
        'red_flushing' => ['red_flushed', 'red_flush_failed'],
        'red_flush_failed' => ['red_flushing'],
        'red_flushed' => [],
        'cancelled' => [],
    ];

    public function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (!in_array($from, self::STATUSES, true)) {
            throw new \DomainException('未知的开票状态：' . $from);
        }
        if (!$this->can($from, $to)) {
            throw new \DomainException('不允许的状态流转：' . $from . ' → ' . $to);
        }
    }

    /**
     * 执行状态流转并写审计日志；extra 为同表更新字段。
     */
    public function transition(
        object $row,
        string $to,
        string $operatorType,
        ?object $actor,
        array $extra = [],
        array $payload = [],
        ?Request $request = null,
        ?InvoiceGuard $guard = null
    ): object {
        $from = (string) $row->status;
        $this->assert($from, $to);

        $update = array_merge($extra, ['status' => $to, 'updated_at' => now()]);
        if ($to === 'issued' && empty($row->issued_at)) {
            $update['issued_at'] = now();
        }
        if ($to === 'delivered' && empty($row->delivered_at)) {
            $update['delivered_at'] = now();
        }
        if ($to === 'submitted') {
            $update['reject_reason'] = null;
        }

        DB::table('invoice_requests')->where('id', $row->id)->update($update);

        ($guard ?? new InvoiceGuard())->audit(
            (int) $row->id,
            (int) $row->account_set_id,
            'status_transition',
            $payload,
            $operatorType,
            $actor,
            $from,
            $to,
            $request
        );

        return (object) array_merge((array) $row, $update, ['status' => $to]);
    }
}
