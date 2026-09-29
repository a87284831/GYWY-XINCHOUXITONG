<?php
/**
 * 演示数据：为管理人员随机生成 2026-01 ~ 2026-09 工资行（payroll_results）。
 * 行结构与历史导入完全一致（imported_history=true），并带 demo_seed 标记便于一键清理：
 *   清理：php database/seed_demo_payroll.php --clean
 * 仅操作本地 local.sqlite，禁止对生产使用。
 */
$db = new PDO('sqlite:' . __DIR__ . '/local.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (in_array('--clean', $argv, true)) {
    $n = $db->exec("DELETE FROM payroll_results WHERE json_extract(row_data,'$.demo_seed') = 1");
    echo "已清理演示数据 {$n} 行\n";
    exit;
}

$managers = $db->query(
    "SELECT legacy_id, name, project_name, position, dept_path, status, fixed_monthly, base_salary, hire_date
     FROM payroll_staff WHERE person_type='manager' AND deleted=0"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$managers) { echo "无管理人员档案，退出\n"; exit; }
echo '管理人员 ', count($managers), " 人\n";

$months = ['2026-01','2026-02','2026-03','2026-04','2026-05','2026-06','2026-07','2026-08','2026-09'];
// 每月应出勤基准（粗略工作日）
$reqAtt = ['2026-01'=>22,'2026-02'=>17,'2026-03'=>22,'2026-04'=>21,'2026-05'=>20,'2026-06'=>22,'2026-07'=>23,'2026-08'=>21,'2026-09'=>22];
mt_srand(20260929); // 固定种子：可复现

$inserted = 0;
$db->beginTransaction();
$stmt = $db->prepare(
    "INSERT INTO payroll_results (year_month, staff_legacy_id, project_name, is_manager_row, is_case_row, is_hq_row, archived, row_data, created_at, updated_at)
     VALUES (:ym, :sid, :proj, 1, 0, 0, 1, :row, datetime('now'), datetime('now'))"
);

foreach ($managers as $m) {
    $fixed = (float) $m['fixed_monthly'];
    if ($fixed <= 0) $fixed = max(3000, (float) $m['base_salary'] * 1.4);
    $basePart = round($fixed * 0.7, 2);      // 基本部分
    $perfPart = round($fixed - $basePart, 2); // 绩效部分
    $hire = $m['hire_date'] ?: '';

    foreach ($months as $mi => $ym) {
        if ($hire && $ym < substr($hire, 0, 7)) continue; // 入职前跳过
        $req = $reqAtt[$ym];
        $act = $req - (mt_rand(0, 10) < 3 ? mt_rand(1, 2) : 0); // 偶尔缺勤 1-2 天
        $coef = mt_rand(90, 110) / 100;
        $scale = $act / max($req, 1);
        $basePay = round($basePart * $scale, 2);
        $perfPay = round($perfPart * $scale * $coef, 2);
        $sickPay = 0; $night = 0; $meal = round(mt_rand(0, 8) * 15, 2);
        $titleSub = 0; $reward = mt_rand(0, 10) < 2 ? mt_rand(2, 6) * 100 : 0;
        $welfare = 0; $punish = 0;
        $lateD = mt_rand(0, 10) < 2 ? mt_rand(1, 3) * 30 : 0;
        $missD = 0; $otherD = 0; $uniformD = 0;
        $gross = round($basePay + $perfPay + $sickPay + $night + $meal + $titleSub + $reward + $welfare - $punish - $missD - $lateD - $otherD - $uniformD, 2);
        $socBase = $basePay + $perfPay;
        $pen = round($socBase * 0.08, 2); $med = round($socBase * 0.02, 2); $une = round($socBase * 0.005, 2);
        $house = round($socBase * 0.12, 2); $big = 0;
        $socTotal = round($pen + $med + $une + $house + $big, 2);
        // 简化个税：对（应发-5000-五险一金）按月度速算粗算（演示口径）
        $taxable = max(0, $gross - 5000 - $socTotal);
        $tax = round($taxable * 0.03, 2);
        $net = round($gross - $socTotal - $tax, 2);

        $row = [
            'staff_id' => (int) $m['legacy_id'], 'name' => $m['name'], 'project' => $m['project_name'],
            'department' => $m['dept_path'] ?: $m['project_name'], 'position' => $m['position'] ?: '',
            'status' => $m['status'] ?: '正式', 'fixed' => $fixed, 'base' => $basePart,
            'req_att' => $req, 'act_att' => $act, 'perf_att' => $act, 'coef' => $coef,
            'base_pay' => $basePay, 'perf_pay' => $perfPay, 'sick_days' => 0, 'sick_pay' => $sickPay,
            'night' => $night, 'meal' => $meal, 'title_sub' => $titleSub, 'reward' => $reward,
            'welfare' => $welfare, 'punish' => $punish, 'late_d' => $lateD, 'miss_d' => $missD,
            'other_d' => $otherD, 'uniform_d' => $uniformD, 'gross' => $gross,
            'pen' => $pen, 'med' => $med, 'une' => $une, 'house' => $house, 'big' => $big,
            'soc_total' => $socTotal, 'spec_total' => 0, 'actual_tax' => $tax, 'net' => $net,
            'remark' => '演示数据', 'withhold' => 0, 'imported_history' => true, 'demo_seed' => 1,
        ];

        // 季度末月造 perf_detail（季度绩效横向明细列的展示数据）
        $perfDetail = null;
        if ($ym === '2026-04') {
            $perfDetail = ['period' => '2026-Q1', 'type' => 'quarterly', 'coef' => 1.0, 'ratio' => 1.0, 'base' => 0, 'amount' => 0, 'months' => []];
            foreach (['2026-01' => 22, '2026-02' => 17, '2026-03' => 22] as $qym => $qReq) {
                $amt = round($perfPart * $qReq / max($qReq, 1), 2);
                $perfDetail['months'][] = ['ym' => $qym, 'perf_att' => $qReq, 'base' => $perfPart, 'amount' => $amt];
                $perfDetail['base'] += $perfPart; $perfDetail['amount'] += $amt;
            }
        } elseif ($ym === '2026-07') {
            $perfDetail = ['period' => '2026-Q2', 'type' => 'quarterly', 'coef' => 1.0, 'ratio' => 1.0, 'base' => 0, 'amount' => 0, 'months' => [],
                'half_year' => ['period' => '2026-H1', 'coef' => 1.0, 'ratio' => 0.1, 'base' => 0, 'amount' => 0, 'months' => []]];
            foreach (['2026-04' => 21, '2026-05' => 20, '2026-06' => 22] as $qym => $qReq) {
                $perfDetail['months'][] = ['ym' => $qym, 'perf_att' => $qReq, 'base' => $perfPart, 'amount' => round($perfPart, 2)];
                $perfDetail['base'] += $perfPart; $perfDetail['amount'] += round($perfPart, 2);
            }
            foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $qym) {
                $amt = round($perfPart * 0.1 / 6, 2);
                $perfDetail['half_year']['months'][] = ['ym' => $qym, 'perf_att' => $reqAtt[$qym], 'base' => $perfPart, 'amount' => $amt];
                $perfDetail['half_year']['base'] += $perfPart; $perfDetail['half_year']['amount'] += $amt;
            }
        }
        if ($perfDetail) $row['perf_detail'] = $perfDetail;

        $stmt->execute([
            ':ym' => $ym, ':sid' => $m['legacy_id'], ':proj' => $m['project_name'],
            ':row' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
        $inserted++;
    }
}
$db->commit();
echo "已生成演示行 {$inserted} 条（管理人员 2026-01 ~ 2026-09）\n";
echo "清理命令：php database/seed_demo_payroll.php --clean\n";
