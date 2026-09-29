<?php
/** 系统管理：项目 / 账号 / 填报窗口 */

// ---------- 项目 ----------
// 代填/筛选用项目下拉：返回平台项目（id=平台 payroll_projects.id，与填报数据统一）
function handle_projects_options() {
    require_admin();
    return ['ok' => true, 'data' => projects_all()];
}

function handle_projects_list() {
    require_admin();
    // 权威源：主系统人力组织架构（启用项目节点）——完全同步，采购不再维护独立项目
    $rows = DB::table('org_nodes')->where('type', 'project')->where('enabled', true)
        ->orderBy('sort_order')->get();
    $out = [];
    foreach ($rows as $o) {
        // 业务项目 id 保持平台 payroll_projects.id（填报/报价/预算/归档均按此口径）
        $pp = DB::table('payroll_projects')->where('name', $o->name)->where('status', '启用')->first();
        $out[] = [
            'id' => $pp ? (int) $pp->id : (int) $o->id,
            'code' => $o->code ?: ($pp ? 'P' . (int) $pp->id : ''),
            'name' => $o->name,
            'manager' => $o->contact ?: '',
            'phone' => $o->phone ?: '',
            'sort_no' => (int) $o->sort_order,
            'status' => 1,
        ];
    }
    return ['ok' => true, 'data' => $out];
}

function handle_projects_create() {
    require_admin();
    return ['ok' => false, 'msg' => '项目由主系统人力组织架构统一管理，请到主系统组织架构中新增项目'];
}

function handle_projects_update($id) {
    require_admin();
    return ['ok' => false, 'msg' => '项目由主系统人力组织架构统一管理，请到主系统组织架构中调整项目'];
}

function handle_projects_delete($id) {
    require_admin();
    return ['ok' => false, 'msg' => '项目由主系统人力组织架构统一管理，请到主系统组织架构中停用/删除项目'];
}

// ---------- 填报窗口 ----------
function handle_windows_list() {
    require_admin();
    $st = db()->query("SELECT id, month, start_date, end_date, status FROM fill_windows ORDER BY month DESC LIMIT 24");
    return ['ok' => true, 'data' => $st->fetchAll()];
}

function handle_windows_upsert() {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $month = trim($in['month'] ?? '');
    $start = trim($in['start_date'] ?? '');
    $end = trim($in['end_date'] ?? '');
    $status = (int)($in['status'] ?? 1);
    if ($month === '' || $start === '' || $end === '') return ['ok' => false, 'msg' => '月份和起止日期必填'];
    if ($start > $end) return ['ok' => false, 'msg' => '截止日期不能早于开始日期'];
    $st = db()->prepare("SELECT id FROM fill_windows WHERE month=? LIMIT 1");
    $st->execute([$month]);
    $exist = $st->fetchColumn();
    $isNew = !$exist;
    if ($exist) {
        db()->prepare("UPDATE fill_windows SET start_date=?, end_date=?, status=? WHERE month=?")
            ->execute([$start, $end, $status, $month]);
    } else {
        db()->prepare("INSERT INTO fill_windows (month, start_date, end_date, status) VALUES (?,?,?,?)")
            ->execute([$month, $start, $end, $status]);
    }
    log_action('窗口设置', ($isNew ? '新增' : '更新') . "填报窗口 {$month}（{$start}~{$end}）状态" . ($status === 1 ? '开放' : '停用'));
    // Q14①：窗口开放提醒员工（保存时已处于开放期则通知）
    if ($status === 1) {
        $today = date('Y-m-d');
        if ($today >= $start && $today <= $end) {
            foreach (all_project_user_ids() as $uid) {
                notify_user((int)$uid, 'window_open', "{$month}月 填报窗口已开放", "填报窗口（{$start} ~ {$end}）已开放，请及时完成 {$month} 月采购填报。");
            }
        }
    }
    return ['ok' => true, 'msg' => '填报窗口已保存'];
}

function handle_windows_delete($id) {
    require_admin();
    $st = db()->prepare("SELECT month FROM fill_windows WHERE id=?");
    $st->execute([$id]);
    $m = $st->fetchColumn();
    db()->prepare("DELETE FROM fill_windows WHERE id=?")->execute([$id]);
    log_action('窗口设置', "删除填报窗口 {$m}");
    return ['ok' => true, 'msg' => '已删除'];
}

