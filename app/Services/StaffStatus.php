<?php

namespace App\Services;

/**
 * 人员状态派生：与前端表单"人员状态(自动判定)"同一套规则，作为单一可信来源。
 *   有离职日期且 离职日 <= 基准日            -> 离职
 *   未到转正日期（转正日 > 基准日）           -> 试用
 *   已到转正日期 / 无转正日                    -> 正式
 * 日期按 ISO(Y-m-d) 字符串比较即可。
 */
class StaffStatus
{
    public static function derive(?string $resignDate, ?string $regularDate, string $refDate): string
    {
        $resign = self::norm($resignDate);
        if ($resign !== null && $resign <= $refDate) return '离职';
        $regular = self::norm($regularDate);
        if ($regular !== null && $refDate < $regular) return '试用';
        return '正式';
    }

    /** 该核算月的月末，作为结果表状态判定的基准日 */
    public static function monthEnd(string $ym): string
    {
        return date('Y-m-t', strtotime($ym . '-01'));
    }

    private static function norm(?string $d): ?string
    {
        $d = trim((string)($d ?? ''));
        if ($d === '' || $d === '0000-00-00') return null;
        return substr($d, 0, 10);
    }
}
