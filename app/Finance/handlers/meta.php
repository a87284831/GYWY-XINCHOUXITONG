<?php
/** 财务管理 - 元数据 handlers：项目清单 / 类别枚举 / 权限信息 */

use App\Finance\FinanceStop;
use App\Finance\Support;

function handle_fin_meta(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);

    $isAdmin = fin_is_admin();
    $projects = fin_projects();
    $accessible = fin_accessible_projects();
    if (!$isAdmin) {
        // 项目财务：仅返回绑定项目，避免前端泄露全部项目清单
        $allowed = array_flip($accessible);
        $projects = array_values(array_filter($projects, function ($p) use ($allowed) {
            return isset($allowed[(int) $p['id']]);
        }));
    }

    return [
        'ok' => true,
        'projects' => $projects,
        'accessible_projects' => $accessible,
        'project_id' => $isAdmin ? 0 : (int) ($accessible[0] ?? 0),
        'categories' => fin_receivable_categories(),
        'payment_types' => fin_payment_types(),
        'is_admin' => $isAdmin,
        'role' => $u['role'],
        'project_name' => $u['project_name'],
    ];
}
