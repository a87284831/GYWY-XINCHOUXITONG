<?php
/** 填报模块 */

// 当前/指定月份填报窗口
function handle_fill_window() {
    $month = $_GET['month'] ?? date('Y-m');
    $st = db()->prepare("SELECT id, month, start_date, end_date, status FROM fill_windows WHERE month=? LIMIT 1");
    $st->execute([$month]);
    $w = $st->fetch();
    if (!$w) {
        return ['ok' => true, 'data' => ['open' => false, 'msg' => '本月未配置填报窗口', 'window' => null]];
    }
    $today = date('Y-m-d');
    $open = (int)$w['status'] === 1 && $today >= $w['start_date'] && $today <= $w['end_date'];
    $msg = $open ? '填报开放中' : ($today < $w['start_date'] ? '填报尚未开始' : '填报已截止');
    return ['ok' => true, 'data' => ['open' => $open, 'msg' => $msg, 'window' => $w]];
}

// 填报明细列表
function handle_fill_items() {
    $user = require_auth();
    $month = $_GET['month'] ?? date('Y-m');
    $project_id = (int)($_GET['project_id'] ?? 0);
    // 项目角色只能看自己项目
    if ($user['role'] !== 'admin') {
        $project_id = (int)$user['project_id'];
    }
    $line = $_GET['line'] ?? '';
    $sql = "SELECT pi.*, p.name AS project_name FROM purchase_items pi JOIN payroll.payroll_projects p ON pi.project_id=p.id WHERE pi.month=?";
    $params = [$month];
    if ($project_id > 0) { $sql .= " AND pi.project_id=?"; $params[] = $project_id; }
    if ($line !== '') { $sql .= " AND pi.line=?"; $params[] = $line; }
    $sql .= " ORDER BY pi.line, pi.id";
    $st = db()->prepare($sql);
    $st->execute($params);
    return ['ok' => true, 'data' => $st->fetchAll()];
}

