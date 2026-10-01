<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 核算护栏（2026-10-01，Task 1）：薪资基数缺失（fixed_monthly==0 &&
 * base_salary==0）且当月有实际出勤的人员，不再静默生成 0 工资行——
 * 适用范围由「仅离职」扩展到所有人员：跳过核算并进入 missing danger
 * 清单（姓名+项目），提示去钉钉花名册补录/检查同步后重算。
 * 仅有考勤行但实际出勤为 0（整月公休）者不在跳过范围，正常出行。
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

    public function test_resigned_with_attendance_but_zero_base_is_skipped_to_missing(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '张传彩', 0, 0);

        $r = $this->calc(['张传彩' => $this->makeAtt($this->workedDays())]);

        // 基数缺失且有出勤：不得生成工资行，进 missing danger 清单提示补录钉钉花名册
        $this->assertSame(0, $r['result']['count']);
        $this->assertNotEmpty($r['result']['missing']);
        $m = $r['result']['missing'][0];
        $this->assertSame('张传彩', $m['name']);
        $this->assertSame('测试项目', $m['project']);
        $this->assertSame('danger', $m['level']);
        $this->assertStringContainsString('钉钉花名册', $m['reason']);
        $this->assertArrayNotHasKey(1, $r['rows']);
        $this->assertSame([], $r['result']['warnings']);
    }

    public function test_resigned_with_normal_base_has_no_warning(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '李四', 6000, 5000);

        $r = $this->calc(['李四' => $this->makeAtt($this->workedDays())]);

        $this->assertSame(1, $r['result']['count']);
        $this->assertSame([], $r['result']['warnings']);
    }

    public function test_resigned_zero_base_no_attendance_produces_no_danger_warning(): void
    {
        // 有考勤行但整月全公休（actual=0）：不属于「无考勤行」missing 路径，正常出行
        $this->seedRules();
        $this->seedStaff(1, '王五', 0, 0);

        $r = $this->calc(['王五' => $this->makeAtt(array_fill(0, 30, '休'))]);

        $this->assertSame(1, $r['result']['count']);
        $this->assertSame([], $r['result']['missing']);
        $this->assertArrayHasKey(1, $r['rows']);
        // Task 2：整月全公休 net=0 → 1 条 info warning，reason 含「实发」
        $warnings = $r['result']['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertSame('info', $warnings[0]['level']);
        $this->assertStringContainsString('实发', $warnings[0]['reason']);
    }

    public function test_active_staff_zero_base_with_attendance_is_skipped_to_missing(): void
    {
        // 护栏范围扩展到在职人员：0/0 且有出勤同样跳过并进 danger 清单
        $this->seedRules();
        $this->seedStaff(1, '赵六', 0, 0, '正式', 'staff');

        $r = $this->calc(['赵六' => $this->makeAtt($this->workedDays())]);

        $this->assertSame(0, $r['result']['count']);
        $this->assertNotEmpty($r['result']['missing']);
        $m = $r['result']['missing'][0];
        $this->assertSame('赵六', $m['name']);
        $this->assertSame('danger', $m['level']);
        $this->assertStringContainsString('钉钉', $m['reason']);
        $this->assertStringNotContainsString('离职', $m['reason']);
        $this->assertArrayNotHasKey(1, $r['rows']);
        $this->assertSame([], $r['result']['warnings']);
    }
}
