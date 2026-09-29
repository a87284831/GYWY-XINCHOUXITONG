<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 季度绩效法（cycle=quarter_grade）测试：
 * 经理级=季度型（季度中0，季度末按 季度系数×Σ(基数×出勤比例) 全额发放，无比例、无半年度）；
 * 主管级/专员级=月度型（每月 基数×出勤比例×当月系数，不查季度系数、不进季度累计）。
 */
class PayrollQuarterGradeTest extends TestCase
{
    use RefreshDatabase;

    private function seedRules(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'pay_rules' => [
                'manager' => [
                    'cycle' => 'quarter_grade',
                    'levels' => [
                        '经理级' => ['mode' => 'quarter'],
                        '主管级' => ['mode' => 'monthly'],
                        '专员级' => ['mode' => 'monthly'],
                    ],
                ],
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
            ['payload' => json_encode(['items' => [[
                'symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true,
                'in_actual' => true, 'value' => 1.0, 'category' => '正常',
            ], [
                'symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false,
                'in_actual' => false, 'value' => 0.0, 'category' => '公休',
            ]]], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function seedManager(int $id, string $name, string $level = '经理级', string $hire = '2025-01-01'): void
    {
        $data = [
            'salary_history' => [[
                'effective_date' => '2025-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000,
                'type' => '初始', 'note' => '',
            ]],
            'special_deductions' => [],
            'bank_card' => '',
            'regular_date' => '2025-02-01',
            'resign_date' => '',
            'pay_grade' => $level,
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '经理',
            'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => $hire, 'regular_date' => '2025-02-01',
            'resign_date' => null, 'deleted' => false, 'person_type' => 'manager',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 历史核算行：fixed=6000 base=5000 → 月绩效基数 1000 */
    private function seedHistory(int $staffId, string $ym, float $perfAtt = 22, float $reqAtt = 22): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => '测试项目',
            'row_data' => json_encode(['fixed' => 6000, 'base' => 5000,
                'perf_att' => $perfAtt, 'req_att' => $reqAtt], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => true, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedCoef(int $staffId, string $type, string $key, float $coef): void
    {
        DB::table('payroll_period_coefs')->insert([
            'staff_legacy_id' => $staffId, 'period_type' => $type, 'period_key' => $key,
            'coef' => $coef, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fullDays(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        return $d;
    }

    /** 跑管理人员核算；可注入多名人员考勤与当月 coef，返回按姓名索引的 row_data */
    private function calcManagers(string $ym, array $names, ?float $coef = null): array
    {
        $rows = [];
        foreach ($names as $name) {
            $rows[$name] = [
                'days' => $this->fullDays(), 'req_attend' => 0.0, 'act_attend' => 0.0,
                'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
                'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
                'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
                'welfare' => 0.0, 'coef' => $coef, 'remark' => '',
            ];
        }
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculateManagers($ym);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', $ym)->where('is_manager_row', true)->get() as $r) {
            $d = json_decode($r->row_data, true);
            $out[$d['name']] = $d;
        }
        return $out;
    }

    public function test_quarter_grade_quarter_type_mid_months_zero(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        $r = $this->calcManagers('2026-03', ['张三'])['张三'];
        $this->assertEquals(0.0, (float) $r['perf_pay'], '季度型季度中月绩效为 0');
        $this->assertNull($r['perf_detail'] ?? null);
    }

    public function test_quarter_grade_quarter_type_pays_q1_full_without_ratio(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) $this->seedHistory(1, $m);
        $this->seedCoef(1, 'quarterly', '2026-Q1', 0.9);
        $r = $this->calcManagers('2026-04', ['张三'])['张三'];
        // Σ(1000×22/22)×3 = 3000；3000 × 季度系数0.9 = 2700（无比例、全额）
        $this->assertEquals(2700.0, (float) $r['perf_pay']);
        $d = $r['perf_detail'] ?? null;
        $this->assertNotNull($d);
        $this->assertEquals('2026-Q1', $d['period']);
        $this->assertEquals('quarterly', $d['type']);
        $this->assertArrayNotHasKey('ratio', $d, '季度绩效法无比例字段');
        $this->assertArrayNotHasKey('half_year', $d, '季度绩效法无半年度部分');
        $this->assertEquals(0.9, (float) $d['coef']);
        $this->assertEquals(['2026-01', '2026-02', '2026-03'], array_column($d['months'], 'ym'));
    }

    public function test_quarter_grade_quarter_type_july_no_half_year(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $m) $this->seedHistory(1, $m);
        $this->seedCoef(1, 'quarterly', '2026-Q2', 1.0); // 故意不录半年度系数
        $r = $this->calcManagers('2026-07', ['张三'])['张三'];
        // 仅 Q2：3 × 1000 × 1.0 = 3000，无 H1 加发
        $this->assertEquals(3000.0, (float) $r['perf_pay']);
        $this->assertEquals('2026-Q2', $r['perf_detail']['period'] ?? null);
        $this->assertArrayNotHasKey('half_year', $r['perf_detail']);
    }

    public function test_quarter_grade_quarter_missing_coef_marks_error(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) $this->seedHistory(1, $m);
        $r = $this->calcManagers('2026-04', ['张三'])['张三'];
        $this->assertEquals(0.0, (float) $r['perf_pay']);
        $this->assertEquals('missing_coef', $r['perf_detail']['error'] ?? null);
        $this->assertEquals('2026-Q1', $r['perf_detail']['period'] ?? null);
    }

    public function test_quarter_grade_missing_and_invalid_grade_mark_error(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三', '');           // 未同步档位
        $this->seedManager(2, '李四', '总经理级');   // 不在三档
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
            $this->seedHistory(2, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $this->seedCoef(2, 'quarterly', '2026-Q1', 1.0);
        $all = $this->calcManagers('2026-04', ['张三', '李四']);
        $this->assertEquals(0.0, (float) $all['张三']['perf_pay']);
        $this->assertEquals('missing_pay_grade', $all['张三']['perf_detail']['error'] ?? null);
        $this->assertEquals(0.0, (float) $all['李四']['perf_pay']);
        $this->assertEquals('invalid_pay_grade', $all['李四']['perf_detail']['error'] ?? null);
    }

    public function test_quarter_grade_monthly_type_pays_every_month_with_coef(): void
    {
        $this->seedRules();
        $this->seedManager(1, '李四', '主管级');
        // 3 月（季度中月）：当月绩效 = 1000 × 22/22 × 0.9 = 900
        $r = $this->calcManagers('2026-03', ['李四'], 0.9)['李四'];
        $this->assertEquals(900.0, (float) $r['perf_pay']);
        $this->assertNull($r['perf_detail'] ?? null);
    }

    public function test_monthly_grade_pays_current_month_even_at_quarter_end(): void
    {
        $this->seedRules();
        $this->seedManager(1, '李四', '主管级');
        // 4 月季度末：无 1-3 月历史、无季度系数，月度型只发当月，满勤 coef=1 → 1000
        $r = $this->calcManagers('2026-04', ['李四'])['李四'];
        $this->assertEquals(1000.0, (float) $r['perf_pay']);
        $this->assertNull($r['perf_detail'] ?? null);
    }

    public function test_monthly_grade_ignores_legacy_quarter_coef(): void
    {
        $this->seedRules();
        $this->seedManager(1, '李四', '主管级');
        $this->seedCoef(1, 'quarterly', '2026-Q1', 0.5); // 库中残留季度系数
        $r = $this->calcManagers('2026-04', ['李四'])['李四'];
        $this->assertEquals(1000.0, (float) $r['perf_pay'], '月度型不受残留季度系数影响');
        $this->assertNull($r['perf_detail'] ?? null);
    }

    public function test_quarter_grade_mid_quarter_hire_only_accumulates_active_months(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三', '经理级', '2026-02-10');
        $this->seedHistory(1, '2026-02');
        $this->seedHistory(1, '2026-03');
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $r = $this->calcManagers('2026-04', ['张三'])['张三'];
        // 仅累计在职的 2/3 月：2000 × 1.0 = 2000
        $this->assertEquals(2000.0, (float) $r['perf_pay']);
        $this->assertEquals(['2026-02', '2026-03'], array_column($r['perf_detail']['months'], 'ym'));
    }
}
