<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-10-03 修复风险1：无实际转正日期的员工离职后，离职状态吞掉在职时的
 * 试用标记，salarySegments 的试用兜底只认 status='试用'，导致离职当月反按
 * 正式工发绩效。修复：离职且无实际转正日时用 data.planned_regular_date
 * （钉钉花名册「计划转正日期」，离职后仍保留）逐日判定试用期。
 */
class PayrollResignedProbationTest extends TestCase
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

    /** 8月1-3日出勤3天，其余休息 → 应出勤=3，实际出勤=3 */
    private function threeDays(): array
    {
        $d = array_fill(0, 31, '休');
        $d[0] = '√'; $d[1] = '√'; $d[2] = '√';
        return $d;
    }

    private function seedStaff(int $id, string $name, string $status, ?string $planned, array $days): array
    {
        $data = [
            'salary_history' => [[
                'effective_date' => '2026-07-29', 'fixed_monthly' => 10000, 'base_salary' => 8000,
                'type' => '初始', 'note' => '',
            ]],
            'special_deductions' => [],
            'planned_regular_date' => $planned,
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $status, 'fixed_monthly' => 10000, 'base_salary' => 8000,
            'hire_date' => '2026-07-29', 'regular_date' => null, 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $att = array_merge([
            'days' => $days, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ]);
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|测试项目', 'year_month' => self::YM, 'project_name' => '测试项目',
            'rows' => json_encode([$name => $att], JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculate(self::YM, ['测试项目']);
        $r = DB::table('payroll_results')->where('year_month', self::YM)
            ->where('staff_legacy_id', $id)->first();
        return json_decode($r->row_data, true);
    }

    public function test_resigned_before_planned_regular_date_gets_no_performance(): void
    {
        $this->seedRules();
        // 测试人员同型：9-29 入职、无实际转正日、计划转正 8-29、月初离职，1-3 日出勤
        $r = $this->seedStaff(1, '离职试用', '离职', '2026-08-29', $this->threeDays());

        $this->assertSame('离职', $r['status']);
        $this->assertEqualsWithDelta(8000.0, $r['base_pay'], 0.02, '基本工资按出勤照发');
        $this->assertEqualsWithDelta(0.0, $r['perf_pay'], 0.02, '离职时未转正（计划转正日在月末）不发绩效');
    }

    public function test_resigned_after_planned_regular_date_gets_performance(): void
    {
        $this->seedRules();
        // 计划转正日已过（7-15）→ 视为到期转正，8 月按正式工
        $r = $this->seedStaff(2, '离职正式', '离职', '2026-07-15', $this->threeDays());

        $this->assertEqualsWithDelta(2000.0, $r['perf_pay'], 0.02);
    }

    public function test_resigned_without_any_regular_date_gets_performance(): void
    {
        $this->seedRules();
        // 无实际/计划转正日：无试用期证据，不臆断为试用，保持原口径（正式）
        $r = $this->seedStaff(3, '离职无日期', '离职', null, $this->threeDays());

        $this->assertEqualsWithDelta(2000.0, $r['perf_pay'], 0.02);
    }

    public function test_active_probation_without_dates_still_no_performance(): void
    {
        $this->seedRules();
        $r = $this->seedStaff(4, '在职试用', '试用', null, $this->threeDays());

        $this->assertSame('试用', $r['status']);
        $this->assertEqualsWithDelta(0.0, $r['perf_pay'], 0.02, '在职试用人员原有口径不变');
    }

    public function test_resigned_probation_split_by_day_around_planned_date(): void
    {
        $this->seedRules();
        // 计划转正日 8-02：8-01 试用段，8-02/03 正式段 → 绩效 = 2000×2/3
        $r = $this->seedStaff(5, '月中到期', '离职', '2026-08-02', $this->threeDays());

        $this->assertEqualsWithDelta(round(2000 * 2 / 3, 2), $r['perf_pay'], 0.02, '按计划转正日逐日切段');
    }
}
