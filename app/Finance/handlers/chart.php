<?php
/** 财务管理 - 驾驶舱图表数据 handlers */

use App\Finance\FinanceStop;
use App\Finance\Support;

/** 驾驶舱聚合数据：一次返回全部图表数据（按年份，可选项目） */
function handle_chart_dashboard(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $year = (int) ($_GET['year'] ?? date('Y'));
    if ($year < 2019 || $year > 2035) throw new FinanceStop(['ok' => false, 'msg' => '年份超出范围'], 400);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $scope = fin_accessible_projects();
    if ($projectId > 0) {
        fin_assert_project_access($projectId);
        $scope = [$projectId];
    }

    $cats = fin_receivable_categories();
    $types = fin_payment_types();
    $months = fin_year_months($year);
    $in = implode(',', array_fill(0, count($scope), '?'));
    $params = [$year . '-%'];
    foreach ($scope as $p) $params[] = $p;

    // 1) 应收 类别汇总（当年）
    $st = fdb()->prepare("SELECT category, SUM(amount) total FROM fin_receivable_items WHERE month LIKE ? AND project_id IN ($in) GROUP BY category");
    $st->execute($params);
    $catRows = $st->fetchAll();
    $catTotal = array_fill_keys(array_keys($cats), 0.0);
    $receivableGrand = 0.0;
    foreach ($catRows as $r) {
        $catTotal[$r['category']] = (float) $r['total'];
        $receivableGrand += (float) $r['total'];
    }

    // 2) 项目对比（当年应收 + 减免[月级状态表]）
    $st = fdb()->prepare("SELECT project_id, SUM(amount) total FROM fin_receivable_items WHERE month LIKE ? AND project_id IN ($in) GROUP BY project_id");
    $st->execute($params);
    $projRows = $st->fetchAll();
    $st = fdb()->prepare("SELECT project_id, SUM(IFNULL(discount_amount,0)) discount FROM fin_receivable_month_status WHERE month LIKE ? AND project_id IN ($in) GROUP BY project_id");
    $st->execute($params);
    $discProjRows = $st->fetchAll();
    $projMap = [];
    foreach ($projRows as $r) $projMap[(int) $r['project_id']] = ['total' => (float) $r['total'], 'discount' => 0.0];
    foreach ($discProjRows as $r) {
        if (isset($projMap[(int) $r['project_id']])) $projMap[(int) $r['project_id']]['discount'] = (float) $r['discount'];
    }
    $projCompare = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $d = $projMap[(int) $p['id']] ?? ['total' => 0.0, 'discount' => 0.0];
        $projCompare[] = ['name' => $p['name'], 'total' => $d['total'], 'discount' => $d['discount']];
    }
    usort($projCompare, fn($a, $b) => $b['total'] <=> $a['total']);

    // 3) 月度趋势（当年应收 月度合计）
    $st = fdb()->prepare("SELECT month, SUM(amount) total FROM fin_receivable_items WHERE month LIKE ? AND project_id IN ($in) GROUP BY month");
    $st->execute($params);
    $monthRows = $st->fetchAll();
    $trend = array_fill_keys($months, 0.0);
    foreach ($monthRows as $r) $trend[$r['month']] = (float) $r['total'];

    // 4) 付款记录：三状态合计 + 各状态类型占比（当年；month 为年度值 '2026' 或旧 '2026-12' 兼容；未支付=已确认−已支付自动计算）
    $payStatus = ['confirmed' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
    $payTypeTotal = array_fill_keys(array_keys($types), 0.0);
    $payParams = [$year . '-%', (string) $year];
    foreach ($scope as $p) $payParams[] = $p;
    $st = fdb()->prepare("SELECT status, type, SUM(amount) total FROM fin_payment_items WHERE (month LIKE ? OR month = ?) AND project_id IN ($in) AND status IN ('confirmed','paid') GROUP BY status, type");
    $st->execute($payParams);
    $payRows = $st->fetchAll();
    $byStatusType = [];
    $payTypePaid = array_fill_keys(array_keys($types), 0.0);
    foreach ($payRows as $r) {
        $byStatusType[$r['status']][$r['type']] = (float) $r['total'];
        if ($r['status'] === 'confirmed') $payTypeTotal[$r['type']] = (float) $r['total'];
        if ($r['status'] === 'paid') $payTypePaid[$r['type']] = (float) $r['total'];
    }
    $payGrand = 0.0;
    foreach (array_keys($types) as $tk) {
        $c = (float) ($byStatusType['confirmed'][$tk] ?? 0);
        $p = (float) ($byStatusType['paid'][$tk] ?? 0);
        $u = round($c - $p, 2);
        if ($u < 0) $u = 0;
        $payStatus['confirmed'] += $c;
        $payStatus['paid'] += $p;
        $payStatus['unpaid'] += $u;
        $payGrand += $c;
    }
    // 付款月度趋势 → 已无月份概念，改为三状态柱（前端适配）
    $payTrend = ['confirmed' => $payStatus['confirmed'], 'paid' => $payStatus['paid'], 'unpaid' => $payStatus['unpaid']];

    // 5) 减免赠送排行（当年，按项目，月级状态表）
    $st = fdb()->prepare("SELECT project_id, SUM(IFNULL(discount_amount,0)) d FROM fin_receivable_month_status WHERE month LIKE ? AND project_id IN ($in) AND discount_amount IS NOT NULL GROUP BY project_id");
    $st->execute($params);
    $discRows = $st->fetchAll();
    $discMap = [];
    foreach ($discRows as $r) $discMap[(int) $r['project_id']] = (float) $r['d'];
    $discTop = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $discTop[] = ['name' => $p['name'], 'discount' => $discMap[(int) $p['id']] ?? 0.0];
    }
    usort($discTop, fn($a, $b) => $b['discount'] <=> $a['discount']);
    $discTop = array_slice($discTop, 0, 10);

    // 6) 统计卡：当年应收合计 / 付款合计 / 减免合计 / 有数据月份数
    $st = fdb()->prepare("SELECT COUNT(DISTINCT month) c FROM fin_receivable_items WHERE month LIKE ? AND project_id IN ($in) AND amount > 0");
    $st->execute($params);
    $activeMonths = (int) $st->fetchColumn();
    $discountGrand = array_sum($discMap);

    // 7) 项目×月份应收热力矩阵（当年）
    $st = fdb()->prepare("SELECT project_id, month, SUM(amount) total FROM fin_receivable_items WHERE month LIKE ? AND project_id IN ($in) GROUP BY project_id, month");
    $st->execute($params);
    $heatRows = $st->fetchAll();
    $heatMap = [];
    foreach ($heatRows as $r) {
        $heatMap[(int) $r['project_id']][$r['month']] = (float) $r['total'];
    }
    $heatmap = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $vals = [];
        foreach ($months as $m) $vals[$m] = $heatMap[(int) $p['id']][$m] ?? 0.0;
        $heatmap[] = ['name' => $p['name'], 'values' => $vals];
    }

    // 8) 历年应收总额（全历史，scope 过滤；用于年度演化图）
    $histIn = implode(',', array_fill(0, count($scope), '?'));
    $histParams = [];
    foreach ($scope as $p) $histParams[] = $p;
    $st = fdb()->prepare("SELECT LEFT(month,4) y, SUM(amount) total FROM fin_receivable_items WHERE project_id IN ($histIn) GROUP BY y ORDER BY y");
    $st->execute($histParams);
    $histRows = $st->fetchAll();
    $history = [];
    foreach ($histRows as $r) $history[$r['y']] = (float) $r['total'];

    // 9) 付款支付率（已支付/已确认）
    $payRate = $payStatus['confirmed'] > 0 ? round($payStatus['paid'] / $payStatus['confirmed'] * 100, 1) : 0;

    return ['ok' => true, 'year' => $year, 'categories' => $cats, 'payment_types' => $types,
        'cards' => [
            'receivable_grand' => $receivableGrand,
            'payment_grand' => $payStatus['confirmed'],
            'payment_paid' => $payStatus['paid'],
            'payment_rate' => $payRate,
            'discount_grand' => $discountGrand,
            'active_months' => $activeMonths,
        ],
        'category_total' => $catTotal,
        'project_compare' => $projCompare,
        'monthly_trend' => $trend,
        'payment_type_total' => $payTypeTotal,
        'payment_type_paid' => $payTypePaid,
        'payment_status_total' => $payStatus,
        'payment_trend' => $payTrend,
        'discount_top' => $discTop,
        'heatmap' => $heatmap,
        'history' => $history,
    ];
}
