<?php
/** 数据驾驶舱 */

/** 当前用户可查看的项目范围：管理员=全部启用项目，员工=自己归属项目
 *  @return [sql_fragment, params]
 */
function dash_scope(string $alias = ''): array {
    $user = require_auth();
    if ($user['role'] === 'admin') return ['', []];
    $pid = (int)($user['project_id'] ?? 0);
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($pid <= 0) return [" AND {$prefix}project_id IN (0)", []];
    return [" AND {$prefix}project_id=?", [$pid]];
}

/** 员工视角月份内可用的月份（无数据返回空） */
function dash_empty(): array {
    return ['ok' => true, 'data' => null];
}

// 月度看板：总览卡片 + 各条线 + 各项目
function handle_dashboard_monthly() {
    $month = $_GET['month'] ?? date('Y-m');
    $db = db();
    [$scope, $sp] = dash_scope('a');
    if ($sp === [] && strpos($scope, '1=0') !== false) {
        return ['ok' => true, 'data' => [
            'month' => $month,
            'overview' => ['amount' => 0, 'projects' => 0, 'items' => 0],
            'lines' => [], 'projects' => [],
        ]];
    }
    // 总览卡片
    $st = $db->prepare("SELECT COUNT(DISTINCT project_id) AS projects, COUNT(*) AS items, SUM(total) AS amount FROM archived_purchases a WHERE month=?{$scope}");
    $st->execute(array_merge([$month], $sp));
    $overview = $st->fetch();

    // 各条线金额
    $st = $db->prepare("SELECT line, SUM(total) AS amount FROM archived_purchases a WHERE month=?{$scope} GROUP BY line");
    $st->execute(array_merge([$month], $sp));
    $lines = $st->fetchAll();

    // 各项目金额
    $st = $db->prepare(
        "SELECT a.project_id, p.name, SUM(a.total) AS amount FROM archived_purchases a
         JOIN payroll.payroll_projects p ON a.project_id=p.id WHERE a.month=?{$scope} GROUP BY a.project_id, p.name ORDER BY amount DESC"
    );
    $st->execute(array_merge([$month], $sp));
    $projects = $st->fetchAll();

    return ['ok' => true, 'data' => [
        'month' => $month,
        'overview' => [
            'amount' => round((float)($overview['amount'] ?? 0), 2),
            'projects' => (int)($overview['projects'] ?? 0),
            'items' => (int)($overview['items'] ?? 0),
        ],
        'lines' => array_map(fn($r) => ['line' => $r['line'], 'amount' => round((float)$r['amount'], 2)], $lines),
        'projects' => array_map(fn($r) => ['project_id' => (int)$r['project_id'], 'name' => $r['name'], 'amount' => round((float)$r['amount'], 2)], $projects),
    ]];
}

// 当月 vs 上月 各项目环比
function handle_dashboard_compare() {
    $month = $_GET['month'] ?? date('Y-m');
    $prev = date('Y-m', strtotime($month . '-01 -1 month'));
    $db = db();
    [$scope, $sp] = dash_scope('a');
    $sql = "SELECT a.project_id, p.name, a.month, SUM(a.total) AS amount FROM archived_purchases a JOIN payroll.payroll_projects p ON a.project_id=p.id WHERE a.month IN (?,?){$scope} GROUP BY a.project_id, p.name, a.month";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$month, $prev], $sp));
    $rows = $st->fetchAll();
    $cur = []; $last = [];
    foreach ($rows as $r) {
        if ($r['month'] === $month) $cur[$r['project_id']] = ['name' => $r['name'], 'amount' => (float)$r['amount']];
        else $last[$r['project_id']] = ['name' => $r['name'], 'amount' => (float)$r['amount']];
    }
    $projects = dash_visible_projects();
    $result = [];
    foreach ($projects as $p) {
        $c = $cur[$p['id']]['amount'] ?? 0;
        $l = $last[$p['id']]['amount'] ?? 0;
        $diff = round($c - $l, 2);
        $result[] = [
            'project_id' => (int)$p['id'], 'name' => $p['name'],
            'current' => round($c, 2), 'prev' => round($l, 2),
            'diff' => $diff,
            'rate' => $l > 0 ? round(($c - $l) / $l * 100, 1) : null,
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
        ];
    }
    return ['ok' => true, 'data' => ['month' => $month, 'prev_month' => $prev, 'rows' => $result]];
}