// 新增明细
function handle_fill_create() {
    $user = require_auth();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $month = $in['month'] ?? date('Y-m');
    $project_id = (int)($in['project_id'] ?? 0);
    if ($user['role'] !== 'admin') {
        $project_id = (int)$user['project_id'];
    }
    if (month_archived($month)) return ['ok' => false, 'msg' => '本月已归档锁定，不可填报'];
    // 窗口期校验（admin 不受限）
    if ($user['role'] !== 'admin' && !fill_window_open($month)) {
        return ['ok' => false, 'msg' => fill_window_msg($month)];
    }
    if ($project_id <= 0) return ['ok' => false, 'msg' => '项目缺失'];
    $line = trim($in['line'] ?? '');
    if ($line === '') return ['ok' => false, 'msg' => '条线必选'];
    $product_id = isset($in['product_id']) && $in['product_id'] ? (int)$in['product_id'] : null;
    $is_custom = $product_id ? 0 : 1;
    $item_name = trim($in['item_name'] ?? '');
    if ($item_name === '') return ['ok' => false, 'msg' => '商品名称必填'];
    $brand = trim($in['brand'] ?? '');
    $spec = norm_spec($in['spec'] ?? '');
    $unit = trim($in['unit'] ?? '');
    $quantity = (float)($in['quantity'] ?? 0);
    $stock = (float)($in['stock'] ?? 0);
    $reason = trim($in['reason'] ?? '');
    $use_location = trim($in['use_location'] ?? '');
    $remark = trim($in['remark'] ?? '');
    $status = ($in['status'] ?? 'draft') === 'submitted' ? 'submitted' : 'draft';
    $st = db()->prepare(
        "INSERT INTO purchase_items (month, project_id, line, product_id, item_name, brand, spec, unit, quantity, stock, reason, use_location, remark, is_custom, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $st->execute([$month, $project_id, $line, $product_id, $item_name, $brand, $spec, $unit, $quantity, $stock, $reason, $use_location, $remark, $is_custom, $status, (int)$user['id']]);
    $newId = (int)db()->lastInsertId();
    // 保存即提交：记日志 + 通知管理员待确认
    if ($status === 'submitted') {
        log_action('提交', "{$month} {$item_name}({$spec}) 提交填报（保存即提交）");
        if ($user['role'] !== 'admin') notify_submitted($month, $project_id, $user, $item_name);
    }
    return ['ok' => true, 'msg' => '已保存', 'data' => ['id' => $newId]];
}

// 修改明细
function handle_fill_update($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) return ['ok' => false, 'msg' => '记录不存在'];
    if ($user['role'] !== 'admin' && (int)$item['project_id'] !== (int)$user['project_id']) {
        return ['ok' => false, 'msg' => '只能操作本项目的记录'];
    }
    if (month_archived($item['month'])) return ['ok' => false, 'msg' => '本月已归档锁定，不可修改'];
    if ($item['status'] === 'confirmed') return ['ok' => false, 'msg' => '已确认的记录不可修改'];
    // 已提交：仅管理员可直接编辑；员工只能执行"撤销提交"（status→draft），禁止修改任何字段
    if ($item['status'] === 'submitted' && $user['role'] !== 'admin') {
        $in0 = json_decode(file_get_contents('php://input'), true) ?? [];
        $keys0 = array_keys($in0);
        $onlyUnsubmit = count($keys0) === 1 && ($keys0[0] ?? '') === 'status' && ($in0['status'] ?? '') === 'draft';
        if (!$onlyUnsubmit) {
            return ['ok' => false, 'msg' => '已提交的记录不可修改，请先撤销提交（招采确认前）'];
        }
    }
    // 窗口外仅允许修改"已退回"的记录（退回修改不受窗口限制）
    if ($user['role'] !== 'admin' && !fill_window_open($item['month']) && $item['status'] !== 'returned') {
        return ['ok' => false, 'msg' => fill_window_msg($item['month'])];
    }
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $oldStatus = $item['status'];
    $fields = ['line', 'product_id', 'item_name', 'brand', 'spec', 'unit', 'quantity', 'stock', 'reason', 'use_location', 'remark', 'status'];
    $sets = []; $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "{$f}=?";
            $v = $in[$f];
            if ($f === 'product_id') { $v = $v ? (int)$v : null; }
            elseif ($f === 'status') { $v = in_array($v, ['draft', 'submitted']) ? $v : 'draft'; }
            elseif (in_array($f, ['quantity', 'stock'])) { $v = (float)$v; }
            elseif ($f === 'spec') { $v = norm_spec((string)$v); }
            else { $v = trim((string)$v); }
            $params[] = $v;
        }
    }
    if (!$sets) return ['ok' => false, 'msg' => '无更新内容'];
    // 若选择了标准商品则不再是清单外
    if (array_key_exists('product_id', $in)) {
        $sets[] = "is_custom=?";
        $params[] = $in['product_id'] ? 0 : 1;
    }
    $params[] = $id;
    $sets[] = "resubmitted_at=" . ($oldStatus === 'returned' && array_key_exists('status', $in) && ($in['status'] ?? '') === 'submitted' ? 'NOW()' : 'resubmitted_at');
    db()->prepare("UPDATE purchase_items SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    $newStatus = array_key_exists('status', $in) ? $in['status'] : $oldStatus;
    $newStatus = in_array($newStatus, ['draft', 'submitted']) ? $newStatus : $oldStatus;
    // Q10B：仅状态变化记日志（草稿保存不记）
    if ($newStatus !== $oldStatus) {
        if ($newStatus === 'submitted') {
            log_action('提交', "{$item['month']} {$item['item_name']}({$item['spec']}) 状态 {$oldStatus}→submitted");
            if ($user['role'] !== 'admin') notify_submitted($item['month'], (int)$item['project_id'], $user, $item['item_name']);
        } else {
            log_action('撤销提交', "{$item['month']} {$item['item_name']}({$item['spec']}) 状态 submitted→draft");
        }
    }
    return ['ok' => true, 'msg' => '已更新'];
}

