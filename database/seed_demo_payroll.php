<?php
/**
 * 演示数据：为管理人员随机生成 2026-01 ~ 2026-09 工资行（payroll_results）。
 * 绩效口径与 PayrollCalculator::calculateManagers 完全一致：
 *   - 季度中月份（1/2/3/5/6/8/9）不发绩效，perf_pay=0（当月绩效基数累计到季度末）
 *   - 4 月发 Q1（1-3 月逐月绩效基数合计 × 季度系数 × 档位季度比例）
 *   - 7 月发 Q2（4-6 月）+ H1 半年度（1-6 月 × 半年系数 × 半年比例），两笔分开
 *   - 1 月本应发上年 Q4/H2，演示无上年数据，perf_pay=0
 * 行结构带 demo_seed 标记便于一键清理：
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
// 季度末发放规则：发放月 => [周期, 季度成员月, 季度比例]；半年度在 7 月追加
$quarterRules = [
    '2026-04' => ['period' => '2026-Q1', 'members' => ['2026-01','2026-02','2026-03'], 'ratio' => 1.0],
    '2026-07' => ['period' => '2026-Q2', 'members' => ['2026-04','2026-05','2026-06'], 'ratio' => 1.0],
];
$halfRule = ['2026-07' => ['period' => '2026-H1', 'members' => ['2026-01','2026-02','2026-03','2026-04','2026-05','2026-06'], 'ratio' => 0.1]];
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
    $basePart = round($fixed * 0.7, 2);       // 基本部分
    $perfPart = round($fixed - $basePart, 2); // 绩效部分（满勤月基数）
    $hire = $m['hire_date'] ?: '';

    // 第一趟：生成各在职月基础数据。perfEarned = 当月产生但累计到季度末才发的绩效基数
    $md = [];
    foreach ($months as $ym) {
        if ($hire && $ym < substr($hire, 0, 7)) continue; // 入职前跳过
        $req = $reqAtt[$ym];
        $act = $req - (mt_rand(0, 10) < 3 ? mt_rand(1, 2) : 0); // 偶尔缺勤 1-2 天
        $coef = mt_rand(90, 110) / 100;
        $scale = $act / max($req, 1);
        $basePay = round($basePart * $scale, 2);
        $perfEarned = round($perfPart * $scale * $coef, 2); // 当月绩效基数（非发放月不计入当月工资）
        $md[$ym] = [
            'req' => $req, 'act' => $act, 'coef' => $coef,
            'basePay' => $basePay, 'perfEarned' => $perfEarned,
            'meal' => round(mt_rand(0, 8) * 15, 2),
            'reward' => mt_rand(0, 10) < 2 ? mt_rand(2, 6) * 100 : 0,
            'lateD' => mt_rand(0, 10) < 2 ? mt_rand(1, 3) * 30 : 0,
        ];
    }

    // 第二趟：按发放规则确定每月 perf_pay / perf_detail，再派生应发、社保、个税、实发
    foreach ($md as $ym => $d) {
        $perfPay = 0.0;
        $perfDetail = null;

        if (isset($quarterRules[$ym])) {
            $rule = $quarterRules[$ym];
            $qMonths = []; $qAmount = 0.0; $qBase = 0.0;
            foreach ($rule['members'] as $mem) {
                if (!isset($md[$mem])) continue; // 季度内尚未入职的月份不计
                $earned = $md[$mem]['perfEarned'];
                $qMonths[] = ['ym' => $mem, 'perf_att' => $md[$mem]['act'], 'base' => $perfPart, 'amount' => $earned];
                $qAmount += $earned; $qBase += $perfPart;
            }
            $perfPay = round($qAmount * $rule['ratio'], 2); // 季度系数演示取 1.0
            $perfDetail = [
                'period' => $rule['period'], 'type' => 'quarterly',
                'coef' => 1.0, 'ratio' => $rule['ratio'],
                'base' => round($qBase, 2), 'amount' => $perfPay, 'months' => $qMonths,
            ];
            // 7 月追加半年度 H1（与季度部分分开累计）
            if (isset($halfRule[$ym])) {
                $hr = $halfRule[$ym];
                $hMonths = []; $hAmount = 0.0; $hBase = 0.0;
                foreach ($hr['members'] as $mem) {
                    if (!isset($md[$mem])) continue;
                    $earned = $md[$mem]['perfEarned'];
                    $hMonths[] = ['ym' => $mem, 'perf_att' => $md[$mem]['act'], 'base' => $perfPart, 'amount' => $earned];
                    $hAmount += $earned; $hBase += $perfPart;
                }
                $hPay = round($hAmount * $hr['ratio'], 2); // 半年系数演示取 1.0
                $perfPay = round($perfPay + $hPay, 2);
                $perfDetail['half_year'] = [
                    'period' => $hr['period'], 'coef' => 1.0, 'ratio' => $hr['ratio'],
                    'base' => round($hBase, 2), 'amount' => $hPay, 'months' => $hMonths,
                ];
            }
        }

        $basePay = $d['basePay'];
        $gross = round($basePay + $perfPay + $d['meal'] + $d['reward'] - $d['lateD'], 2);
        // 社保按固定月度基数（不随季度末大额绩效跳变），更贴近真实工资表
        $pen = round($fixed * 0.08, 2); $med = round($fixed * 0.02, 2); $une = round($fixed * 0.005, 2);
        $house = round($fixed * 0.12, 2); $big = 0.0;
        $socTotal = round($pen + $med + $une + $house + $big, 2);
        $taxable = max(0, $gross - 5000 - $socTotal);
        $tax = round($taxable * 0.03, 2);
        $net = round($gross - $socTotal - $tax, 2);

        $row = [
            'staff_id' => (int) $m['legacy_id'], 'name' => $m['name'], 'project' => $m['project_name'],
            'department' => $m['dept_path'] ?: $m['project_name'], 'position' => $m['position'] ?: '',
            'status' => $m['status'] ?: '正式', 'fixed' => $fixed, 'base' => $basePart,
            'req_att' => $d['req'], 'act_att' => $d['act'], 'perf_att' => $d['act'], 'coef' => $d['coef'],
            'base_pay' => $basePay, 'perf_pay' => $perfPay, 'sick_days' => 0, 'sick_pay' => 0,
            'night' => 0, 'meal' => $d['meal'], 'title_sub' => 0, 'reward' => $d['reward'],
            'welfare' => 0, 'punish' => 0, 'late_d' => $d['lateD'], 'miss_d' => 0,
            'other_d' => 0, 'uniform_d' => 0, 'gross' => $gross,
            'pen' => $pen, 'med' => $med, 'une' => $une, 'house' => $house, 'big' => $big,
            'soc_total' => $socTotal, 'spec_total' => 0, 'actual_tax' => $tax, 'net' => $net,
            'remark' => '演示数据', 'withhold' => 0, 'imported_history' => true, 'demo_seed' => 1,
        ];
        if ($perfDetail) $row['perf_detail'] = $perfDetail;

        $stmt->execute([
            ':ym' => $ym, ':sid' => $m['legacy_id'], ':proj' => $m['project_name'],
            ':row' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
        $inserted++;
    }
}
$db->commit();
echo "已生成演示行 {$inserted} 条（管理人员 2026-01 ~ 2026-09，季度末月发放绩效）\n";
echo "清理命令：php database/seed_demo_payroll.php --clean\n";
