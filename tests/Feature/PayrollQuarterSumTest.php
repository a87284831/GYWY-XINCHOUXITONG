<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 季度绩效法新口径（performance.quarter_sum=true）：
 *   季度绩效 = 季度满勤基数 × 季度出勤率 × 季度系数
 *   季内缺考勤/缺工资行的月份跳过；试用段在 perf_att/perf_req 中同步剔除一次。
 *
 * 全部场景跑真实考勤核算（sqlite :memory:），Q4 2026（10–12 月）在 2027-01 结算。
 */
class PayrollQuarterSumTest extends TestCase
{
    use RefreshDatabase;

    private function seedRules(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true, 'quarter_sum' => true],
            'pay_rules' => [
                'manager' => ['cycle' => 'quarter_grade', 'levels' => [
                    '经理级' => ['mode' => 'quarter'],
                    '主管级' => ['mode' => 'monthly'],
                    '专员级' => ['mode' => 'monthly'],
                ]],
                'hq' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'staff' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'case' => ['cycle' => 'monthly', 'ratio' => 1.0],
            ],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true,
                    'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false,
                    'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
                ['symbol' => '事', 'name' => '事假', 'desc' => '', 'in_required' => true,
                    'in_actual' => false, 'value' => 0.0, 'category' => '事假'],
            ]], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function seedStaff(int $id, string $name, array $opt = []): void
    {
        $fixed = (float)($opt['fixed'] ?? 6000);
        $base = (float)($opt['base'] ?? 5000);
        $hire = (string)($opt['hire'] ?? '2025-01-01');
        $resign = $opt['resign'] ?? null;
        $regular = (string)($opt['regular'] ?? '2025-02-01');
        $history = $opt['history'] ?? [[
            'effective_date' => '2025-01-01', 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'type' => '初始', 'note' => '',
        ]];
        $data = [
            'salary_history' => $history,
            'special_deductions' => [], 'bank_card' => '',
            'regular_date' => $regular, 'resign_date' => $resign ? (string)$resign : '',
            'pay_grade' => (string)($opt['pay_grade'] ?? '经理级'),
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '经理',
            'status' => $resign ? '离职' : '正式', 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => $hire, 'regular_date' => $regular,
            'resign_date' => $resign, 'deleted' => false, 'person_type' => 'manager',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 当月考勤：22 个工作日（首 22 天），其余休息；$special 指定某几天改符号 */
    private function grid(string $ym, array $special = []): array
    {
        $n = (int)date('t', strtotime($ym . '-01'));
        $d = array_fill(0, $n, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        foreach ($special as $day => $sym) $d[(int)$day - 1] = $sym;
        return $d;
    }

    /**
     * 跑某月核算。$entries: name => ['days'=>array,'coef'=>float|null]；
     * 不含某人 = 该月无该员工考勤行（缺月）。
     */
    private function calc(string $ym, array $entries, bool $insert = true): array
    {
        if ($insert) {
            $rows = [];
            foreach ($entries as $name => $e) {
                $rows[$name] = [
                    'days' => $e['days'], 'req_attend' => null, 'act_attend' => null,
                    'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
                    'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
                    'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
                    'welfare' => 0.0, 'coef' => $e['coef'] ?? null, 'remark' => '',
                ];
            }
            DB::table('payroll_attendance')->insert([
                'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
                'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
                'locked' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new PayrollCalculator())->calculateManagers($ym);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', $ym)->where('is_manager_row', true)->get() as $r) {
            $d = json_decode($r->row_data, true);
            $out[$d['name']] = $d;
        }
        return $out;
    }

    /** 模拟弹窗录入季度系数：周期系数表 + 季度末月行/考勤 coef 双写 */
    private function seedCoef(int $staffId, string $key, float $coef, string $month): void
    {
        DB::table('payroll_period_coefs')->updateOrInsert(
            ['staff_legacy_id' => $staffId, 'period_type' => 'quarterly', 'period_key' => $key],
            ['coef' => $coef, 'created_at' => now(), 'updated_at' => now()]
        );
        $att = DB::table('payroll_attendance')->where('year_month', $month)->where('locked', true)->first();
        if ($att) {
            $rows = json_decode((string)$att->rows, true) ?: [];
            foreach ($rows as &$r) {
                if ((int)($r['staff_id'] ?? 0) === $staffId) $r['coef'] = $coef;
            }
            DB::table('payroll_attendance')->where('id', $att->id)
                ->update(['rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function test_eight_scenarios_hand_calc_matches_system(): void
    {
        $this->seedRules();
        // ① 整季在职全勤
        $this->seedStaff(1, '甲全勤');
        // ② 11/1 入职（10 月无工资行）
        $this->seedStaff(2, '乙入职', ['hire' => '2026-11-01', 'regular' => '2026-11-01']);
        // ③ 11/20 离职（12 月无工资行；1 月结算行由 HR 补入）
        $this->seedStaff(3, '丙离职', ['resign' => '2026-11-20']);
        // ④ 10/16 转正（10 月 1–15 日为试用段）
        $this->seedStaff(4, '丁转正', ['regular' => '2026-10-16']);
        // ⑤ 11 月 15 日调薪：6000→7000（基本工资不变）
        $this->seedStaff(5, '戊调薪', ['fixed' => 7000, 'history' => [
            ['effective_date' => '2025-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '初始', 'note' => ''],
            ['effective_date' => '2026-11-15', 'fixed_monthly' => 7000, 'base_salary' => 5000, 'type' => '调薪', 'note' => ''],
        ]]);
        // ⑥ 季末月（12 月）事假 3 天
        $this->seedStaff(6, '己事假');
        // ⑦ 季内只有 11 月有考勤/工资行（10、12 月无）
        $this->seedStaff(7, '庚缺月');
        // ⑧ 逐月系数 0.8/0.9/1.2 不同，季度系数 0.7
        $this->seedStaff(8, '辛系数');

        // 10 月：丙/丁/戊/己/辛 + 甲（乙未入职、庚无数据）
        $this->calc('2026-10', [
            '甲全勤' => ['days' => $this->grid('2026-10')],
            '丙离职' => ['days' => $this->grid('2026-10')],
            '丁转正' => ['days' => $this->grid('2026-10')],
            '戊调薪' => ['days' => $this->grid('2026-10')],
            '己事假' => ['days' => $this->grid('2026-10')],
            '辛系数' => ['days' => $this->grid('2026-10'), 'coef' => 0.8],
        ]);
        // 11 月：全员（乙入职、庚仅此月有数据）
        $this->calc('2026-11', [
            '甲全勤' => ['days' => $this->grid('2026-11')],
            '乙入职' => ['days' => $this->grid('2026-11')],
            '丙离职' => ['days' => $this->grid('2026-11')],
            '丁转正' => ['days' => $this->grid('2026-11')],
            '戊调薪' => ['days' => $this->grid('2026-11')],
            '己事假' => ['days' => $this->grid('2026-11')],
            '庚缺月' => ['days' => $this->grid('2026-11')],
            '辛系数' => ['days' => $this->grid('2026-11'), 'coef' => 0.9],
        ]);
        // 12 月：丙已离职无行、庚无数据；己事假 3 天
        $this->calc('2026-12', [
            '甲全勤' => ['days' => $this->grid('2026-12')],
            '乙入职' => ['days' => $this->grid('2026-12')],
            '丁转正' => ['days' => $this->grid('2026-12')],
            '戊调薪' => ['days' => $this->grid('2026-12')],
            '己事假' => ['days' => $this->grid('2026-12', [20 => '事', 21 => '事', 22 => '事'])],
            '辛系数' => ['days' => $this->grid('2026-12'), 'coef' => 1.2],
        ]);

        // 2027-01 结算：录入 Q4 系数（辛=0.7，其余 1.0），HR 为离职的丙保留结算考勤行
        $jan = [
            '甲全勤' => ['days' => $this->grid('2027-01')],
            '乙入职' => ['days' => $this->grid('2027-01')],
            '丙离职' => ['days' => $this->grid('2027-01')],
            '丁转正' => ['days' => $this->grid('2027-01')],
            '戊调薪' => ['days' => $this->grid('2027-01')],
            '己事假' => ['days' => $this->grid('2027-01')],
            '庚缺月' => ['days' => $this->grid('2027-01')],
            '辛系数' => ['days' => $this->grid('2027-01')],
        ];
        $this->calc('2027-01', $jan);
        foreach ([1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 1.0, 7 => 1.0, 8 => 0.7] as $id => $coef) {
            $this->seedCoef($id, '2026-Q4', $coef, '2027-01');
        }
        $settle = $this->calc('2027-01', $jan, false);

        // ===== 手算 vs 系统 =====
        // ① 3000 × 66/66 × 1.0 = 3000.00
        $this->assertQuarter($settle['甲全勤'], 3000.0, 66, 66, 1.0);
        // ② 10 月缺行跳过；11+12 月基数 2000 × 44/44 × 1.0 = 2000.00
        $this->assertQuarter($settle['乙入职'], 2000.0, 44, 44, 1.0);
        // ③ 10 月 1000 + 11 月 1000×20/30=666.67；12 月缺行跳过；1666.67 × 44/44 = 1666.67
        $this->assertQuarter($settle['丙离职'], 1666.67, 44, 44, 1.0);
        // ④ 试用段只剔一次，且试用期不产生绩效基数：10 月基数 1000×16/31=516.13，
        //    perf_att/perf_req 同为 7（16–22 日），11/12 各 22；Σ基数 2516.13 × 51/51 = 2516.13
        $this->assertQuarter($settle['丁转正'], 2516.13, 51, 51, 1.0);
        $oct = $this->row('2026-10', '丁转正');
        $this->assertEquals(7.0, (float)$oct['perf_att']);
        $this->assertEquals(7.0, (float)$oct['perf_req']);
        $this->assertEquals(516.13, (float)$oct['perf_base_month'], '试用段不得计入当月绩效基数');
        // ⑤ 10 月 1000 + 11 月 (1000×14/30 + 2000×16/30)=1533.33 + 12 月 2000 = 4533.33 → 4533.33
        $this->assertQuarter($settle['戊调薪'], 4533.33, 66, 66, 1.0);
        // ⑥ 12 月事假 3 天：re 22 att 19；Σ=63/66 → 3000×0.954545=2863.64
        $this->assertQuarter($settle['己事假'], 2863.64, 63, 66, 1.0);
        // ⑦ 只按有数据的 11 月算：1000 × 22/22 = 1000
        $this->assertQuarter($settle['庚缺月'], 1000.0, 22, 22, 1.0);
        // ⑧ 只认季度系数 0.7：3000 × 66/66 × 0.7 = 2100（不受 0.8/0.9/1.2 影响）
        $this->assertQuarter($settle['辛系数'], 2100.0, 66, 66, 0.7);

        // 非季末月主表绩效为 0，且季末月不能留空
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->assertEquals(0.0, (float)$this->row($m, '甲全勤')['perf_pay'], "{$m} 季度中月绩效应为 0");
        }
        // 季度绩效法不再写逐月明细
        foreach ($settle as $name => $r) {
            $this->assertNull($r['perf_detail'] ?? null, "{$name} 不应再写 perf_detail");
        }
    }

    public function test_quarter_end_adjust_recomputes_by_quarter_formula(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '甲全勤');
        // 季内三月按月核算
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->calc($m, ['甲全勤' => ['days' => $this->grid($m)]]);
        }
        // 2027-01 结算
        $jan = ['甲全勤' => ['days' => $this->grid('2027-01')]];
        $this->calc('2027-01', $jan);
        $this->seedCoef(1, '2026-Q4', 1.0, '2027-01');
        $this->calc('2027-01', $jan, false);

        $accId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = str_repeat('t', 64);
        \Illuminate\Support\Facades\Cache::put('payroll_api_token:' . $token, $accId, now()->addHours(8));

        // 系数 1.0 → 1.2：季度额 3000 × 66/66 × 1.2 = 3600（不是单月公式的 1000×22/22×1.2）
        $this->postJson('/api/payroll/adjust', [
            'ym' => '2027-01', 'staff_id' => 1, 'fields' => ['coef' => 1.2], 'reason' => '季度系数调整',
        ], ['X-Token' => $token])->assertOk();
        $row = $this->row('2027-01', '甲全勤');
        $this->assertEquals(3600.0, (float)$row['perf_pay']);
        $this->assertEquals(1.2, (float)$row['perf_quarter']['coef']);
        $this->assertEquals(3600.0, (float)$row['perf_quarter']['amount']);
        $this->assertEquals(3000.0, (float)$row['perf_quarter']['base']);

        // 季末月 perf_att 22→20：差额并入季度出勤 66→64，3000×64/66×1.2 = 3490.91
        $this->postJson('/api/payroll/adjust', [
            'ym' => '2027-01', 'staff_id' => 1, 'fields' => ['perf_att' => 20], 'reason' => '出勤修正',
        ], ['X-Token' => $token])->assertOk();
        $row = $this->row('2027-01', '甲全勤');
        $this->assertEquals(3490.91, (float)$row['perf_pay']);
        $this->assertEquals(64.0, (float)$row['perf_quarter']['att']);
        $this->assertEquals(3490.91, (float)$row['perf_quarter']['amount']);
    }

    /** 整季试用：季度绩效必须为 0，且每月都不产生绩效基数 */
    public function test_all_probation_quarter_pays_zero(): void
    {
        $this->seedRules();
        $this->seedStaff(11, '试用整季', ['hire' => '2026-10-01', 'regular' => '2027-02-01']);
        $monthsAtd = [];
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $monthsAtd[$m] = ['days' => $this->grid($m)];
            $this->calc($m, ['试用整季' => $monthsAtd[$m]]);
        }
        $jan = ['试用整季' => ['days' => $this->grid('2027-01')]];
        $this->calc('2027-01', $jan);
        $this->seedCoef(11, '2026-Q4', 1.0, '2027-01');
        $settle = $this->calc('2027-01', $jan, false);

        $r = $settle['试用整季'];
        $this->assertEquals(0.0, (float)$r['perf_pay']);
        $this->assertEquals(0.0, (float)$r['perf_quarter']['base']);
        $this->assertEquals(0.0, (float)$r['perf_quarter']['req']);
        $this->assertSame([], $r['perf_quarter']['months'], '整季试用无有效月度基数');
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $row = $this->row($m, '试用整季');
            $this->assertEquals(0.0, (float)$row['perf_base_month'], "{$m} 试用期不得产生绩效基数");
            $this->assertEquals(0.0, (float)$row['perf_req'], "{$m} 试用期不应有绩效应出勤");
        }
    }

    /** 1 个整月试用 + 2 个正式月：试用月整月跳过，不计入 Σ基数 */
    public function test_full_probation_month_excluded_from_quarter_base(): void
    {
        $this->seedRules();
        $this->seedStaff(12, '试用一月', ['hire' => '2026-10-01', 'regular' => '2026-11-01']);
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->calc($m, ['试用一月' => ['days' => $this->grid($m)]]);
        }
        $jan = ['试用一月' => ['days' => $this->grid('2027-01')]];
        $this->calc('2027-01', $jan);
        $this->seedCoef(12, '2026-Q4', 1.0, '2027-01');
        $settle = $this->calc('2027-01', $jan, false);

        // 10 月 base/req 均为 0 → 整月跳过；11+12 月基数 2000 × 44/44 × 1.0 = 2000
        $this->assertQuarter($settle['试用一月'], 2000.0, 44, 44, 1.0);
        $this->assertEquals(0.0, (float)$this->row('2026-10', '试用一月')['perf_base_month']);
        $this->assertCount(2, $settle['试用一月']['perf_quarter']['months']);
    }

    /** 旧月份缺 perf_req/perf_base_month：整月试用必须按转正日重算为 0，不能回退 req_att */
    public function test_legacy_month_without_perf_keys_skips_full_probation(): void
    {
        $this->seedRules();
        $this->seedStaff(13, '旧行试用', ['hire' => '2026-10-01', 'regular' => '2026-11-01']);
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->calc($m, ['旧行试用' => ['days' => $this->grid($m)]]);
        }
        // 模拟升级前落库行：删掉两个新键
        $this->stripPerfKeys(13, '2026-10');

        $jan = ['旧行试用' => ['days' => $this->grid('2027-01')]];
        $this->calc('2027-01', $jan);
        $this->seedCoef(13, '2026-Q4', 1.0, '2027-01');
        $settle = $this->calc('2027-01', $jan, false);

        // 若仍用 req_att 回退，10 月会多算 1000 基数 / 22 应出勤 → 3000，本断言可拦住
        $this->assertQuarter($settle['旧行试用'], 2000.0, 44, 44, 1.0);
    }

    /** 旧月份缺新键且月中转正：基数按非试用在职天数占整月天数折算 */
    public function test_legacy_partial_probation_month_prorates_base(): void
    {
        $this->seedRules();
        $this->seedStaff(14, '旧行月中转正', ['hire' => '2026-10-01', 'regular' => '2026-10-16']);
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->calc($m, ['旧行月中转正' => ['days' => $this->grid($m)]]);
        }
        $this->stripPerfKeys(14, '2026-10');

        $jan = ['旧行月中转正' => ['days' => $this->grid('2027-01')]];
        $this->calc('2027-01', $jan);
        $this->seedCoef(14, '2026-Q4', 1.0, '2027-01');
        $settle = $this->calc('2027-01', $jan, false);

        $r = $settle['旧行月中转正'];
        // 10 月：base 1000×16/31=516.13；req 回退按 22×16/31=11.35（无考勤格子可用，按日历天折算）
        $this->assertEquals(516.13, (float)$r['perf_quarter']['months'][0]['base']);
        $this->assertEquals(11.35, (float)$r['perf_quarter']['months'][0]['perf_req']);
        $this->assertEquals(2516.13, (float)$r['perf_quarter']['base']);
        $this->assertEquals(2318.39, (float)$r['perf_pay']);
    }

    /** 删除某月工资行里的 perf_req/perf_base_month，模拟升级前落库的历史行 */
    private function stripPerfKeys(int $staffId, string $ym): void
    {
        $row = DB::table('payroll_results')
            ->where('year_month', $ym)->where('staff_legacy_id', $staffId)->where('is_manager_row', true)->first();
        $this->assertNotNull($row, "缺少 {$ym} 的工资行");
        $d = json_decode((string)$row->row_data, true) ?: [];
        unset($d['perf_req'], $d['perf_base_month']);
        DB::table('payroll_results')->where('id', $row->id)
            ->update(['row_data' => json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }

    private function assertQuarter(array $row, float $amount, float $att, float $req, float $coef): void
    {
        $this->assertNotNull($row['perf_quarter'] ?? null);
        $this->assertEquals($amount, (float)$row['perf_pay']);
        $this->assertEquals($amount, (float)$row['perf_quarter']['amount']);
        $this->assertEquals($att, (float)$row['perf_quarter']['att']);
        $this->assertEquals($req, (float)$row['perf_quarter']['req']);
        $this->assertEquals($coef, (float)$row['perf_quarter']['coef']);
        $this->assertEquals(round($att / $req, 4), (float)$row['perf_quarter']['ratio']);
        $this->assertEquals($amount, round((float)$row['perf_quarter']['base'] * $att / $req * $coef, 2));
    }

    private function row(string $ym, string $name): array
    {
        $r = DB::table('payroll_results')->where('year_month', $ym)->where('is_manager_row', true)->get()
            ->first(fn ($x) => (json_decode($x->row_data, true)['name'] ?? '') === $name);
        return $r ? json_decode($r->row_data, true) : [];
    }
}
