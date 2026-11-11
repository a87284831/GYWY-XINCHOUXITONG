<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 同项目同名档案治理（2026-11-11，用户确认口径）：
 *  1. 同项目同名「离职但无离职日期（脏档案） + 在岗」→ 只出 1 行（在岗），并给出 info 提示；
 *  2. 跨项目同名 → 两人各出 1 行，绝不合并；
 *  3. 有离职日期的当月离职 → 照常核算，不受规则影响；
 *  4. 无 staff_id/dingtalk_userid 标记的旧考勤 → 仍可按姓名兜底；
 *  5. 两个在岗同名 → 不自动剔除，给 danger 名单；考勤行标记指向其一，另一条不得借用该行；
 *  6. 既有 AttendanceResignedDupNameTest 回归由全量测试覆盖。
 */
class PayrollDuplicateStaffTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'sick_pay' => ['enabled' => false],
            'deduction_rules' => [
                'miss_punch' => ['enabled' => false],
                'late_early' => ['enabled' => false],
                'absent' => ['enabled' => false],
            ],
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'calc_rules.json',
            'payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $items = [];
        foreach (['√' => ['出勤', true, true, 1.0, '正常'], '休' => ['休息日', false, false, 0.0, '公休']] as $sym => [$n, $req, $act, $val, $cat]) {
            $items[] = ['symbol' => $sym, 'name' => $n, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedStaff(int $id, string $name, string $project, string $status, ?string $resignDate, string $duid = ''): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => $project, 'position' => '岗位',
            'status' => $status, 'resign_date' => $resignDate, 'hire_date' => '2026-01-01',
            'fixed_monthly' => 6000, 'base_salary' => 5000,
            'dingtalk_userid' => $duid !== '' ? $duid : null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '初始', 'note' => '']],
                'special_deductions' => [], 'regular_date' => '', 'resign_date' => $resignDate ?? '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 30 天考勤行；可带 staff_id / dingtalk_userid 标记 */
    private function attRow(array $marker = []): array
    {
        $days = array_fill(0, 30, '休');
        for ($i = 0; $i < 22; $i++) $days[$i] = '√';
        return array_merge([
            'days' => $days, 'req_attend' => 22, 'act_attend' => 22,
            'reward' => 0, 'punish' => 0, 'meal_sub' => 0, 'night_sub' => 0, 'title_sub' => 0,
            'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
            'miss_deduct' => 0, 'late_deduct' => 0, 'other_deduct' => 0, 'uniform_deduct' => 0,
            'welfare' => 0, 'coef' => null, 'remark' => '',
        ], $marker);
    }

    /** @param array<string,array<string,array>> $blocks 项目 => [姓名 => 考勤行] */
    private function calc(array $blocks, array $projects): array
    {
        foreach ($blocks as $project => $rows) {
            DB::table('payroll_attendance')->insert([
                'record_key' => self::YM . '|' . $project, 'year_month' => self::YM,
                'project_name' => $project, 'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
                'locked' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $result = (new PayrollCalculator())->calculate(self::YM, $projects);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', self::YM)->get() as $r) {
            $out[(int)$r->staff_legacy_id] = json_decode($r->row_data, true);
        }
        return ['rows' => $out, 'result' => $result];
    }

    private function dupWarnings(array $warnings): array
    {
        return array_values(array_filter($warnings, fn ($w) => str_contains($w['reason'] ?? '', '同名')));
    }

    /** ① 离职无离职日期 + 在岗 → 只出 1 行，且 info 提示可见 */
    public function test_dirty_resigned_duplicate_excluded_with_info_notice(): void
    {
        $this->seedStaff(406, '徐振祥', '罗庄项目', '离职', null);
        $this->seedStaff(422, '徐振祥', '罗庄项目', '正式', null, 'u422');

        $r = $this->calc([
            '罗庄项目' => ['徐振祥' => $this->attRow(['staff_id' => 422, 'dingtalk_userid' => 'u422'])],
        ], ['罗庄项目']);

        $this->assertSame(1, $r['result']['count'], '同项目同名脏档案不得导致重复出行');
        $this->assertArrayHasKey(422, $r['rows']);
        $this->assertArrayNotHasKey(406, $r['rows']);

        $dup = $this->dupWarnings($r['result']['warnings']);
        $this->assertCount(1, $dup, '剔除脏档案必须有可见提示');
        $this->assertSame('info', $dup[0]['level']);
        $this->assertStringContainsString('已自动剔除', $dup[0]['reason']);
        $this->assertStringContainsString('406', $dup[0]['reason']);
    }

    /** ② 跨项目同名（孙涛 425/511）→ 各出 1 行，不合并、不提示同名 */
    public function test_cross_project_same_name_both_calculated(): void
    {
        $this->seedStaff(425, '孙涛', '罗庄项目', '正式', null, 'u425');
        $this->seedStaff(511, '孙涛', '日照项目', '正式', null, 'u511');

        $r = $this->calc([
            '罗庄项目' => ['孙涛' => $this->attRow(['staff_id' => 425, 'dingtalk_userid' => 'u425'])],
            '日照项目' => ['孙涛' => $this->attRow(['staff_id' => 511, 'dingtalk_userid' => 'u511'])],
        ], ['罗庄项目', '日照项目']);

        $this->assertSame(2, $r['result']['count']);
        $this->assertArrayHasKey(425, $r['rows']);
        $this->assertArrayHasKey(511, $r['rows']);
        $this->assertSame('罗庄项目', $r['rows'][425]['project']);
        $this->assertSame('日照项目', $r['rows'][511]['project']);
        $this->assertCount(0, $this->dupWarnings($r['result']['warnings']), '跨项目同名不得被当作重复档案');
    }

    /** ③ 有离职日期的当月离职 → 照常核算（绝对不能被同名治理误伤） */
    public function test_resigned_with_date_still_calculated(): void
    {
        $this->seedStaff(333, '王五', '罗庄项目', '离职', '2026-09-15');

        $r = $this->calc([
            '罗庄项目' => ['王五' => $this->attRow(['staff_id' => 333])],
        ], ['罗庄项目']);

        $this->assertSame(1, $r['result']['count']);
        $this->assertArrayHasKey(333, $r['rows']);
        $this->assertCount(0, $this->dupWarnings($r['result']['warnings']), '正常离职人员不得命中同名治理');
    }

    /** ④ 无标记旧考勤仍按姓名兜底 */
    public function test_legacy_unmarked_attendance_falls_back_to_name(): void
    {
        $this->seedStaff(444, '张三', '罗庄项目', '正式', null);

        $r = $this->calc([
            '罗庄项目' => ['张三' => $this->attRow()],
        ], ['罗庄项目']);

        $this->assertSame(1, $r['result']['count']);
        $this->assertArrayHasKey(444, $r['rows']);
        $this->assertSame(22, (int)$r['rows'][444]['req_att']);
    }

    /** ⑤ 两个在岗同名 → danger 名单；标记指向其一，另一条不得借用该行重复出行 */
    public function test_two_active_duplicates_get_danger_notice_and_no_row_borrowing(): void
    {
        $this->seedStaff(501, '李雷', '罗庄项目', '正式', null, 'u501');
        $this->seedStaff(502, '李雷', '罗庄项目', '试用', null, 'u502');

        $r = $this->calc([
            '罗庄项目' => ['李雷' => $this->attRow(['staff_id' => 501, 'dingtalk_userid' => 'u501'])],
        ], ['罗庄项目']);

        $dup = $this->dupWarnings($r['result']['warnings']);
        $this->assertCount(1, $dup, '两个在岗同名必须给出提示，不得静默');
        $this->assertSame('danger', $dup[0]['level']);
        $this->assertStringContainsString('501', $dup[0]['reason']);
        $this->assertStringContainsString('502', $dup[0]['reason']);

        // 只有标记归属者拿到考勤行；另一条在岗同名档案不得按姓名借用该行
        $this->assertArrayHasKey(501, $r['rows']);
        $this->assertArrayNotHasKey(502, $r['rows']);
        $this->assertSame(1, $r['result']['count']);
        $missing = collect($r['result']['missing'])->firstWhere('name', '李雷');
        $this->assertNotNull($missing);
        $this->assertStringContainsString('考勤表中无此人', $missing['reason']);
    }
}