/** 当前用户可见项目列表 */
function dash_visible_projects(): array {
    $user = require_auth();
    if ($user['role'] === 'admin') return projects_all();
    $pid = (int)($user['project_id'] ?? 0);
    if ($pid <= 0) return [];
    $st = db()->prepare("SELECT id, name FROM payroll.payroll_projects WHERE id=? AND status='启用'");
    $st->execute([$pid]);
    return $st->fetchAll() ?: [];
}

// 年度趋势（逐月金额；year 为空返回全部月份）
function handle_dashboard_annual() {
    $year = trim($_GET['year'] ?? date('Y'));
    [$scope, $sp] = dash_scope('a');
    if ($sp === [] && strpos($scope, '1=0') !== false) {
        return ['ok' => true, 'data' => ['year' => $year, 'rows' => []]];
    }
    if ($year === '') {
        $sql = "SELECT month, SUM(total) AS amount FROM archived_purchases a" . (trim($scope) !== '' ? " WHERE 1=1{$scope}" : '') . " GROUP BY month ORDER BY month";
        $st = db()->prepare($sql);
        $st->execute($sp);
    } else {
        $st = db()->prepare("SELECT month, SUM(total) AS amount FROM archived_purchases a WHERE month LIKE ?{$scope} GROUP BY month ORDER BY month");
        $st->execute(array_merge(["$year-%"], $sp));
    }
    return ['ok' => true, 'data' => [
        'year' => $year,
        'rows' => array_map(fn($r) => ['month' => $r['month'], 'amount' => round((float)$r['amount'], 2)], $st->fetchAll()),
    ]];
}

// 年度各条线
function handle_dashboard_annual_lines() {
    $year = $_GET['year'] ?? date('Y');
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare("SELECT line, SUM(total) AS amount FROM archived_purchases a WHERE month LIKE ?{$scope} GROUP BY line");
    $st->execute(array_merge(["$year-%"], $sp));
    return ['ok' => true, 'data' => [
        'year' => $year,
        'rows' => array_map(fn($r) => ['line' => $r['line'], 'amount' => round((float)$r['amount'], 2)], $st->fetchAll()),
    ]];
}

// 年度各项目
function handle_dashboard_annual_projects() {
    $year = $_GET['year'] ?? date('Y');
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare(
        "SELECT a.project_id, p.name, SUM(a.total) AS amount FROM archived_purchases a
         JOIN payroll.payroll_projects p ON a.project_id=p.id WHERE a.month LIKE ?{$scope} GROUP BY a.project_id, p.name ORDER BY amount DESC"
    );
    $st->execute(array_merge(["$year-%"], $sp));
    return ['ok' => true, 'data' => [
        'year' => $year,
        'rows' => array_map(fn($r) => ['project_id' => (int)$r['project_id'], 'name' => $r['name'], 'amount' => round((float)$r['amount'], 2)], $st->fetchAll()),
    ]];
}

// 商品采购频次 TOP（近N个月，按商品分组）
function handle_dashboard_top() {
    $months = max(1, min(12, (int)($_GET['months'] ?? 3)));
    $end = $_GET['end_month'] ?? date('Y-m');
    $start = date('Y-m', strtotime($end . '-01 -' . ($months - 1) . ' month'));
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare(
        "SELECT item_name, spec, unit, COUNT(*) AS times, SUM(quantity) AS qty, SUM(total) AS amount
         FROM archived_purchases a WHERE month BETWEEN ? AND ?{$scope} GROUP BY item_name, spec, unit ORDER BY times DESC LIMIT 20"
    );
    $st->execute(array_merge([$start, $end], $sp));
    return ['ok' => true, 'data' => [
        'start' => $start, 'end' => $end,
        'rows' => array_map(fn($r) => [
            'item_name' => $r['item_name'], 'spec' => $r['spec'], 'unit' => $r['unit'],
            'times' => (int)$r['times'], 'qty' => round((float)$r['qty'], 2), 'amount' => round((float)$r['amount'], 2),
        ], $st->fetchAll()),
    ]];
}