// 一键生成未来N个月填报窗口（默认每月20-23日，已有月份跳过）
function handle_admin_gen_windows() {
    require_admin();
    $from = $_GET['from'] ?? date('Y-m');
    $count = min(60, max(1, (int)($_GET['count'] ?? 12)));
    $y = (int)substr($from, 0, 4);
    $m = (int)substr($from, 5, 2);
    $created = 0; $skipped = 0;
    for ($i = 0; $i < $count; $i++) {
        $month = sprintf('%04d-%02d', $y, $m);
        $exists = db()->prepare("SELECT COUNT(*) FROM fill_windows WHERE month=?");
        $exists->execute([$month]);
        if ((int)$exists->fetchColumn() > 0) {
            $skipped++;
        } else {
            $lastDay = (int)date('t', strtotime($month . '-01'));
            $end = min(23, $lastDay);
            db()->prepare("INSERT INTO fill_windows (month, start_date, end_date, status) VALUES (?,?,?,1)")
                ->execute([$month, $month . '-20', $month . '-' . $end]);
            $created++;
        }
        $m++;
        if ($m > 12) { $m = 1; $y++; }
    }
    return ['ok' => true, 'msg' => "已生成 {$created} 个月窗口，跳过已有 {$skipped} 个月", 'data' => ['created' => $created, 'skipped' => $skipped]];
}


// 操作日志列表（Q12B：分页 + 时间范围筛选）
function handle_oplogs() {
    require_admin();
    $action = $_GET['action'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(200, max(10, (int)($_GET['page_size'] ?? 50)));
    $sql = "SELECT * FROM op_logs WHERE 1=1";
    $countSql = "SELECT COUNT(*) FROM op_logs WHERE 1=1";
    $params = [];
    if ($action !== '') { $sql .= " AND action=?"; $countSql .= " AND action=?"; $params[] = $action; }
    if ($keyword !== '') { $sql .= " AND (username LIKE ? OR detail LIKE ?)"; $countSql .= " AND (username LIKE ? OR detail LIKE ?)"; $params[] = '%' . $keyword . '%'; $params[] = '%' . $keyword . '%'; }
    if ($dateFrom !== '') { $sql .= " AND DATE(created_at)>=?"; $countSql .= " AND DATE(created_at)>=?"; $params[] = $dateFrom; }
    if ($dateTo !== '') { $sql .= " AND DATE(created_at)<=?"; $countSql .= " AND DATE(created_at)<=?"; $params[] = $dateTo; }
    $countSt = db()->prepare($countSql);
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $sql .= " ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return ['ok' => true, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'data' => $st->fetchAll()];
}

// ---------- 站内通知 ----------
function handle_notifications_list() {
    $user = require_auth();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(50, max(5, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $st = db()->prepare("SELECT id, type, title, content, is_read, created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $st->execute([(int)$user['id']]);
    $st2 = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
    $st2->execute([(int)$user['id']]);
    $st3 = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $st3->execute([(int)$user['id']]);
    return ['ok' => true, 'total' => (int)$st2->fetchColumn(), 'unread' => (int)$st3->fetchColumn(), 'data' => $st->fetchAll()];
}

function handle_notifications_unread_count() {
    $user = require_auth();
    $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $st->execute([(int)$user['id']]);
    return ['ok' => true, 'unread' => (int)$st->fetchColumn()];
}

function handle_notifications_read_all() {
    $user = require_auth();
    db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([(int)$user['id']]);
    return ['ok' => true, 'msg' => '已全部标记为已读'];
}

function handle_notifications_read_one($id) {
    $user = require_auth();
    db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$id, (int)$user['id']]);
    return ['ok' => true, 'msg' => '已读'];
}

// ---------- 系统设置（价格异常阈值） ----------
function handle_settings_get() {
    require_admin();
    return ['ok' => true, 'data' => ['price_threshold' => price_anomaly_threshold()]];
}

function handle_settings_put() {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($in['price_threshold'])) {
        $v = max(1, min(200, (float)$in['price_threshold']));
        db()->prepare("INSERT INTO sys_settings (skey, svalue) VALUES ('price_threshold', ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)")
            ->execute([(string)$v]);
        log_action('系统设置', "价格异常阈值调整为 {$v}%");
        return ['ok' => true, 'msg' => "阈值已设置为 {$v}%"];
    }
    return ['ok' => false, 'msg' => '参数缺失'];
}