// 提交明细（草稿→已提交）
function handle_fill_submit($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) return ['ok' => false, 'msg' => '记录不存在'];
    if ($user['role'] !== 'admin' && (int)$item['project_id'] !== (int)$user['project_id']) {
        return ['ok' => false, 'msg' => '只能操作本项目的记录'];
    }
    if (month_archived($item['month'])) return ['ok' => false, 'msg' => '本月已归档锁定，不可提交'];
    if ($user['role'] !== 'admin' && !fill_window_open($item['month']) && $item['status'] !== 'returned') {
        return ['ok' => false, 'msg' => fill_window_msg($item['month'])];
    }
    if ($item['status'] === 'submitted') return ['ok' => false, 'msg' => '该记录已提交，请勿重复提交'];
    $wasReturned = ($item['status'] === 'returned');
    $sql = $wasReturned
        ? "UPDATE purchase_items SET status='submitted', resubmitted_at=NOW() WHERE id=?"
        : "UPDATE purchase_items SET status='submitted' WHERE id=?";
    db()->prepare($sql)->execute([$id]);
    log_action('提交', "{$item['month']} {$item['item_name']}({$item['spec']}) 提交填报");
    if ($user['role'] !== 'admin') notify_submitted($item['month'], (int)$item['project_id'], $user, $item['item_name']);
    return ['ok' => true, 'msg' => '已提交'];
}

// 删除明细
function handle_fill_delete($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) return ['ok' => false, 'msg' => '记录不存在'];
    if ($user['role'] !== 'admin' && (int)$item['project_id'] !== (int)$user['project_id']) {
        return ['ok' => false, 'msg' => '只能操作本项目的记录'];
    }
    if (month_archived($item['month'])) return ['ok' => false, 'msg' => '本月已归档锁定，不可删除'];
    if ($item['status'] === 'confirmed') return ['ok' => false, 'msg' => '已确认的记录不可删除'];
    // 已提交：员工不可删除（需先撤销）；管理员可删
    if ($item['status'] === 'submitted' && $user['role'] !== 'admin') {
        return ['ok' => false, 'msg' => '已提交的记录不可删除，请先撤销提交'];
    }
    // 窗口外仅允许删除"已退回"的记录
    if ($user['role'] !== 'admin' && !fill_window_open($item['month']) && $item['status'] !== 'returned') {
        return ['ok' => false, 'msg' => fill_window_msg($item['month'])];
    }
    // 删除退回记录时同步删除对应存档行（价格已导入过的）
    if ($item['status'] === 'returned') {
        db()->prepare("DELETE FROM archived_purchases WHERE month=? AND project_id=? AND item_name=? AND spec=?")
            ->execute([$item['month'], $item['project_id'], $item['item_name'], $item['spec']]);
    }
    db()->prepare("DELETE FROM purchase_items WHERE id=?")->execute([$id]);
    log_action('删除', "{$item['month']} {$item['item_name']}({$item['spec']}) 删除填报记录（原状态 {$item['status']}）");
    return ['ok' => true, 'msg' => '已删除'];
}

// 近3个月采购频率（次数+总量；单位不一致只显示次数）
function handle_fill_frequency() {
    $user = require_auth();
    $product_id = (int)($_GET['product_id'] ?? 0);
    $item_name = trim($_GET['item_name'] ?? '');
    $spec = norm_spec($_GET['spec'] ?? '');
    $unit = trim($_GET['unit'] ?? '');
    $line = trim($_GET['line'] ?? '');
    $project_id = (int)($_GET['project_id'] ?? 0);
    $month = $_GET['month'] ?? date('Y-m');
    if ($user['role'] !== 'admin' && $project_id <= 0) {
        $project_id = (int)$user['project_id'];
    }
    if ($product_id <= 0 && $item_name === '') return ['ok' => false, 'msg' => '缺少商品'];
    // 计算近3个自然月（不含当前填报月）
    $m = date_create_from_format('Y-m', $month);
    $months = [];
    for ($i = 1; $i <= 3; $i++) {
        $months[] = (clone $m)->modify("-{$i} month")->format('Y-m');
    }
    $sql = "SELECT month, unit, SUM(quantity) AS qty FROM archived_purchases WHERE project_id=? AND month IN ('" . implode("','", $months) . "')";
    $params = [$project_id];
    if ($line !== '') { $sql .= " AND line=?"; $params[] = $line; }
    if ($product_id > 0) {
        $sql .= " AND (product_id=? OR (product_id IS NULL AND item_name=?))";
        $params[] = $product_id; $params[] = $item_name;
    } else {
        $sql .= " AND item_name=? AND spec=?";
        $params[] = $item_name; $params[] = $spec;
    }
    $sql .= " GROUP BY month, unit ORDER BY month";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $count = 0; $total = 0; $same_unit = true; $units = [];
    $detail = [];
    foreach ($rows as $r) {
        $count++;
        $detail[] = ['month' => $r['month'], 'qty' => (float)$r['qty'], 'unit' => $r['unit']];
        if (!in_array($r['unit'], $units)) $units[] = $r['unit'];
        $total += (float)$r['qty'];
    }
    if (count($units) > 1) $same_unit = false;
    return ['ok' => true, 'data' => [
        'count' => $count,
        'total' => $same_unit ? round($total, 2) : null,
        'unit' => $same_unit ? ($units[0] ?? $unit) : '',
        'same_unit' => $same_unit,
        'detail' => $detail,
    ]];
}

