<?php
/** 财务管理 - 财务账号管理 handlers（仅总部财务可操作） */

use App\Finance\FinanceStop;
use App\Finance\Support;

function handle_fin_accounts_list(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可管理账号'], 403);

    $st = fdb()->prepare("SELECT id, username, name, role, project_name, enabled, updated_at FROM payroll.payroll_accounts WHERE role IN ('finance','finance_admin') ORDER BY id");
    $st->execute();
    return ['ok' => true, 'accounts' => $st->fetchAll()];
}

function handle_fin_accounts_save(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可管理账号'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    $username = trim((string) ($in['username'] ?? ''));
    $name = trim((string) ($in['name'] ?? ''));
    $role = (string) ($in['role'] ?? '');
    $projectName = trim((string) ($in['project_name'] ?? ''));
    $password = (string) ($in['password'] ?? '');

    if ($role !== 'finance' && $role !== 'finance_admin') throw new FinanceStop(['ok' => false, 'msg' => '角色不合法'], 400);
    if ($username === '' || $name === '') throw new FinanceStop(['ok' => false, 'msg' => '用户名和姓名必填'], 400);
    if (!preg_match('/^[a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]{2,30}$/u', $username)) {
        throw new FinanceStop(['ok' => false, 'msg' => '用户名仅支持2-30位中英文/数字/下划线'], 400);
    }
    if ($role === 'finance') {
        if ($projectName === '') throw new FinanceStop(['ok' => false, 'msg' => '项目财务必须绑定项目'], 400);
        if (fin_project_id_by_name($projectName) === null) throw new FinanceStop(['ok' => false, 'msg' => '项目不存在: ' . $projectName], 400);
    } else {
        $projectName = '';
    }
    $db = fdb();

    $exists = $db->prepare('SELECT id FROM payroll.payroll_accounts WHERE username = ? AND id <> ? LIMIT 1');
    $exists->execute([$username, $id]);
    if ($exists->fetchColumn()) throw new FinanceStop(['ok' => false, 'msg' => '用户名已存在'], 400);

    if ($id > 0) {
        $chk = $db->prepare('SELECT id FROM payroll.payroll_accounts WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) throw new FinanceStop(['ok' => false, 'msg' => '账号不存在'], 404);
        if ($password !== '') {
            $upd = $db->prepare("UPDATE payroll.payroll_accounts SET username=?, name=?, role=?, project_name=?, password_hash=? WHERE id=?");
            $upd->execute([$username, $name, $role, $projectName, hash('sha256', 'gwxy_' . $password), $id]);
        } else {
            $upd = $db->prepare("UPDATE payroll.payroll_accounts SET username=?, name=?, role=?, project_name=? WHERE id=?");
            $upd->execute([$username, $name, $role, $projectName, $id]);
        }
        return ['ok' => true, 'id' => $id];
    }

    $ins = $db->prepare("INSERT INTO payroll.payroll_accounts (legacy_id, username, name, role, project_name, password_hash, enabled) VALUES (?,?,?,?,?,?,1)");
    $legacy = time() . random_int(100, 999);
    $ins->execute([$legacy, $username, $name, $role, $projectName, hash('sha256', 'gwxy_' . $password)]);
    return ['ok' => true, 'id' => (int) $db->lastInsertId()];
}

function handle_fin_accounts_status(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可管理账号'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    $enabled = isset($in['enabled']) ? ((int) $in['enabled'] ? 1 : 0) : 1;
    if ($id <= 0) throw new FinanceStop(['ok' => false, 'msg' => '参数错误'], 400);
    fdb()->prepare('UPDATE payroll.payroll_accounts SET enabled = ? WHERE id = ? AND role IN (\'finance\',\'finance_admin\')')
        ->execute([$enabled, $id]);
    return ['ok' => true];
}

/** 财务账号删除（仅停用后可删，保护审计） */
function handle_fin_accounts_delete(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可管理账号'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) throw new FinanceStop(['ok' => false, 'msg' => '参数错误'], 400);
    $st = fdb()->prepare('SELECT id, enabled FROM payroll.payroll_accounts WHERE id = ? AND role IN (\'finance\',\'finance_admin\') LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new FinanceStop(['ok' => false, 'msg' => '账号不存在'], 404);
    if ((int) $row['enabled'] === 1) throw new FinanceStop(['ok' => false, 'msg' => '请先停用账号再删除'], 400);
    fdb()->prepare('DELETE FROM payroll.payroll_accounts WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}
