<?php
/**
 * 操作日志公共函数
 */

function log_action(string $action, string $detail = ''): void {
    try {
        $user = auth_user();
        $uid = $user ? (int)$user['id'] : 0;
        $uname = $user ? ($user['username'] . '/' . $user['name']) : '系统';
        $role = $user ? $user['role'] : '-';
        db()->prepare("INSERT INTO op_logs (user_id, username, role, action, detail) VALUES (?,?,?,?,?)")
            ->execute([$uid, $uname, $role, $action, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
        // 日志失败不影响主流程
    }
}