// 商品价格趋势（月度/年度对比）：按商品名聚合各月平均单价
function handle_dashboard_price_trend() {
    $item = trim($_GET['item'] ?? '');
    if ($item === '') return ['ok' => false, 'msg' => '请选择商品'];
    $year = (int)($_GET['year'] ?? date('Y'));
    $start = $year . '-01';
    $end = $year . '-12';
    [$scope, $sp] = dash_scope('a');
    $sql = "SELECT month, ROUND(AVG(price),2) AS avg_price, SUM(quantity) AS qty, COUNT(*) AS cnt
            FROM archived_purchases a WHERE item_name=? AND month>=? AND month<=? AND price>0{$scope}
            GROUP BY month ORDER BY month";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$item, $start, $end], $sp));
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[sprintf('%04d-%02d', $year, $m)] = null;
    foreach ($st->fetchAll() as $r) {
        $months[$r['month']] = [
            'avg_price' => (float)$r['avg_price'],
            'qty' => (float)$r['qty'],
            'cnt' => (int)$r['cnt'],
        ];
    }
    // 年度均价（当年 vs 上年）——month 为 char(7) 'YYYY-MM'，用 LEFT 取年份；stdClass 保证 JSON 对象 {2026:22.5}
    $st2 = db()->prepare("SELECT LEFT(month,4) AS y, ROUND(AVG(price),2) AS avg_price FROM archived_purchases a
        WHERE item_name=? AND price>0 AND month>=? AND month<=?{$scope} GROUP BY y ORDER BY y");
    $st2->execute(array_merge([$item, ($year - 1) . '-01', $year . '-12'], $sp));
    $yearAvg = new stdClass();
    foreach ($st2->fetchAll() as $r) $yearAvg->{(string)(int)$r['y']} = (float)$r['avg_price'];
    return ['ok' => true, 'data' => [
        'item' => $item, 'year' => $year,
        'months' => $months,
        'year_avg' => $yearAvg,
    ]];
}

// P2-13：价格异常监控（本月 vs 上月，按商品名全规格均价环比，阈值可配置，默认±30%）
function handle_dashboard_price_anomalies() {
    $month = $_GET['month'] ?? date('Y-m');
    $threshold = price_anomaly_threshold();
    $m = date_create_from_format('Y-m', $month);
    $prev = $m ? (clone $m)->modify('-1 month')->format('Y-m') : '';
    [$scope, $sp] = dash_scope('a');
    // 本月各商品均价
    $st = db()->prepare(
        "SELECT item_name, ROUND(AVG(price),2) AS avg_price, SUM(quantity) AS qty, COUNT(*) AS cnt, GROUP_CONCAT(DISTINCT unit) AS units
         FROM archived_purchases a WHERE month=? AND price>0{$scope} GROUP BY item_name"
    );
    $st->execute(array_merge([$month], $sp));
    $cur = [];
    foreach ($st->fetchAll() as $r) $cur[$r['item_name']] = $r;
    // 上月各商品均价
    $st2 = db()->prepare(
        "SELECT item_name, ROUND(AVG(price),2) AS avg_price, SUM(quantity) AS qty
         FROM archived_purchases a WHERE month=? AND price>0{$scope} GROUP BY item_name"
    );
    $st2->execute(array_merge([$prev], $sp));
    $prevMap = [];
    foreach ($st2->fetchAll() as $r) $prevMap[$r['item_name']] = (float)$r['avg_price'];
    $rows = [];
    foreach ($cur as $name => $r) {
        $curP = (float)$r['avg_price'];
        if (isset($prevMap[$name]) && $prevMap[$name] > 0) {
            $prevP = $prevMap[$name];
            $rate = round(($curP - $prevP) / $prevP * 100, 1);
            if (abs($rate) >= $threshold) {
                $rows[] = [
                    'item_name' => $name,
                    'cur_price' => $curP,
                    'prev_price' => $prevP,
                    'rate' => $rate,
                    'trend' => $rate > 0 ? 'up' : 'down',
                    'qty' => (float)$r['qty'],
                    'cnt' => (int)$r['cnt'],
                    'units' => $r['units'] ?? '',
                ];
            }
        }
    }
    usort($rows, fn($a, $b) => abs($b['rate']) <=> abs($a['rate']));
    return ['ok' => true, 'data' => [
        'month' => $month, 'prev_month' => $prev, 'threshold' => $threshold,
        'rows' => $rows, 'total' => count($rows),
    ]];
}

// ========== 新增：驾驶舱扩展 ==========

// 当年度累计采购金额（1月~当前选中月）+ 较上年同期增减
function handle_dashboard_ytd() {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $mm)) $month = date('Y-m');
    $y = (int)substr($month, 0, 4);
    $endM = substr($month, 5, 2);
    $start = sprintf('%04d-01', $y);
    $prevStart = sprintf('%04d-01', $y - 1);
    $prevEnd = sprintf('%04d-%s', $y - 1, $endM);
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare("SELECT COALESCE(SUM(total),0) FROM archived_purchases a WHERE month>=? AND month<=?{$scope}");
    $st->execute(array_merge([$start, $month], $sp));
    $ytd = (float)$st->fetchColumn();
    $st2 = db()->prepare("SELECT COALESCE(SUM(total),0) FROM archived_purchases a WHERE month>=? AND month<=?{$scope}");
    $st2->execute(array_merge([$prevStart, $prevEnd], $sp));
    $prevYtd = (float)$st2->fetchColumn();
    return ['ok' => true, 'data' => [
        'month' => $month,
        'ytd' => round($ytd, 2),
        'prev_ytd' => round($prevYtd, 2),
        'diff' => round($ytd - $prevYtd, 2),
        'rate' => $prevYtd > 0 ? round(($ytd - $prevYtd) / $prevYtd * 100, 1) : null,
    ]];
}

