<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 微调日志（问题4）：
 *  - 每次微调成功写一条 payroll_adjust_logs（字段旧值→新值、原因、操作人、关键合计快照）
 *  - 同一人员多次微调不同字段 → 多条日志，后一条 old 等于前一条 new（追加而非覆盖）
 *  - /api/payroll 列表按当月行人员口径附带 logs；弹窗单人历史接口按 staff_id 查询
 *  - 项目账号：微调 403（既有规则），但日志只读可见（工资页只读视图可审计）
 */
class PayrollAdjustLogTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-07';
    private const PROJ = '测试项目';

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
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_staff')->insert([
            'legacy_id' => 2, 'name' => '正常人', 'project_name' => self::PROJ, 'position' => '测试岗',
            'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '初始', 'note' => '']],
                'special_deductions' => [], 'regular_date' => '', 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $d = array_fill(0, 31, '休');
        for ($i = 0; $i < 20; $i++) $d[$i] = '√';
        $att = ['days' => $d, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => ''];
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ, 'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode(['正常人' => $att], JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculate(self::YM, [self::PROJ]);
    }

    private function adjust(array $fields, string $reason, string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payroll/adjust', [
            'ym' => self::YM, 'staff_id' => 2, 'fields' => $fields, 'reason' => $reason,
        ], ['X-Token' => $token ?? $this->adminToken]);
    }

    public function test_each_adjust_appends_log_with_old_new_reason_and_operator(): void
    {
        // 第一次：月度奖励 100
        $this->adjust(['reward' => 100], '第一次加奖')->assertOk();
        // 第二次：缺卡扣款 30（不同字段，不得覆盖第一条日志）
        $this->adjust(['miss_d' => 30], '第二次缺卡')->assertOk();

        $logs = DB::table('payroll_adjust_logs')->where('ym', self::YM)->orderBy('id')->get();
        $this->assertCount(2, $logs, '两次微调应产生两条追加日志');

        $first = json_decode((string)$logs[0]->changes, true);
        $second = json_decode((string)$logs[1]->changes, true);
        $this->assertSame('reward', $first[0]['field']);
        $this->assertEqualsWithDelta(0.0, (float)$first[0]['old'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float)$first[0]['new'], 0.001);
        $this->assertSame('miss_d', $second[0]['field']);
        $this->assertEqualsWithDelta(0.0, (float)$second[0]['old'], 0.001);
        $this->assertEqualsWithDelta(30.0, (float)$second[0]['new'], 0.001);

        $this->assertSame('第一次加奖', $logs[0]->reason);
        $this->assertSame('第二次缺卡', $logs[1]->reason);
        $this->assertSame('总部管理员', $logs[0]->operator);
        $this->assertSame('admin', $logs[0]->operator_role);
        $this->assertSame('正常人', $logs[0]->staff_name);
        $this->assertSame(self::PROJ, $logs[0]->project_name);

        // row_after 只存关键合计快照（gross/net/actual_tax），不存整行
        $snap = json_decode((string)$logs[1]->row_after, true);
        $this->assertArrayHasKey('gross', $snap);
        $this->assertArrayHasKey('net', $snap);
        $this->assertArrayHasKey('actual_tax', $snap);
    }

    public function test_payroll_list_returns_logs_for_rows_in_scope(): void
    {
        $this->adjust(['reward' => 100], '加奖')->assertOk();

        $resp = $this->getJson('/api/payroll?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $logs = $resp->json('logs');
        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs);
        $this->assertSame('正常人', $logs[0]['staff_name']);
        $this->assertSame('加奖', $logs[0]['reason']);
    }

    public function test_staff_history_endpoint_returns_one_person_logs(): void
    {
        $this->adjust(['reward' => 100], '第一条')->assertOk();
        $this->adjust(['punish' => 50], '第二条')->assertOk();

        $resp = $this->getJson('/api/payroll/adjust-logs?staff_id=2', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $items = $resp->json('logs');
        $this->assertCount(2, $items);
        // 时间倒序（最新在前）
        $this->assertSame('第二条', $items[0]['reason']);
        $this->assertSame('第一条', $items[1]['reason']);
    }

    public function test_project_account_cannot_adjust_but_can_read_logs(): void
    {
        $this->adjust(['reward' => 100], '管理员调的')->assertOk();

        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj_user', 'name' => '项目账号',
            'role' => 'project', 'project_name' => self::PROJ, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $pToken, $pid, now()->addHours(8));

        // 微调被拒
        $this->adjust(['reward' => 999], '项目账号想调', $pToken)->assertStatus(403);
        // 总部核定（归档）完成后，项目账号才可只读查看本项目工资及微调日志
        DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 2)
            ->update(['archived' => true]);
        $resp = $this->getJson('/api/payroll/adjust-logs?staff_id=2', ['X-Token' => $pToken]);
        $resp->assertOk();
        $this->assertNotEmpty($resp->json('logs'));
    }

    public function test_reason_optional_defaults_to_empty_string(): void
    {
        $this->adjust(['reward' => 10], '')->assertOk();
        $this->assertSame('', (string) DB::table('payroll_adjust_logs')->where('ym', self::YM)->value('reason'));
    }
}