/** 内部工具 */
function fetch_item($id) {
    $st = db()->prepare("SELECT * FROM purchase_items WHERE id=?");
    $st->execute([$id]);
    return $st->fetch();
}

/** 通知管理员：新提交待确认 */
function notify_submitted(string $month, int $project_id, array $user, string $item_name): void {
    $pname = '';
    try {
        $st = db()->prepare("SELECT name FROM payroll.payroll_projects WHERE id=?");
        $st->execute([$project_id]);
        $pname = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    $title = '有新填报待确认';
    $content = "【{$pname}】{$month}月 {$user['name']} 提交了填报（{$item_name}），请及时确认。";
    foreach (admin_user_ids() as $uid) {
        notify_user((int)$uid, 'fill_submitted', $title, $content, '#/overview');
    }
}

function fill_window_open($month): bool {
    $st = db()->prepare("SELECT start_date, end_date, status FROM fill_windows WHERE month=? LIMIT 1");
    $st->execute([$month]);
    $w = $st->fetch();
    if (!$w || (int)$w['status'] !== 1) return false;
    $today = date('Y-m-d');
    return $today >= $w['start_date'] && $today <= $w['end_date'];
}

function fill_window_msg($month): string {
    $st = db()->prepare("SELECT start_date, end_date, status FROM fill_windows WHERE month=? LIMIT 1");
    $st->execute([$month]);
    $w = $st->fetch();
    if (!$w) return '本月未配置填报窗口';
    if ((int)$w['status'] !== 1) return '本月填报窗口已停用';
    $today = date('Y-m-d');
    if ($today < $w['start_date']) return '填报尚未开始（' . $w['start_date'] . ' 起）';
    return '填报已截止（' . $w['end_date'] . ' 止）';
}

// 统一窗口信息（含状态）
function fill_window_info($month): array {
    $st = db()->prepare("SELECT id, month, start_date, end_date, status FROM fill_windows WHERE month=? LIMIT 1");
    $st->execute([$month]);
    $w = $st->fetch();
    if (!$w) return ['window' => null, 'open' => false, 'msg' => '未配置填报窗口'];
    $today = date('Y-m-d');
    $open = (int)$w['status'] === 1 && $today >= $w['start_date'] && $today <= $w['end_date'];
    $msg = '填报开放中';
    if (!$open) {
        $msg = (int)$w['status'] !== 1 ? '填报窗口已停用' : ($today < $w['start_date'] ? '填报尚未开始' : '填报已截止');
    }
    return ['window' => $w, 'open' => $open, 'msg' => $msg];
}

// 员工端：我的月份列表（当年12个月 + 往年有数据的月份）
function handle_my_months() {
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        $project_id = (int)$user['project_id'];
    } else {
        $project_id = (int)($_GET['project_id'] ?? 0);
    }
    if ($project_id <= 0) return ['ok' => false, 'msg' => '项目缺失'];
    $year = (int)($_GET['year'] ?? date('Y'));
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[] = sprintf('%04d-%02d', $year, $m);
    $st = db()->prepare("SELECT DISTINCT month FROM purchase_items WHERE project_id=? UNION SELECT DISTINCT month FROM archived_purchases WHERE project_id=? ORDER BY month");
    $st->execute([$project_id, $project_id]);
    foreach ($st->fetchAll() as $r) {
        if (!in_array($r['month'], $months)) $months[] = $r['month'];
    }
    sort($months);
    $out = [];
    foreach ($months as $month) {
        $w = fill_window_info($month);
        $st = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(status='confirmed'),0) cf, COALESCE(SUM(status='submitted'),0) cs, COALESCE(SUM(status='returned'),0) cr FROM purchase_items WHERE project_id=? AND month=?");
        $st->execute([$project_id, $month]);
        $r = $st->fetch();
        $st2 = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) a FROM archived_purchases WHERE project_id=? AND month=?");
        $st2->execute([$project_id, $month]);
        $a = $st2->fetch();
        // 退回原因摘要（最近一条，供入口卡片提示）
        $st3 = db()->prepare("SELECT return_reason FROM purchase_items WHERE project_id=? AND month=? AND status='returned' AND return_reason<>'' ORDER BY returned_at DESC LIMIT 1");
        $st3->execute([$project_id, $month]);
        $return_reason = (string)$st3->fetchColumn();
        $out[] = [
            'month' => $month,
            'window' => $w['window'],
            'open' => $w['open'],
            'msg' => $w['msg'],
            'item_count' => (int)$r['c'],
            'confirmed_count' => (int)$r['cf'],
            'submitted_count' => (int)$r['cs'],
            'returned_count' => (int)$r['cr'],
            'return_reason' => $return_reason,
            'imported' => (int)$a['c'] > 0,
            'amount' => round((float)$a['a'], 2),
        ];
    }
    return ['ok' => true, 'data' => ['year' => $year, 'rows' => $out]];
}

