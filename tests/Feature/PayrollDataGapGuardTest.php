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
}
