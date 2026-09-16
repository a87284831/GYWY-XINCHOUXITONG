<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 消息通知中心服务：供审批流、到期预警等各模块统一推送站内消息。
 * 每条消息对应一个接收账号（account_id）；按角色/项目定向时调用方先解析成账号列表。
 */
class MessageService
{
    /**
     * 向一个或多个账号推送一条站内消息。
     *
     * @param array|int $accountIds 接收账号 id 或 id 数组
     * @param string    $type       approval | remind | notice
     * @param string    $title
     * @param string    $content
     * @param string    $link       前端跳转路由 key 或 URL
     * @param string    $project    项目名（可选，用于按项目筛选）
     */
    public static function push(array|int $accountIds, string $type, string $title, string $content = '', string $link = '', string $project = ''): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $accountIds))));
        if (!$ids) {
            return;
        }
        $now = now()->toDateTimeString();
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = [
                'account_id' => $id,
                'type' => $type,
                'title' => mb_substr($title, 0, 190),
                'content' => mb_substr($content, 0, 480),
                'link' => mb_substr($link, 0, 190),
                'project_name' => mb_substr($project, 0, 110),
                'read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('app_messages')->insert($rows);
    }

    /**
     * 向某项目下拥有指定权限点的所有启用账号推送（用于"给项目/角色定向通知"）。
     *
     * @param array $permPoints 权限点列表，任一命中即投递
     */
    public static function pushToRole(array $permPoints, string $type, string $title, string $content = '', string $link = '', ?string $project = null): void
    {
        $perms = array_values(array_filter(array_map('strval', $permPoints)));
        if (!$perms) {
            return;
        }
        $query = DB::table('payroll_accounts')->where('enabled', true);
        if ($project !== null && $project !== '') {
            $query->where('project_name', $project);
        }
        $accounts = $query->get();
        $targets = [];
        foreach ($accounts as $acc) {
            // admin 恒为真；其余读取角色权限
            if ((string) $acc->role === 'admin') {
                $targets[] = (int) $acc->id;
                continue;
            }
            $role = DB::table('payroll_roles')->where('role_key', $acc->role)->first();
            $rp = $role ? (self::jsonValue($role->permissions) ?: []) : [];
            if (in_array('*', $rp, true)) {
                $targets[] = (int) $acc->id;
                continue;
            }
            if (count(array_intersect($perms, $rp)) > 0) {
                $targets[] = (int) $acc->id;
            }
        }
        self::push($targets, $type, $title, $content, $link, $project ?? '');
    }

    private static function jsonValue($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
}
