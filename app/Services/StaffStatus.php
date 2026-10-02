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

    /**
     * 逐日判定计薪分段是否处于试用期（与 salarySegments 同一口径）：
     *   1. 有实际转正日期：该日 < 转正日 为试用（精确到日，月中转正切段）
     *   2. 无实际转正日期且档案状态为「试用」：试用
     *   3. 无实际转正日期但已离职（离职状态会吞掉在职时的试用标记）：
     *      用钉钉花名册「计划转正日期」兜底——该字段员工离职后仍保留；
     *      该日 < 计划转正日 说明离职时尚未转正；计划日已过视为到期转正
     *   4. 其余（含离职且无任何转正日期）：正式——无试用期证据不臆断
     */
    public static function isProbationOnDate(
        ?string $regularDate,
        ?string $plannedRegularDate,
        string $status,
        string $date
    ): bool {
        $regular = self::norm($regularDate);
        if ($regular !== null) return $date < $regular;
        if (trim($status) === '试用') return true;
        if (trim($status) === '离职') {
            $planned = self::norm($plannedRegularDate);
            if ($planned !== null) return $date < $planned;
        }
        return false;
    }

    private static function norm(?string $d): ?string
    {
        $d = trim((string)($d ?? ''));
        if ($d === '' || $d === '0000-00-00') return null;
        return substr($d, 0, 10);
    }
}
