<?php
/** 财务管理 - 汇总 handlers（年度汇总/项目汇总/减免汇总/付款汇总）
 *  year 参数支持 "all"（截至目前全部年度）或具体年份
 */

use App\Finance\FinanceStop;
use App\Finance\Support;

/** 年度汇总：某年 月份×类别（全项目或单项目） */
function handle_summary_annual(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    [$all, $year] = fin_parse_year();
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $scope = fin_accessible_projects();
    if ($projectId > 0) {
        fin_assert_project_access($projectId);
        $scope = [$projectId];
    }

    $cats = fin_receivable_categories();
    $months = $all ? fin_all_months() : fin_year_months($year);
    $in = implode(',', array_fill(0, count($scope), '?'));
    $params = [];
    if (!$all) $params[] = $year . '-%';
    foreach ($scope as $p) $params[] = $p;

    $sql = "SELECT month, category, SUM(amount) total FROM fin_receivable_items WHERE ";
    $sql .= $all ? "1=1" : "month LIKE ?";
    $sql .= " AND project_id IN ($in) GROUP BY month, category";
    $st = fdb()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $grid = [];
    $monthTotal = [];
    foreach ($months as $m) {
        $grid[$m] = [];
        foreach (array_keys($cats) as $k) $grid[$m][$k] = 0.0;
        $monthTotal[$m] = 0.0;
    }
    foreach ($rows as $r) {
        if (isset($grid[$r['month']][$r['category']])) {
            $grid[$r['month']][$r['category']] = (float) $r['total'];
            $monthTotal[$r['month']] += (float) $r['total'];
        }
    }
    $grand = array_sum($monthTotal);
    return ['ok' => true, 'year' => $all ? 'all' : (int) $year, 'categories' => $cats, 'months' => $months,
        'grid' => $grid, 'month_total' => $monthTotal, 'grand_total' => $grand];
}

/** 项目汇总：某年 项目×类别（减免赠送金额取自月级状态表） */
function handle_summary_projects(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    [$all, $year] = fin_parse_year();
    $scope = fin_accessible_projects();

    $cats = fin_receivable_categories();
    $in = implode(',', array_fill(0, count($scope), '?'));
    $params = [];
    if (!$all) $params[] = $year . '-%';
    foreach ($scope as $p) $params[] = $p;

    $sql = "SELECT project_id, category, SUM(amount) total
         FROM fin_receivable_items WHERE ";
    $sql .= $all ? "1=1" : "month LIKE ?";
    $sql .= " AND project_id IN ($in) GROUP BY project_id, category";
    $st = fdb()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // 减免赠送金额（月级状态表）
    $sql2 = "SELECT project_id, SUM(IFNULL(discount_amount,0)) d FROM fin_receivable_month_status WHERE ";
    $sql2 .= $all ? "1=1" : "month LIKE ?";
    $sql2 .= " AND project_id IN ($in) GROUP BY project_id";
    $st2 = fdb()->prepare($sql2);
    $st2->execute($params);
    $discByProj = [];
    foreach ($st2->fetchAll() as $r) $discByProj[(int) $r['project_id']] = (float) $r['d'];

    $byProj = [];
    foreach ($rows as $r) {
        $pid = (int) $r['project_id'];
        if (!isset($byProj[$pid])) {
            $byProj[$pid] = ['total' => 0.0, 'categories' => []];
            foreach (array_keys($cats) as $k) $byProj[$pid]['categories'][$k] = 0.0;
        }
        $byProj[$pid]['categories'][$r['category']] = (float) $r['total'];
        $byProj[$pid]['total'] += (float) $r['total'];
    }

    $projects = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $d = $byProj[(int) $p['id']] ?? ['total' => 0.0,
            'categories' => array_fill_keys(array_keys($cats), 0.0)];
        $projects[] = ['project_id' => (int) $p['id'], 'name' => $p['name'], 'total' => $d['total'],
            'discount_total' => $discByProj[(int) $p['id']] ?? 0.0, 'categories' => $d['categories']];
    }
    return ['ok' => true, 'year' => $all ? 'all' : (int) $year, 'categories' => $cats, 'projects' => $projects];
}

