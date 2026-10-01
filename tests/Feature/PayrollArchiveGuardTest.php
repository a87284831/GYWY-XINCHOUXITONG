<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 归档 409/force 防线（Task 3）：
 *   - locked=true 归档时，若范围内存在 danger 异常（缺基数未核算者 / 绩效配置错误行），
 *     首次请求返回 409 名单；force=true 放行并留日志。
 *   - info 类（net≤0）不拦截；locked=false 不检查。
 *   - 四组行类型 where 与 archive update 完全一致；perf blocker 查询加 archived=false。
 */
class PayrollArchiveGuardTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-07';
    private const PROJ = '测试项目';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->token, $id, now()->addHours(8));
    }

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

    private function seedStaff(int $id, string $name, float $fixed, float $base, string $type = 'staff', string $status = '正式'): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => self::PROJ, 'position' => '测试岗',
            'status' => $status, 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => $type,
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

    private function insertAttendance(array $rows, string $ym = self::YM): void
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|' . self::PROJ, 'year_month' => $ym, 'project_name' => self::PROJ,
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 直接插入一条 payroll_results 行（用于 perf blocker 场景） */
    private function insertResultRow(int $staffId, string $name, array $rowData, bool $archived, bool $isManager, bool $isHq = false): void
    {
        $rowData = array_merge(['staff_id' => $staffId, 'name' => $name, 'project' => self::PROJ], $rowData);
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'staff_legacy_id' => $staffId, 'project_name' => self::PROJ,
            'row_data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
            'archived' => $archived, 'is_manager_row' => (int)$isManager,
            'is_case_row' => 0, 'is_hq_row' => (int)$isHq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------- Test 1 + 2: 缺基数 → 409，force 放行 ----------

    public function test_missing_base_staff_blocks_archive_and_force_releases(): void
    {
        $this->seedRules();
        // 0/0 staff（无 results 行）+ 正常 staff（核算后有 results 行）
        $this->seedStaff(1, '缺基数', 0, 0, 'staff');
        $this->seedStaff(2, '正常人', 6000, 5000, 'staff');
        $this->insertAttendance([
            '缺基数' => $this->makeAtt($this->workedDays()),
            '正常人' => $this->makeAtt($this->workedDays()),
        ]);
        (new PayrollCalculator())->calculate(self::YM, [self::PROJ]);

        // 0/0 人员无 results 行，正常人有
        $this->assertSame(0, DB::table('payroll_results')->where('staff_legacy_id', 1)->count());
        $this->assertSame(1, DB::table('payroll_results')->where('staff_legacy_id', 2)->count());

        // 首次归档 locked=true → 409
        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true], ['X-Token' => $this->token]);
        $resp->assertStatus(409);
        $json = $resp->json();
        $this->assertFalse($json['ok']);
        $this->assertTrue($json['need_confirm']);
        $blockers = $json['blockers'];
        $this->assertNotEmpty($blockers);
        $missing = array_values(array_filter($blockers, fn($b) => $b['name'] === '缺基数'));
        $this->assertCount(1, $missing, 'blockers 应含缺基数人员');
        $this->assertSame('missing_base', $missing[0]['kind']);

        // 正常人 results 行 archived 仍为 0（未放行）
        $this->assertSame(0, (int)DB::table('payroll_results')->where('staff_legacy_id', 2)->value('archived'));

        // force=true 重发 → 200
        $resp2 = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true, 'force' => true], ['X-Token' => $this->token]);
        $resp2->assertOk()->assertJson(['ok' => true]);
        // 正常人 results 行 archived=1
        $this->assertSame(1, (int)DB::table('payroll_results')->where('staff_legacy_id', 2)->value('archived'));
    }

    // ---------- Test 3: 无异常直接 200 ----------

    public function test_no_blocker_archives_directly_200(): void
    {
        $this->seedRules();
        $this->seedStaff(2, '正常人', 6000, 5000, 'staff');
        $this->insertAttendance(['正常人' => $this->makeAtt($this->workedDays())]);
        (new PayrollCalculator())->calculate(self::YM, [self::PROJ]);

        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, (int)DB::table('payroll_results')->where('staff_legacy_id', 2)->value('archived'));
    }

    // ---------- Test 4: 范围隔离 ----------

    public function test_scope_isolation_staff_missing_base_not_block_manager_archive(): void
    {
        $this->seedRules();
        // 员工缺基数存在
        $this->seedStaff(1, '缺基数', 0, 0, 'staff');
        $this->insertAttendance(['缺基数' => $this->makeAtt($this->workedDays())]);

        // manager 归档（无 manager results 行）→ 200
        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true, 'type' => 'manager'], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
    }

    public function test_scope_isolation_perf_error_only_blocks_manager_scope(): void
    {
        $this->seedRules();
        // 一条 manager results 行 perf_detail.error='missing_coef'
        $this->insertResultRow(10, '经理甲', ['perf_detail' => ['error' => 'missing_coef', 'period' => '2026-Q1']], false, true);

        // 员工归档（无 type）→ 200（perf 查询 is_manager_row=false 不匹配 manager 行）
        $respStaff = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true], ['X-Token' => $this->token]);
        $respStaff->assertOk()->assertJson(['ok' => true]);

        // manager 归档 → 409 且 blockers 含经理甲 kind='perf_error'
        $respMgr = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true, 'type' => 'manager'], ['X-Token' => $this->token]);
        $respMgr->assertStatus(409);
        $blockers = $respMgr->json('blockers');
        $perf = array_values(array_filter($blockers, fn($b) => $b['name'] === '经理甲'));
        $this->assertCount(1, $perf);
        $this->assertSame('perf_error', $perf[0]['kind']);
    }

    // ---------- Test 5: 历史归档行不误报 ----------

    public function test_archived_history_rows_not_reported_as_perf_blocker(): void
    {
        $this->seedRules();
        // 未归档的 perf_error 行
        $this->insertResultRow(10, '经理甲', ['perf_detail' => ['error' => 'missing_coef', 'period' => '2026-Q1']], false, true);
        // 历史归档行：archived=true 且 row_data 不含 perf_detail 键
        $this->insertResultRow(11, '历史经理', ['gross' => 5000, 'net' => 4000], true, true);

        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true, 'type' => 'manager'], ['X-Token' => $this->token]);
        $resp->assertStatus(409);
        $names = array_column($resp->json('blockers'), 'name');
        $this->assertContains('经理甲', $names);
        $this->assertNotContains('历史经理', $names, 'archived=true 历史行不应被报告为 perf blocker');
    }

    // ---------- Test 6: 解锁不检查 ----------

    public function test_unlocked_does_not_check_blockers(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '缺基数', 0, 0, 'staff');
        $this->insertAttendance(['缺基数' => $this->makeAtt($this->workedDays())]);

        // locked=false 直接 200，即使有 missing_base 人员
        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => false], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
    }

    // ---------- Test 7: 非 admin 权限 403 ----------

    public function test_non_admin_forbidden(): void
    {
        // 无 token → 401
        $resp = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true]);
        $this->assertNotEquals(200, $resp->status());

        // 项目账号（role != admin）→ 403
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj_user', 'name' => '项目账号',
            'role' => 'project', 'project_name' => self::PROJ, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $pToken, $pid, now()->addHours(8));
        $resp2 = $this->postJson('/api/payroll/archive', ['ym' => self::YM, 'locked' => true], ['X-Token' => $pToken]);
        $resp2->assertStatus(403);
    }
}