// 本月 vs 去年同月 各项目同比
function handle_dashboard_yoy() {
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $mm)) $month = date('Y-m');
    $prev = (($y = (int)substr($month, 0, 4)) - 1) . '-' . substr($month, 5, 2);
    $db = db();
    [$scope, $sp] = dash_scope('a');
    $sql = "SELECT a.project_id, p.name, a.month, SUM(a.total) AS amount FROM archived_purchases a JOIN payroll.payroll_projects p ON a.project_id=p.id WHERE a.month IN (?,?){$scope} GROUP BY a.project_id, p.name, a.month";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$month, $prev], $sp));
    $rows = $st->fetchAll();
    $cur = []; $last = [];
    foreach ($rows as $r) {
        if ($r['month'] === $month) $cur[$r['project_id']] = ['name' => $r['name'], 'amount' => (float)$r['amount']];
        else $last[$r['project_id']] = ['name' => $r['name'], 'amount' => (float)$r['amount']];
    }
    $result = [];
    foreach (dash_visible_projects() as $p) {
        $c = $cur[$p['id']]['amount'] ?? 0;
        $l = $last[$p['id']]['amount'] ?? 0;
        $diff = round($c - $l, 2);
        $result[] = [
            'project_id' => (int)$p['id'], 'name' => $p['name'],
            'current' => round($c, 2), 'prev' => round($l, 2),
            'diff' => $diff,
            'rate' => $l > 0 ? round(($c - $l) / $l * 100, 1) : null,
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
        ];
    }
    return ['ok' => true, 'data' => ['month' => $month, 'prev_month' => $prev, 'rows' => $result]];
}

// 本月各项目预算执行（预算 vs 实际 vs 执行率）
function handle_dashboard_budget_exec() {
    $month = $_GET['month'] ?? date('Y-m');
    $projects = dash_visible_projects();
    $stB = db()->prepare("SELECT project_id, amount FROM budget_plan WHERE month=?");
    $stB->execute([$month]);
    $budgets = [];
    foreach ($stB->fetchAll() as $r) $budgets[(int)$r['project_id']] = (float)$r['amount'];
    [$scope, $sp] = dash_scope('a');
    $stA = db()->prepare("SELECT project_id, SUM(total) AS amount, COUNT(*) AS cnt FROM archived_purchases a WHERE month=?{$scope} GROUP BY project_id");
    $stA->execute(array_merge([$month], $sp));
    $actuals = [];
    foreach ($stA->fetchAll() as $r) $actuals[(int)$r['project_id']] = ['amount' => (float)$r['amount'], 'cnt' => (int)$r['cnt']];
    $rows = [];
    $totalBudget = 0; $totalActual = 0;
    foreach ($projects as $p) {
        $b = $budgets[$p['id']] ?? 0;
        $a = $actuals[$p['id']]['amount'] ?? 0;
        $totalBudget += $b;
        $totalActual += $a;
        $rows[] = [
            'project_id' => (int)$p['id'],
            'project_name' => $p['name'],
            'budget' => round($b, 2),
            'actual' => round($a, 2),
            'diff' => round($b - $a, 2),
            'over' => $b > 0 && $a > $b,
            'rate' => $b > 0 ? round($a / $b * 100, 1) : null,
            'items' => $actuals[$p['id']]['cnt'] ?? 0,
        ];
    }
    return ['ok' => true, 'data' => [
        'month' => $month,
        'rows' => $rows,
        'total_budget' => round($totalBudget, 2),
        'total_actual' => round($totalActual, 2),
        'total_rate' => $totalBudget > 0 ? round($totalActual / $totalBudget * 100, 1) : null,
    ]];
}