// 员工端：某月明细（已导入价格则读存档，否则读填报记录）
function handle_my_month_items() {
    $user = require_auth();
    $month = $_GET['month'] ?? date('Y-m');
    if ($user['role'] !== 'admin') {
        $project_id = (int)$user['project_id'];
    } else {
        $project_id = (int)($_GET['project_id'] ?? 0);
    }
    if ($project_id <= 0) return ['ok' => false, 'msg' => '项目缺失'];
    // 已导入：读存档（含单价金额）
    $st = db()->prepare("SELECT line, item_name, spec, brand, unit, quantity, price, total FROM archived_purchases WHERE project_id=? AND month=? ORDER BY line, id");
    $st->execute([$project_id, $month]);
    $arch = $st->fetchAll();
    if ($arch) {
        return ['ok' => true, 'data' => ['imported' => true, 'rows' => array_map(fn($r) => [
            'line' => $r['line'], 'item_name' => $r['item_name'], 'spec' => $r['spec'], 'brand' => $r['brand'],
            'unit' => $r['unit'], 'quantity' => (float)$r['quantity'], 'price' => round((float)$r['price'], 2), 'total' => round((float)$r['total'], 2),
        ], $arch)]];
    }
    // 未导入：读填报记录
    $st = db()->prepare("SELECT line, item_name, spec, brand, unit, quantity, stock, reason, is_custom, status, return_reason, returned_at FROM purchase_items WHERE project_id=? AND month=? ORDER BY line, id");
    $st->execute([$project_id, $month]);
    return ['ok' => true, 'data' => ['imported' => false, 'rows' => array_map(fn($r) => [
        'line' => $r['line'], 'item_name' => $r['item_name'], 'spec' => $r['spec'], 'brand' => $r['brand'],
        'unit' => $r['unit'], 'quantity' => (float)$r['quantity'], 'stock' => (float)$r['stock'], 'reason' => $r['reason'],
        'is_custom' => (int)$r['is_custom'], 'status' => $r['status'], 'price' => null, 'total' => null,
        'return_reason' => $r['return_reason'] ?? '', 'returned_at' => $r['returned_at'] ?? '',
    ], $st->fetchAll())]];
}
