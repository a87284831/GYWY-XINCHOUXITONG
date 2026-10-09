<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 列序改造后的回归保护：
 * 1. row_data 新增 hire_date 字段不破坏核算
 * 2. 在职人员当月转正（计划转正日在月中）绩效分段不受影响
 * 3. row_data.status 用档案 status 口径（非 rowStatus 动态推算）
 *
 * 对照基线：PayrollResignedProbationTest 已覆盖离职人员分段；本类补在职分段 + hire_date 字段。
 */
class PayrollHireDateSegmentTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';

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
        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
        ];
        $items = [];
        foreach ($def as $sym => [$name, $req, $act, $val, $cat]) {
            $items[] = ['symbol' => $sym, 'name' => $name, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)]
        );
    }

    /** 8月1-3日出勤3天，其余休息 */
    private function threeDays(): array
    {
        $d = array_fill(0, 31, '休');
        $d[0] = '√'; $d[1] = '√'; $d[2] = '√';
        return $d;
    }

    private function seedStaff(int $id, string $name, string $status, ?string $planned, string $hireDate, ?string $regularDate = null): array
    {
        $data = [
            'salary_history' => [[
                'effective_date' => substr($hireDate, 0, 10), 'fixed_monthly' => 10000, 'base_salary' => 8000,
                'type' => '初始', 'note' => '',
            ]],
            'special_deductions' => [],
            'planned_regular_date' => $planned,
            'regular_date' => $regularDate,
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $status, 'fixed_monthly' => 10000, 'base_salary' => 8000,
            'hire_date' => $hireDate, 'regular_date' => $regularDate, 'resign_date' => null,
            'dept_path' => '测试项目/客服部',
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $att = array_merge([
            'days' => $this->threeDays(), 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ]);
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => self::YM . '|测试项目'],
            ['year_month' => self::YM, 'project_name' => '测试项目',
             'rows' => json_encode([$name => $att], JSON_UNESCAPED_UNICODE), 'locked' => true,
             'created_at' => now(), 'updated_at' => now()]
        );
        (new PayrollCalculator())->calculate(self::YM, ['测试项目']);
        $r = DB::table('payroll_results')->where('year_month', self::YM)
            ->where('staff_legacy_id', $id)->first();
        return json_decode($r->row_data, true);
    }

    public function test_hire_date_field_present_and_not_breaking_calc(): void
    {
        $this->seedRules();
        $r = $this->seedStaff(1, '张三', '正式', null, '2026-07-29');

        $this->assertArrayHasKey('hire_date', $r, 'row_data 必须含 hire_date 字段');
        $this->assertSame('2026-07-29', $r['hire_date']);
        // 核算未受影响：基本工资 8000（3天出勤/22应出勤按日折算）
        $this->assertArrayHasKey('base_pay', $r);
        $this->assertGreaterThan(0, $r['base_pay']);
    }

    public function test_active_probation_before_planned_date_gets_no_performance(): void
    {
        $this->seedRules();
        // 计划转正 8-29，8-1/2/3 全在试用段 → 绩效=0
        $r = $this->seedStaff(2, '在职试用', '试用', '2026-08-29', '2026-07-29');

        $this->assertSame('试用', $r['status'], 'status 用档案口径');
        $this->assertSame('2026-07-29', $r['hire_date']);
        $this->assertEqualsWithDelta(0.0, $r['perf_pay'], 0.02, '试用期不发绩效（probation_excluded）');
    }

    public function test_active_mid_month_regular_split_performance(): void
    {
        $this->seedRules();
        // 实际转正日 8-02：8-1 试用段（不发绩效），8-2/3 正式段 → 绩效 = 2000×2/3
        $r = $this->seedStaff(3, '月中转正', '正式', null, '2026-07-29', '2026-08-02');

        $this->assertSame('2026-07-29', $r['hire_date']);
        $this->assertEqualsWithDelta(round(2000 * 2 / 3, 2), $r['perf_pay'], 0.02, '按实际转正日逐日切段');
    }

    public function test_status_uses_archive_status_not_affecting_perf_segment(): void
    {
        $this->seedRules();
        // 实际转正日 8-02，但档案 status='试用'（钉钉未同步转正）
        // 旧 rowStatus 会推算为"正式"（有 actual regular_date），新档案口径直接读 → '试用'
        // 绩效仍按 actual regular_date 分段（isProbationOnDate 有 actual 时不看 status）
        $r = $this->seedStaff(4, '档案试用实际转正', '试用', null, '2026-07-29', '2026-08-02');

        $this->assertSame('试用', $r['status'], '直接读档案 status，不动态推算为正式');
        $this->assertEqualsWithDelta(round(2000 * 2 / 3, 2), $r['perf_pay'], 0.02, '绩效分段按 actual regular_date，不受 status 显示口径影响');
    }
}
