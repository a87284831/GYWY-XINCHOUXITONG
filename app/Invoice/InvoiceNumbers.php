<?php

namespace App\Invoice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceNumbers
{
    public function requestNo(): string
    {
        $prefix = 'INV' . now()->format('Ymd') . '-';
        for ($i = 0; $i < 5; $i++) {
            $max = (string) DB::table('invoice_requests')
                ->where('request_no', 'like', $prefix . '%')
                ->max('request_no');
            $seq = $max ? ((int) substr($max, -6)) + 1 : 1;
            $candidate = $prefix . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
            if (!DB::table('invoice_requests')->where('request_no', $candidate)->exists()) {
                return $candidate;
            }
        }
        // 极端并发兜底：随机后缀仍保持唯一
        return $prefix . strtoupper(Str::random(6));
    }

    public function queryKey(): string
    {
        return Str::random(40);
    }

    public function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public function idempotencyKey(int $projectId, string $houseNo, string $requestNo): string
    {
        return $projectId . '|' . trim($houseNo) . '|' . $requestNo;
    }

    public function entryToken(): string
    {
        return Str::random(48);
    }
}
