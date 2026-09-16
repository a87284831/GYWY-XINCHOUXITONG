<?php
/**
 * 采购信息汇总查看（只读，数据源 = 已导入存档 + 预算）
 */

// 有存档的月份列表（月度汇总卡片）
function handle_summary_months() {
    require_admin();
    $rows = db()->query(
        "SELECT ap.month,
                ROUND(SUM(ap.total),2) AS amount,
                COUNT(*) AS items,
                COUNT(DISTINCT ap.project_id) AS projects,
                COUNT(DISTINCT ap.line) AS `lines`
         FROM archived_purchases ap
         GROUP BY ap.month ORDER BY ap.month DESC"
    )->fetchAll();
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'month' => $r['month'],
            'amount' => (float)$r['amount'],
            'items' => (int)$r['items'],
            'projects' => (int)$r['projects'],
            'lines' => (int)$r['lines'],
        ];
    }
    return ['ok' => true, 'data' => ['rows' => $data]];
}

// 商品搜索：定位已采购商品出现的月份/项目
function handle_summary_search() {
    require_admin();
    $q = trim($_GET['q'] ?? '');
    if ($q === '') return ['ok' => true, 'data' => ['rows' => []]];
    $like = '%' . $q . '%';
    $st = db()->prepare(
        "SELECT ap.month, ap.project_id, p.name AS project_name, ap.line, ap.item_name, ap.brand, ap.spec, ap.unit,
                ap.quantity, ap.price, ap.total
         FROM archived_purchases ap
         JOIN payroll.payroll_projects p ON ap.project_id=p.id
         WHERE ap.item_name LIKE ? OR ap.spec LIKE ? OR ap.brand LIKE ?
         ORDER BY ap.month DESC, ap.item_name, ap.project_id
         LIMIT 60"
    );
    $st->execute([$like, $like, $like]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'month' => $r['month'],
            'project_id' => (int)$r['project_id'],
            'project_name' => $r['project_name'],
            'line' => $r['line'],
            'item_name' => $r['item_name'],
            'brand' => $r['brand'],
            'spec' => $r['spec'],
            'unit' => $r['unit'],
            'quantity' => (float)$r['quantity'],
            'price' => (float)$r['price'],
            'total' => (float)$r['total'],
        ];
    }
    return ['ok' => true, 'data' => ['rows' => $rows]];
}

// 某月汇总：项目×条线矩阵 + 合计 + 明细（可按项目/条线筛选）
function handle_summary_month() {
    require_admin();
    $month = $_GET['month'] ?? date('Y-m');
    $filterProject = (int)($_GET['project_id'] ?? 0);
    $filterLine = trim($_GET['line'] ?? '');

    $projects = projects_all();

    // 各项目×条线金额
    $stA = db()->prepare("SELECT project_id, line, SUM(total) AS amount, COUNT(*) AS cnt FROM archived_purchases WHERE month=? GROUP BY project_id, line");
    $stA->execute([$month]);
    $actuals = [];
    foreach ($stA->fetchAll() as $r) $actuals[(int)$r['project_id']][$r['line']] = ['amount' => (float)$r['amount'], 'cnt' => (int)$r['cnt']];

    // 预算
    $stB = db()->prepare("SELECT project_id, amount FROM budget_plan WHERE month=?");
    $stB->execute([$month]);
    $budgets = [];
    foreach ($stB->fetchAll() as $r) $budgets[(int)$r['project_id']] = (float)$r['amount'];

    $lines = ['环境', '绿化', '工程', '秩序', '行政'];
    $matrix = [];
    $totBudget = 0; $totActual = 0; $totItems = 0;
    foreach ($projects as $p) {
        $lineData = [];
        $total = 0.0; $cnt = 0;
        foreach ($lines as $l) {
            $v = $actuals[$p['id']][$l] ?? null;
            $lineData[$l] = $v ? ['amount' => $v['amount'], 'cnt' => $v['cnt']] : null;
            if ($v) { $total += $v['amount']; $cnt += $v['cnt']; }
        }
        $budget = $budgets[$p['id']] ?? 0;
        $totBudget += $budget;
        $totActual += $total;
        $totItems += $cnt;
        $matrix[] = [
            'project_id' => (int)$p['id'],
            'project_name' => $p['name'],
            'lines' => $lineData,
            'total' => round($total, 2),
            'items' => $cnt,
            'budget' => $budget,
            'actual' => round($total, 2),
            'over' => $budget > 0 && $total > $budget,
            'rate' => $budget > 0 ? round($total / $budget * 100, 1) : null,
        ];
    }

    // 明细（筛选）
    $sql = "SELECT ap.month, ap.project_id, p.name AS project_name, ap.line, ap.item_name, ap.brand, ap.spec, ap.unit,
                   ap.quantity, ap.price, ap.total
            FROM archived_purchases ap
            JOIN payroll.payroll_projects p ON ap.project_id=p.id
            WHERE ap.month=?";
    $params = [$month];
    if ($filterProject > 0) { $sql .= " AND ap.project_id=?"; $params[] = $filterProject; }
    if ($filterLine !== '') { $sql .= " AND ap.line=?"; $params[] = $filterLine; }
    $sql .= " ORDER BY FIELD(ap.line,'环境','绿化','工程','秩序','行政'), p.id, ap.id";
    $st = db()->prepare($sql);
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'month' => $r['month'],
            'project_id' => (int)$r['project_id'],
            'project_name' => $r['project_name'],
            'line' => $r['line'],
            'item_name' => $r['item_name'],
            'brand' => $r['brand'],
            'spec' => $r['spec'],
            'unit' => $r['unit'],
            'quantity' => (float)$r['quantity'],
            'price' => (float)$r['price'],
            'total' => (float)$r['total'],
        ];
    }

    return ['ok' => true, 'data' => [
        'month' => $month,
        'lines' => $lines,
        'matrix' => $matrix,
        'items' => $items,
        'total_budget' => round($totBudget, 2),
        'total_actual' => round($totActual, 2),
        'total_rate' => $totBudget > 0 ? round($totActual / $totBudget * 100, 1) : null,
        'total_items' => $totItems,
    ]];
}
