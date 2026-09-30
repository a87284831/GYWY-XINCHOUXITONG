<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 核算护栏（2026-10-01）：离职人员当月有出勤但薪资基数为空
 * （fixed_monthly==0 && base_salary==0，典型成因：钉钉离职列表新建占位行
 * 从未同步花名册薪资字段）→ 不得静默出 0 工资单，结果必须携带显式
 * warnings 清单（姓名+项目），提示去钉钉花名册补录后重算。
 */
class PayrollResignGuardTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-06';

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

    /** person_type=null 模拟钉钉离职列表新建占位行的真实形态 */
    private function seedStaff(int $id, string $name, float $fixed, float $base, string $status = '离职', $personType = null): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $status, 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => '2026-01-01', 'resign_date' => $status === '离职' ? '2026-06-15' : null,
            'deleted' => false, 'person_type' => $personType,
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => $fixed, 'base_salary' => $base, 'type' => '初始', 'note' => '']],
                'special_deductions' => [],
                'regular_date' => '', 'resign_date' => $status === '离职' ? '2026-06-15' : '',
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

    /** 6月30天：前20天出勤 */
    private function workedDays(): array
    {
        $d = array_fill(0, 30, '休');
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

    public function test_resigned_with_attendance_but_zero_base_produces_warning(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '张传彩', 0, 0);

        $r = $this->calc(['张传彩' => $this->makeAtt($this->workedDays())]);

        // 工资单仍生成（计0），但必须显式警告，不得静默
        $this->assertSame(1, $r['result']['count']);
        $this->assertNotEmpty($r['result']['warnings']);
        $w = $r['result']['warnings'][0];
        $this->assertSame('张传彩', $w['name']);
        $this->assertSame('测试项目', $w['project']);
        $this->assertStringContainsString('钉钉花名册', $w['reason']);
        $this->assertArrayHasKey(1, $r['rows']);
    }

    public function test_resigned_with_normal_base_has_no_warning(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '李四', 6000, 5000);

        $r = $this->calc(['李四' => $this->makeAtt($this->workedDays())]);

        $this->assertSame(1, $r['result']['count']);
        $this->assertSame([], $r['result']['warnings']);
    }

    public function test_resigned_zero_base_but_no_actual_attendance_has_no_warning(): void
    {
        // 整月无出勤（全公休/空）：计0是合理结果，不触发警告噪音
        $this->seedRules();
        $this->seedStaff(1, '王五', 0, 0);

        $r = $this->calc(['王五' => $this->makeAtt(array_fill(0, 30, '休'))]);

        $this->assertSame([], $r['result']['warnings']);
    }

    public function test_active_staff_zero_base_is_out_of_guard_scope(): void
    {
        // 护栏范围为离职人员；在职人员薪资缺失走钉钉同步兜底，不在本次护栏内
        $this->seedRules();
        $this->seedStaff(1, '赵六', 0, 0, '正式', 'staff');

        $r = $this->calc(['赵六' => $this->makeAtt($this->workedDays())]);

        $this->assertSame([], $r['result']['warnings']);
    }
}
