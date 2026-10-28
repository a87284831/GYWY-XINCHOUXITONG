<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * 维保合同状态分级（90/60/30 天三档预警 + 已过期 + 无到期日）。
 * 无到期日不再算正常在保，单列异常。
 */
class MaintenanceService
{
    public const STATUS_LABELS = [
        'normal' => '正常在保',
        'd90' => '90天内到期',
        'd60' => '60天内到期',
        'd30' => '30天内到期',
        'expired' => '已过期',
        'missing' => '无到期日',
    ];

    /** 合同状态分级 */
    public static function contractStatus(?string $endDate, ?Carbon $today = null): string
    {
        if (!$endDate) {
            return 'missing';
        }
        $today = $today ? $today->copy()->startOfDay() : now()->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($today)) {
            return 'expired';
        }
        $days = (int) $today->diffInDays($end);
        if ($days <= 30) {
            return 'd30';
        }
        if ($days <= 60) {
            return 'd60';
        }
        if ($days <= 90) {
            return 'd90';
        }
        return 'normal';
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /** 合同当前是否处于有效维保期（无到期日视为无效） */
    public static function isEffective(object $row, ?Carbon $today = null): bool
    {
        if (!$row->end_date) {
            return false;
        }
        $today = $today ? $today->copy()->startOfDay() : now()->startOfDay();
        $start = $row->start_date ? Carbon::parse($row->start_date)->startOfDay() : null;
        $end = Carbon::parse($row->end_date)->startOfDay();
        return (!$start || $start->lte($today)) && $end->gte($today);
    }
}