/** 减免优惠汇总：某年 月份×项目（减免赠送金额，月级状态表） */
function handle_summary_discount(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    [$all, $year] = fin_parse_year();
    $scope = fin_accessible_projects();

    $months = $all ? fin_all_months() : fin_year_months($year);
    $in = implode(',', array_fill(0, count($scope), '?'));
    $params = [];
    if (!$all) $params[] = $year . '-%';
    foreach ($scope as $p) $params[] = $p;

    $sql = "SELECT project_id, month, SUM(IFNULL(discount_amount,0)) d
         FROM fin_receivable_month_status WHERE ";
    $sql .= $all ? "1=1" : "month LIKE ?";
    $sql .= " AND project_id IN ($in) AND discount_amount IS NOT NULL GROUP BY project_id, month";
    $st = fdb()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $projects = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $projects[] = ['project_id' => (int) $p['id'], 'name' => $p['name']];
    }
    $grid = [];
    foreach ($projects as $pr) {
        $grid[$pr['project_id']] = array_fill_keys($months, 0.0);
    }
    foreach ($rows as $r) {
        if (isset($grid[(int) $r['project_id']][$r['month']])) {
            $grid[(int) $r['project_id']][$r['month']] = (float) $r['d'];
        }
    }
    return ['ok' => true, 'year' => $all ? 'all' : (int) $year, 'months' => $months, 'projects' => $projects, 'grid' => $grid];
}

/** 付款记录汇总：某年 项目×类型（可按状态筛选：confirmed/paid/unpaid） */
function handle_summary_payments(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    [$all, $year] = fin_parse_year();
    $scope = fin_accessible_projects();
    $status = (string) ($_GET['status'] ?? '');
    $statuses = fin_payment_statuses();
    if ($status !== '' && !isset($statuses[$status])) {
        throw new FinanceStop(['ok' => false, 'msg' => '状态不合法'], 400);
    }

    $types = fin_payment_types();
    $in = implode(',', array_fill(0, count($scope), '?'));
    $params = [];
    // 付款 month 语义为年度：新结构存 '2026'，旧数据存 '2026-12'，两种都匹配
    if (!$all) {
        $params[] = $year . '-%';
        $params[] = (string) $year;
    }
    foreach ($scope as $p) $params[] = $p;

    // 未支付为自动计算（已确认−已支付），DB 仅存 confirmed/paid
    $sql = "SELECT project_id, status, type, SUM(amount) total FROM fin_payment_items WHERE ";
    $sql .= $all ? "1=1" : "(month LIKE ? OR month = ?)";
    $sql .= " AND project_id IN ($in) GROUP BY project_id, status, type";
    $st = fdb()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $byProj = [];
    foreach ($rows as $r) {
        $pid = (int) $r['project_id'];
        if (!isset($byProj[$pid])) {
            $byProj[$pid] = ['confirmed' => array_fill_keys(array_keys($types), 0.0), 'paid' => array_fill_keys(array_keys($types), 0.0)];
        }
        $byProj[$pid][$r['status']][$r['type']] = (float) $r['total'];
    }

    $projects = [];
    foreach (fin_projects() as $p) {
        if (!in_array((int) $p['id'], $scope, true)) continue;
        $d = $byProj[(int) $p['id']] ?? null;
        $typesArr = [];
        $total = 0.0;
        foreach (array_keys($types) as $tk) {
            if ($status === 'unpaid') {
                $amt = round((float) ($d['confirmed'][$tk] ?? 0) - (float) ($d['paid'][$tk] ?? 0), 2);
                if ($amt < 0) $amt = 0;
            } elseif ($status === 'confirmed' || $status === 'paid') {
                $amt = (float) ($d[$status][$tk] ?? 0);
            } else {
                // 全量（默认）：已确认数据口径
                $amt = (float) ($d['confirmed'][$tk] ?? 0);
            }
            $typesArr[$tk] = $amt;
            $total += $amt;
        }
        $projects[] = ['project_id' => (int) $p['id'], 'name' => $p['name'], 'total' => $total, 'types' => $typesArr];
    }
    return ['ok' => true, 'year' => $all ? 'all' : (int) $year, 'types' => $types, 'statuses' => $statuses,
        'status' => $status, 'projects' => $projects];
}
