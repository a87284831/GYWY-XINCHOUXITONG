<?php

namespace App\Console\Commands;

use App\Invoice\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InvoiceRetryDeliveries extends Command
{
    protected $signature = 'invoice:retry-deliveries';

    protected $description = '重试开票邮件交付（1/5/15 分钟），超过次数写告警审计';

    public function handle(InvoiceService $service): int
    {
        $rows = DB::table('invoice_requests')
            ->whereIn('status', ['delivered_failed', 'delivering'])
            ->whereNotNull('next_delivery_at')
            ->where('next_delivery_at', '<=', now())
            ->orderBy('next_delivery_at')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            try {
                $service->retryDelivery($row);
            } catch (\Throwable $e) {
                $this->warn('delivery retry failed #' . $row->id . ': ' . $e->getMessage());
            }
        }

        $this->info('invoice delivery retry done: ' . $rows->count());
        return self::SUCCESS;
    }
}
