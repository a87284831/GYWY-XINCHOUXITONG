<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 季度/半年度绩效核算测试（管理/总部人员）。
 * 覆盖：季度中绩效为 0、季度末按累计×系数×比例发放、缺系数跳过、
 *       半年度末（7月/1月）同时发季度+半年度两笔、1月跨年取上年 Q4。
 */
class PayrollQuarterlyTest extends TestCase
{
    use RefreshDatabase;

    private function seedRules(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'pay_rules' => [
                'manager' => [
                    'cycle' => 'quarterly',
                    'levels' => ['经理级' => ['quarter_ratio' => 0.95, 'half_year_ratio' => 0.05]],
                    'default' => ['quarter_ratio' => 1.0, 'half_year_ratio' => 0.0],
                ],
                'hq' => [
                    'cycle' => 'quarterly',
                    'levels' => ['经理级' => ['quarter_ratio' => 0.95, 'half_year_ratio' => 0.05]],
                    'default' => ['quarter_ratio' => 1.0, 'half_year_ratio' => 0.0],
                ],
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

    private function seedManager(int $id, string $name, string $level = '经理级'): void
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
            'hire_date' => '2025-01-01', 'regular_date' => '2025-02-01',
            'resign_date' => null, 'deleted' => false, 'person_type' => 'manager',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 造一行历史核算结果（管理人员行）：fixed=6000 base=5000 → 月绩效基数 1000 */
    private function seedHistory(int $staffId, string $ym, float $perfAtt = 22, float $reqAtt = 22, float $fixed = 6000, float $base = 5000): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => '测试项目',
            'row_data' => json_encode(['fixed' => $fixed, 'base' => $base,
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

    /** 跑管理人员核算并取回指定人员的 row_data */
    private function calcManager(string $ym, string $name): array
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode([$name => [
                'days' => $this->fullDays(), 'req_attend' => 0.0, 'act_attend' => 0.0,
                'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
                'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
                'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
                'welfare' => 0.0, 'coef' => null, 'remark' => '',
            ]], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculateManagers($ym);
        $row = DB::table('payroll_results')->where('year_month', $ym)->where('is_manager_row', true)->first();
        $this->assertNotNull($row, "管理人员核算未生成 {$ym} 结果行");
        return json_decode($row->row_data, true);
    }

    public function test_quarterly_manager_mid_month_perf_is_zero(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        $r = $this->calcManager('2026-03', '张三');
        $this->assertEquals(0.0, (float) $r['perf_pay'], '季度中月绩效应为 0');
        $this->assertNull($r['perf_detail'] ?? null, '季度中月不应有 perf_detail');
    }

    public function test_quarterly_manager_end_month_calculates_q1(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $r = $this->calcManager('2026-04', '张三');
        // 每月基数 1000 × 出勤22/应勤22 = 1000，Q1 累计 3000 × 系数1.0 × 比例0.95 = 2850
        $this->assertEquals(2850.0, (float) $r['perf_pay']);
        $d = $r['perf_detail'] ?? null;
        $this->assertNotNull($d);
        $this->assertEquals('2026-Q1', $d['period']);
        $this->assertEquals('quarterly', $d['type']);
        $this->assertEquals(0.95, (float) $d['ratio']);
        $this->assertCount(3, $d['months']);
        $this->assertEquals(['2026-01', '2026-02', '2026-03'], array_column($d['months'], 'ym'));
    }

    public function test_quarterly_missing_coef_marks_error(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
        }
        // 不录系数
        $r = $this->calcManager('2026-04', '张三');
        $this->assertEquals(0.0, (float) $r['perf_pay']);
        $this->assertEquals('missing_coef', $r['perf_detail']['error'] ?? null);
        $this->assertEquals('2026-Q1', $r['perf_detail']['period'] ?? null);
    }

    public function test_missing_pay_grade_marks_error_and_pays_zero(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三', ''); // 档案未同步薪酬档位
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $r = $this->calcManager('2026-04', '张三');
        $this->assertEquals(0.0, (float) $r['perf_pay'], '缺薪酬档位不得按 default 静默发放');
        $this->assertEquals('missing_pay_grade', $r['perf_detail']['error'] ?? null);
    }

    public function test_invalid_pay_grade_marks_error(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三', '总经理级'); // 不在 专员级/主管级/经理级 三档内
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $r = $this->calcManager('2026-04', '张三');
        $this->assertEquals(0.0, (float) $r['perf_pay']);
        $this->assertEquals('invalid_pay_grade', $r['perf_detail']['error'] ?? null);
        $this->assertEquals('总经理级', $r['perf_detail']['pay_grade'] ?? null);
    }

    public function test_unconfigured_grade_ratio_marks_missing_pay_rule(): void
    {
        $this->seedRules(); // 规则只配了经理级
        $this->seedManager(1, '张三', '专员级');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q1', 1.0);
        $r = $this->calcManager('2026-04', '张三');
        $this->assertEquals(0.0, (float) $r['perf_pay'], '档位合法但未配比例，不得按 default 发放');
        $this->assertEquals('missing_pay_rule', $r['perf_detail']['error'] ?? null);
    }

    public function test_half_year_end_pays_both_quarter_and_half_year(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q2', 1.0);
        $this->seedCoef(1, 'half_year', '2026-H1', 1.0);
        $r = $this->calcManager('2026-07', '张三');
        // Q2 部分：3个月 × 1000 × 1.0 × 0.95 = 2850；H1 部分：6个月 × 1000 × 1.0 × 0.05 = 300
        $this->assertEquals(3150.0, (float) $r['perf_pay'], '7月应同时发 Q2 季度绩效 + H1 半年度绩效');
        $d = $r['perf_detail'] ?? null;
        $this->assertNotNull($d);
        $this->assertEquals('2026-Q2', $d['period']);
        $this->assertCount(3, $d['months']);
        $this->assertEquals('2026-H1', $d['half_year']['period'] ?? null);
        $this->assertCount(6, $d['half_year']['months'] ?? []);
    }

    public function test_january_uses_previous_year_q4(): void
    {
        $this->seedRules();
        $this->seedManager(1, '张三');
        foreach (['2026-10', '2026-11', '2026-12'] as $m) {
            $this->seedHistory(1, $m);
        }
        $this->seedCoef(1, 'quarterly', '2026-Q4', 1.0);
        $r = $this->calcManager('2027-01', '张三');
        // 1月属上年 Q4：3个月 × 1000 × 1.0 × 0.95 = 2850；H2 系数未录 → 半年度部分为 0
        $this->assertEquals(2850.0, (float) $r['perf_pay'], '1月应取上年 10-12 月累计并按上年 Q4 系数发放');
        $this->assertEquals('2026-Q4', $r['perf_detail']['period'] ?? null);
    }
}
