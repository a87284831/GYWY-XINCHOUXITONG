<?php
/** 认证接口 */
function handle_auth_login() {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($in['username'] ?? '');
    $password = (string)($in['password'] ?? '');
    if ($username === '' || $password === '') {
        return ['ok' => false, 'msg' => '请输入账号和密码'];
    }
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        try {
            db()->prepare("INSERT INTO op_logs (user_id, username, role, action, detail) VALUES (0, ?, '-', '登录失败', ?)")
                ->execute([$username, '账号或密码错误']);
        } catch (Throwable $e) {}
        return ['ok' => false, 'msg' => '账号或密码错误'];
    }
    if ((int)$user['status'] !== 1) {
        try {
            db()->prepare("INSERT INTO op_logs (user_id, username, role, action, detail) VALUES (?, ?, ?, '登录失败', ?)")
                ->execute([(int)$user['id'], $user['username'], $user['role'], '账号已停用']);
        } catch (Throwable $e) {}
        return ['ok' => false, 'msg' => '账号已停用，请联系管理员'];
    }
    $cfg = require __DIR__ . '/../../config.php';
    $token = jwt_encode(['uid' => (int)$user['id'], 'role' => $user['role']], $cfg['jwt_secret'], $cfg['jwt_expire']);
    log_action('登录', "登录成功（{$username}）");
    $project = null;
    if ($user['project_id']) {
        $pst = db()->prepare('SELECT id, name FROM payroll.payroll_projects WHERE id = ?');
        $pst->execute([$user['project_id']]);
        $project = $pst->fetch();
    }
    return [
        'ok' => true,
        'data' => [
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'role' => $user['role'],
                'project' => $project,
            ],
        ],
    ];
}

function handle_auth_me() {
    $user = require_auth();
    $project = null;
    if ($user['project_id']) {
        $pst = db()->prepare('SELECT id, name FROM payroll.payroll_projects WHERE id = ?');
        $pst->execute([$user['project_id']]);
        $project = $pst->fetch();
    }
    return ['ok' => true, 'data' => ['user' => [
        'id' => (int)$user['id'], 'username' => $user['username'], 'name' => $user['name'],
        'role' => $user['role'], 'project' => $project,
    ]]];
}

// 退出登录（记录日志）
function handle_auth_logout() {
    $user = null;
    try { $user = auth_user(); } catch (Throwable $e) {}
    if ($user) {
        log_action('退出登录', "退出登录（{$user['username']}）");
    }
    return ['ok' => true, 'msg' => '已退出'];
}