// 本月填报进度（项目维度：应填/已填/已提交/已确认）
function handle_dashboard_fill_progress() {
    $month = $_GET['month'] ?? date('Y-m');
    $projects = dash_visible_projects();
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare(
        "SELECT project_id, COUNT(*) AS total,
            SUM(status='draft') AS drafts,
            SUM(status='submitted') AS submitted,
            SUM(status='confirmed') AS confirmed,
            SUM(status='returned') AS returned
         FROM purchase_items a WHERE month=?{$scope} GROUP BY project_id"
    );
    $st->execute(array_merge([$month], $sp));
    $stats = [];
    foreach ($st->fetchAll() as $r) $stats[(int)$r['project_id']] = $r;
    $rows = [];
    $filled = 0; $submittedProj = 0; $confirmedProj = 0; $returnedProj = 0;
    $totItems = 0; $totSubmitted = 0; $totConfirmed = 0; $totReturned = 0;
    foreach ($projects as $p) {
        $s = $stats[$p['id']] ?? null;
        $total = $s ? (int)$s['total'] : 0;
        $sub = $s ? (int)$s['submitted'] : 0;
        $conf = $s ? (int)$s['confirmed'] : 0;
        $ret = $s ? (int)$s['returned'] : 0;
        if ($total > 0) $filled++;
        if ($sub > 0) $submittedProj++;
        if ($conf > 0) $confirmedProj++;
        if ($ret > 0) $returnedProj++;
        $totItems += $total; $totSubmitted += $sub; $totConfirmed += $conf; $totReturned += $ret;
        $status = $total === 0 ? '未填报' : ($ret > 0 ? '已退回' : ($conf === $total ? '已确认' : '填报中'));
        $rows[] = [
            'project_id' => (int)$p['id'], 'project_name' => $p['name'],
            'total' => $total, 'submitted' => $sub, 'confirmed' => $conf, 'returned' => $ret,
            'has_fill' => $total > 0, 'status' => $status,
        ];
    }
    return ['ok' => true, 'data' => [
        'month' => $month,
        'total_projects' => count($projects),
        'filled_projects' => $filled,
        'submitted_projects' => $submittedProj,
        'confirmed_projects' => $confirmedProj,
        'returned_projects' => $returnedProj,
        'items_total' => $totItems,
        'items_submitted' => $totSubmitted,
        'items_confirmed' => $totConfirmed,
        'items_returned' => $totReturned,
        'rows' => $rows,
    ]];
}

// 本月清单外采购占比（条数与金额）
function handle_dashboard_custom_ratio() {
    $month = $_GET['month'] ?? date('Y-m');
    [$scope, $sp] = dash_scope('a');
    $st = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity*price),0) AS amount
         FROM purchase_items a WHERE month=? AND is_custom=1{$scope}"
    );
    $st->execute(array_merge([$month], $sp));
    $c = $st->fetch();
    $st2 = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity*price),0) AS amount
         FROM purchase_items a WHERE month=?{$scope}"
    );
    $st2->execute(array_merge([$month], $sp));
    $t = $st2->fetch();
    $customItems = (int)$c['cnt'];
    $customAmount = (float)$c['amount'];
    $totalItems = (int)$t['cnt'];
    $totalAmount = (float)$t['amount'];
    return ['ok' => true, 'data' => [
        'month' => $month,
        'custom_items' => $customItems,
        'custom_amount' => round($customAmount, 2),
        'total_items' => $totalItems,
        'total_amount' => round($totalAmount, 2),
        'item_ratio' => $totalItems > 0 ? round($customItems / $totalItems * 100, 1) : 0,
        'amount_ratio' => $totalAmount > 0 ? round($customAmount / $totalAmount * 100, 1) : 0,
    ]];
}
