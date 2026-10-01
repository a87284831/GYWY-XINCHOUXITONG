<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 数据缺失护栏（2026-10-01，Task 1）：
 *   - 单项基数为 0（固定月薪/基本工资只缺一项）不属于缺失，正常核算；
 *   - 两项均为 0 且有实际出勤 → 跳过核算进 missing danger（与正常人员同项目互不影响）；
 *   - 两项均为 0 但考勤表中根本无此人 → 沿用原「无考勤记录」missing info 路径。
 */
class PayrollDataGapGuardTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-07';

    private function seedRules(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'sick_pay' => ['enabled' => true, 'params' => ['factor_a' => 0.7, 'factor_b' => 0.6, 'sick_base' => 'base']],
            'meal_subsidy' => ['mode' => 'full'],
            'allowances' => ['mode' => 'full'],
            'deduction_rules' => [
                'miss_punch' => ['enabled' => true, 'first_3' => 30, 'after_3' => 50],
                'absent' => ['enabled' => true, 'multiplier' => 3],
            ],
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => $this->symbols()], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function symbols(): array
    {
        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
        ];
        $out = [];
        foreach ($def as $sym => [$name, $req, $act, $val, $cat]) {
            $out[] = ['symbol' => $sym, 'name' => $name, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        return $out;
    }

    private function seedStaff(int $id, string $name, float $fixed, float $base, string $status = '正式', $personType = 'staff'): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $status, 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => $personType,
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => $fixed, 'base_salary' => $base, 'type' => '初始', 'note' => '']],
                'special_deductions' => [],
                'regular_date' => '', 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAtt(array $days): array
    {
        return [
            'days' => $days, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ];
    }

    /** 7月31天：前20天出勤 */
    private function workedDays(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 0; $i < 20; $i++) $d[$i] = '√';
        return $d;
    }

    private function calc(array $rows, string $ym = self::YM): array
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = (new PayrollCalculator())->calculate($ym, ['测试项目']);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', $ym)->get() as $r) {
            $out[$r->staff_legacy_id] = json_decode($r->row_data, true);
        }
        return ['rows' => $out, 'result' => $result];
    }

    public function test_single_zero_base_with_attendance_is_calculated_normally(): void
    {
        // 仅基本工资一项为 0（固定月薪 5000）：不属于基数缺失，正常出行
        $this->seedRules();
        $this->seedStaff(1, '钱七', 5000, 0);

        $r = $this->calc(['钱七' => $this->makeAtt($this->workedDays())]);

        $this->assertSame(1, $r['result']['count']);
        $this->assertSame([], $r['result']['missing']);
        $this->assertSame([], $r['result']['warnings']);
        $this->assertArrayHasKey(1, $r['rows']);
    }

    public function test_zero_base_staff_skipped_while_normal_staff_calculated(): void
    {
        // 0/0 人员与正常人员混在同一项目：仅正常人出行，缺失者进 danger 清单
        $this->seedRules();
        $this->seedStaff(1, '孙八', 0, 0);
        $this->seedStaff(2, '李四', 6000, 5000);

        $r = $this->calc([
            '孙八' => $this->makeAtt($this->workedDays()),
            '李四' => $this->makeAtt($this->workedDays()),
        ]);

        $this->assertSame(1, $r['result']['count']);
        $this->assertCount(1, $r['result']['missing']);
        $m = $r['result']['missing'][0];
        $this->assertSame('孙八', $m['name']);
        $this->assertSame('danger', $m['level']);
        $this->assertArrayNotHasKey(1, $r['rows']);
        $this->assertArrayHasKey(2, $r['rows']);
        $this->assertSame([], $r['result']['warnings']);
    }

    public function test_zero_base_staff_without_attendance_row_keeps_info_missing(): void
    {
        // 考勤表中根本无此人：沿用原「无考勤记录」info 路径，不是 danger，也不涉薪资缺失文案
        $this->seedRules();
        $this->seedStaff(1, '周九', 0, 0);

        $r = $this->calc([]);

        $this->assertSame(0, $r['result']['count']);
        $this->assertCount(1, $r['result']['missing']);
        $m = $r['result']['missing'][0];
        $this->assertSame('周九', $m['name']);
        $this->assertSame('info', $m['level']);
        $this->assertStringContainsString('考勤', $m['reason']);
        $this->assertStringNotContainsString('薪资数据缺失', $m['reason']);
        $this->assertArrayNotHasKey(1, $r['rows']);
    }

    // ---------- Task 2: 行级 warnings ----------

    public function test_net_zero_legal_produces_single_info_warning(): void
    {
        // 正常基数（6000/5000）但整月全公休（actual=0）、无扣款 → 出行 net=0
        // → 1 条 info warning，reason 同时含「实发」与「出勤」
        $this->seedRules();
        $this->seedStaff(1, '陈十', 6000, 5000);

        $r = $this->calc(['陈十' => $this->makeAtt(array_fill(0, 31, '休'))]);

        $this->assertSame(1, $r['result']['count']);
        $row = $r['rows'][1];
        $this->assertEquals(0.0, (float) $row['net']);
        $warnings = $r['result']['warnings'];
        $this->assertCount(1, $warnings);
        $w = $warnings[0];
        $this->assertSame('info', $w['level']);
        $this->assertSame('陈十', $w['name']);
        $this->assertStringContainsString('实发', $w['reason']);
        $this->assertStringContainsString('出勤', $w['reason']);
    }

    public function test_net_negative_produces_info_warning_with_negative_amount(): void
    {
        // 整月全公休 + 社保扣款 > gross → net<0；info warning reason 含负金额
        $this->seedRules();
        $this->seedStaff(1, '吴十一', 6000, 5000);

        $att = $this->makeAtt(array_fill(0, 31, '休'));
        $att['pen'] = 200.0; $att['med'] = 200.0; $att['une'] = 200.0;
        $att['house'] = 200.0; $att['big'] = 200.0;

        $r = $this->calc(['吴十一' => $att]);

        $this->assertSame(1, $r['result']['count']);
        $row = $r['rows'][1];
        $net = (float) $row['net'];
        $this->assertLessThan(0, $net, '社保扣款合计大于应发时 net 应为负');
        $warnings = $r['result']['warnings'];
        $this->assertCount(1, $warnings);
        $w = $warnings[0];
        $this->assertSame('info', $w['level']);
        $this->assertSame('吴十一', $w['name']);
        $this->assertStringContainsString('实发', $w['reason']);
        // reason 中应能解析到负号（-）；最稳妥断言含 (string)net 值前缀，如 -1000
        $negativePrefix = (string) $net;
        $this->assertStringContainsString($negativePrefix, $w['reason']);
    }

    private function seedManagerRules(): void
    {
        // 经理级季度型：季度末月缺系数 → perf_detail.error = missing_coef
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
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
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => $this->symbols()], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function seedManager(int $id, string $name): void
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
            'pay_grade' => '经理级',
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

    private function seedManagerHistory(int $staffId, string $ym): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => '测试项目',
            'row_data' => json_encode(['fixed' => 6000, 'base' => 5000,
                'perf_att' => 22.0, 'req_att' => 22.0], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => true, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedManagerCoef(int $staffId, string $key, float $coef): void
    {
        DB::table('payroll_period_coefs')->insert([
            'staff_legacy_id' => $staffId, 'period_type' => 'quarterly', 'period_key' => $key,
            'coef' => $coef, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function managerFullDays(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        return $d;
    }

    private function calcManager(string $ym, string $name): array
    {
        $rows[$name] = [
            'days' => $this->managerFullDays(), 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ];
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (new PayrollCalculator())->calculateManagers($ym);
    }

    public function test_perf_error_danger_warning_when_coef_missing(): void
    {
        // 经理级季度型 + 季度末月（2026-04 发 Q1）+ 不录系数 → perf_detail.error=missing_coef
        // → 出行且 warnings 含 danger 条目，reason 含「系数」
        $this->seedManagerRules();
        $this->seedManager(1, '郑十二');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) $this->seedManagerHistory(1, $m);

        $result = $this->calcManager('2026-04', '郑十二');

        $this->assertSame(1, $result['count']);
        $dangers = array_values(array_filter($result['warnings'], fn($w) => $w['level'] === 'danger'));
        $this->assertNotEmpty($dangers, '缺系数应产生 danger warning');
        $matched = false;
        foreach ($dangers as $w) {
            if ($w['name'] === '郑十二' && str_contains($w['reason'], '系数')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, 'danger warning 应属于郑十二且 reason 含「系数」');
    }

    public function test_normal_performance_no_danger_warning_when_coef_set(): void
    {
        // 同上但先 seedCoef 录入季度系数 → warnings 中无 danger 条目
        // 正常出勤 net>0 时 info 也应无
        $this->seedManagerRules();
        $this->seedManager(1, '郑十二');
        foreach (['2026-01', '2026-02', '2026-03'] as $m) $this->seedManagerHistory(1, $m);
        $this->seedManagerCoef(1, '2026-Q1', 0.9);

        $result = $this->calcManager('2026-04', '郑十二');

        $this->assertSame(1, $result['count']);
        $dangers = array_values(array_filter($result['warnings'], fn($w) => $w['level'] === 'danger'));
        $this->assertSame([], $dangers, '正常绩效不应产生 danger warning');
        $infos = array_values(array_filter($result['warnings'], fn($w) => $w['level'] === 'info'));
        $this->assertSame([], $infos, '正常出勤 net>0 不应产生 info warning');
    }
}
