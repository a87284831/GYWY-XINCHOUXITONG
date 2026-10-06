<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 锁定后重算拦截（问题5）：
 *  - 微调在保存端本就 400（PayrollAdjustLogTest/PayrollArchiveGuardTest 已覆盖），
 *    漏洞在「重新核算」：calc 四入口对已归档（锁定）行无任何检查，重算会用公式
 *    结果覆盖锁定行的 row_data（archived 标记虽恢复，数据已被覆盖）。
 *  - 修复：目标范围内存在 archived 行 → 首次 409 返回锁定名单；force=true 二次确认放行。
 *  - 附带修复 allArchived 口径：员工视图 archived 判定必须排除 is_case_row，
 *    否则案场行归档会让员工工资表误报"已锁定"。
 */
class PayrollRecalcArchiveGuardTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-07';
    private const PROJ = '测试项目';
    private const PROJ_B = '第二项目';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        $this->seedBase();
    }

    private function attRow(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 0; $i < 20; $i++) $d[$i] = '√';
        return ['days' => $d, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => ''];
    }

    private function seedBase(): void
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
            ['payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false, 'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
            ]], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
            ['name' => self::PROJ_B, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $people = [
            [2, '正常人', self::PROJ, 'staff'],
            [3, '张管理', self::PROJ, 'manager'],
            [4, '李案场', self::PROJ, 'case'],
            [5, '王总部', '物业总部', 'hq'],
            [6, '项目B人', self::PROJ_B, 'staff'],
        ];
        foreach ($people as [$id, $name, $proj, $type]) {
            DB::table('payroll_staff')->insert([
                'legacy_id' => $id, 'name' => $name, 'project_name' => $proj, 'position' => '测试岗',
                'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
                'hire_date' => '2026-01-01', 'resign_date' => null,
                'deleted' => false, 'person_type' => $type,
                'data' => json_encode([
                    'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '初始', 'note' => '']],
                    'special_deductions' => [], 'regular_date' => '', 'resign_date' => '',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $mkBlock = function (string $key, array $names): void {
            $rows = [];
            foreach ($names as $n) $rows[$n] = $this->attRow();
            DB::table('payroll_attendance')->insert([
                'record_key' => self::YM . '|' . $key, 'year_month' => self::YM, 'project_name' => $key,
                'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $mkBlock(self::PROJ, ['正常人', '张管理', '李案场']);
        $mkBlock(self::PROJ_B, ['项目B人']);
        $mkBlock('物业总部', ['王总部']);

        $calc = new PayrollCalculator();
        $calc->calculate(self::YM, [self::PROJ, self::PROJ_B]);
        $calc->calculateManagers(self::YM);
        $calc->calculateCaseStaff(self::YM);
        $calc->calculateHq(self::YM);
    }

    private function archiveRows(array $flags, array $extra = []): void
    {
        $q = DB::table('payroll_results')->where('year_month', self::YM);
        if (($flags['staff'] ?? false)) $q->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        if (($flags['manager'] ?? false)) $q->where('is_manager_row', true)->where('is_hq_row', false);
        if (($flags['case'] ?? false)) $q->where('is_case_row', true)->where('is_hq_row', false);
        if (($flags['hq'] ?? false)) $q->where('is_hq_row', true);
        if (!empty($extra['project'])) $q->where('project_name', $extra['project']);
        $q->update(['archived' => true]);
    }

    private function calc(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payroll/calc', array_merge([
            'ym' => self::YM, 'projects' => [self::PROJ],
        ], $body), ['X-Token' => $this->adminToken]);
    }

    public function test_staff_recalc_blocked_when_scope_archived(): void
    {
        $this->archiveRows(['staff' => true], ['project' => self::PROJ]);
        $resp = $this->calc();
        $resp->assertStatus(409);
        $this->assertTrue($resp->json('need_confirm'));
        $locked = $resp->json('locked');
        $this->assertIsArray($locked);
        $ids = array_column($locked, 'staff_id');
        $this->assertContains(2, $ids);
        // 范围外人员（项目B）不得进锁定名单
        $this->assertNotContains(6, $ids);
    }

    public function test_force_allows_staff_recalc_and_keeps_archived_flag(): void
    {
        $this->archiveRows(['staff' => true], ['project' => self::PROJ]);
        $this->calc(['force' => true])->assertOk();
        // force 重算覆盖数据后，行仍保持锁定状态（不能因重算解锁）
        $stillArchived = DB::table('payroll_results')->where('year_month', self::YM)
            ->where('staff_legacy_id', 2)->value('archived');
        $this->assertEquals(1, (int) $stillArchived);
    }

    public function test_unarchived_recalc_not_blocked(): void
    {
        $this->calc()->assertOk();
    }

    public function test_recalc_scope_isolation_other_project_archived(): void
    {
        // 测试项目已锁定；只重算第二项目 → 不拦截
        $this->archiveRows(['staff' => true], ['project' => self::PROJ]);
        $this->calc(['projects' => [self::PROJ_B]])->assertOk();
    }

    public function test_manager_recalc_blocked_when_archived(): void
    {
        $this->archiveRows(['manager' => true]);
        $resp = $this->postJson('/api/payroll/calc-managers', ['ym' => self::YM], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(409);
        $this->assertTrue($resp->json('need_confirm'));
        $this->assertContains(3, array_column($resp->json('locked'), 'staff_id'));
        // force 放行
        $this->postJson('/api/payroll/calc-managers', ['ym' => self::YM, 'force' => true], ['X-Token' => $this->adminToken])
            ->assertOk();
    }

    public function test_case_recalc_blocked_when_archived(): void
    {
        $this->archiveRows(['case' => true]);
        $this->postJson('/api/payroll/calc-case', ['ym' => self::YM], ['X-Token' => $this->adminToken])
            ->assertStatus(409);
    }

    public function test_hq_recalc_blocked_when_archived(): void
    {
        $this->archiveRows(['hq' => true]);
        $this->postJson('/api/payroll/calc-hq', ['ym' => self::YM], ['X-Token' => $this->adminToken])
            ->assertStatus(409);
    }

    public function test_staff_view_archived_flag_not_polluted_by_case_rows(): void
    {
        // 仅案场行归档：员工工资表不得误报已锁定
        $this->archiveRows(['case' => true]);
        $resp = $this->getJson('/api/payroll?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $this->assertFalse($resp->json('archived'), '案场行归档不应让员工视图误报已锁定');

        // 员工行归档后才报已锁定
        $this->archiveRows(['staff' => true]);
        $resp2 = $this->getJson('/api/payroll?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $this->assertTrue($resp2->json('archived'));
    }
}
