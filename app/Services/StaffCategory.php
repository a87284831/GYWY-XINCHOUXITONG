<?php

namespace App\Services;

/**
 * 人员档案分类（在职/离职/黑名单）——单一可信来源，与旧版口径一致：
 *   显式黑名单标记（data.blacklist 或 data.category=黑名单）→ 黑名单
 *   已删除（deleted）或离职日期<=基准日 → 离职
 *   否则 → 在职
 */
class StaffCategory
{
    public static function derive(array $row, string $refDate): string
    {
        $data = $row['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) $data = $decoded;
        }
        if (is_array($data)) {
            $blacklist = $data['blacklist'] ?? null;
            if ($blacklist === true || $blacklist === 1 || $blacklist === '1' || ($data['category'] ?? '') === '黑名单') {
                return '黑名单';
            }
        }
        if (!empty($row['deleted'])) return '离职';
        // status 列为离职（来自钉钉离职名单，权威），即使未取到离职日期也应归为离职
        if (trim((string)($row['status'] ?? '')) === '离职') return '离职';
        $resign = trim((string)($row['resign_date'] ?? ''));
        if ($resign !== '' && $resign !== '0000-00-00' && substr($resign, 0, 10) <= substr($refDate, 0, 10)) {
            return '离职';
        }
        return '在职';
    }
}
