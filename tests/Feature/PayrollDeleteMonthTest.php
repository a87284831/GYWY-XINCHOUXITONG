<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 薪资核算整月删除（仅 admin、归档拦截、409/force 二次确认、删除审计日志）。
 */
class PayrollDeleteMonthTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';
    private const OTHER_YM = '2026-09';
    private const PROJ = '测试项目';

    private string $token;
    private string $projToken;

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

        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
                'permissions' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj_user', 'name' => '项目人力',
            'role' => 'project', 'project_name' => self::PROJ, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->projToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $this->projToken, $pid, now()->addHours(8));
    }

    private function insertResultRow(string $ym, int $staffId, string $name, bool $archived = false, string $type = 'staff', string $project = self::PROJ): void
    {
        $rowData = ['staff_id' => $staffId, 'name' => $name, 'project' => $project,
            'gross' => 8000.0, 'net' => 6500.0, 'actual_tax' => 100.0];
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => $project,
            'row_data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
            'archived' => $archived,
            'is_manager_row' => $type === 'manager' ? 1 : 0,
            'is_case_row' => $type === 'case' ? 1 : 0,
            'is_hq_row' => $type === 'hq' ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertAdjustLog(string $ym, int $staffId, string $name): void
    {
        DB::table('payroll_adjust_logs')->insert([
            'staff_legacy_id' => $staffId, 'staff_name' => $name, 'project_name' => self::PROJ,
            'ym' => $ym, 'changes' => json_encode([['field' => 'meal', 'old' => 0, 'new' => 100]]),
            'row_after' => json_encode(['net' => 6600.0]), 'reason' => '测试微调',
            'operator' => '总部管理员', 'operator_role' => 'admin', 'created_at' => now(),
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true])
            ->assertStatus(401);
    }

    public function test_project_account_returns_403(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->projToken])
            ->assertStatus(403);
    }

    public function test_invalid_ym_returns_400(): void
    {
        $this->postJson('/api/payroll/delete-month', ['ym' => '2026/08', 'force' => true],
            ['X-Token' => $this->token])
            ->assertStatus(400);
    }

    public function test_no_data_returns_404(): void
    {
        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token])
            ->assertStatus(404);
    }

    public function test_archived_month_returns_400(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A', archived: true);
        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertStatus(400);
        $this->assertStringContainsString('已归档', $resp->json('error'));
    }

    public function test_first_call_returns_409_with_scope_stats(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::YM, 2, '员工B');
        $this->insertResultRow(self::YM, 3, '经理A', type: 'manager');
        $this->insertAdjustLog(self::YM, 1, '员工A');
        $this->insertAdjustLog(self::YM, 3, '经理A');

        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM],
            ['X-Token' => $this->token]);
        $resp->assertStatus(409);
        $json = $resp->json();
        $this->assertFalse($json['ok']);
        $this->assertTrue($json['need_confirm']);
        $scope = $json['scope'];
        // 默认 staff 维度：仅统计员工行与员工微调日志
        $this->assertSame('staff', $scope['scope_type']);
        $this->assertSame('员工', $scope['scope_label']);
        $this->assertSame(2, $scope['total_rows']);
        $this->assertSame(2, $scope['staff_rows']);
        $this->assertSame(0, $scope['manager_rows']);
        $this->assertSame(1, $scope['adjust_logs']);
        $this->assertContains(self::PROJ, $scope['projects']);
        $this->assertEquals(16000.0, $scope['total_gross']);
        $this->assertEquals(13000.0, $scope['total_net']);
    }

    public function test_force_delete_scoped_clears_only_scope_and_writes_log(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::YM, 2, '经理A', type: 'manager');
        $this->insertAdjustLog(self::YM, 1, '员工A');
        $this->insertAdjustLog(self::YM, 2, '经理A');

        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);

        // 范围隔离：员工行与员工A微调日志被删，管理行与经理A日志保留
        $remaining = DB::table('payroll_results')->where('year_month', self::YM)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(1, (int) $remaining->first()->is_manager_row);
        $logs = DB::table('payroll_adjust_logs')->where('ym', self::YM)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(2, (int) $logs->first()->staff_legacy_id);

        $log = DB::table('payroll_delete_logs')->where('ym', self::YM)->first();
        $this->assertNotNull($log);
        $this->assertSame('总部管理员', $log->operator);
        $this->assertSame('admin', $log->operator_role);
        $scope = json_decode($log->scope_info, true);
        $this->assertSame('staff', $scope['scope_type']);
        $this->assertSame(1, $scope['total_rows']);
        $this->assertSame(1, $scope['staff_rows']);
        $this->assertSame(0, $scope['manager_rows']);
        $this->assertSame(1, $scope['adjust_logs']);
    }

    public function test_delete_manager_scope_only_removes_manager_rows(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::YM, 2, '经理A', type: 'manager');
        $this->insertResultRow(self::YM, 3, '经理B', type: 'manager');
        $this->insertAdjustLog(self::YM, 1, '员工A');
        $this->insertAdjustLog(self::YM, 2, '经理A');

        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'type' => 'manager', 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(2, $resp->json('deleted.total_rows'));
        $this->assertSame('manager', $resp->json('deleted.scope_type'));
        $this->assertSame('管理人员', $resp->json('deleted.scope_label'));

        $remaining = DB::table('payroll_results')->where('year_month', self::YM)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(0, (int) $remaining->first()->is_manager_row);
        $this->assertSame('员工A', json_decode($remaining->first()->row_data, true)['name']);
        // 微调日志：经理A的随删除清除，员工A的保留
        $logs = DB::table('payroll_adjust_logs')->where('ym', self::YM)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(1, (int) $logs->first()->staff_legacy_id);
    }

    public function test_invalid_type_returns_400(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'type' => 'bogus', 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertStatus(400);
    }

    public function test_scope_without_data_returns_404(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'type' => 'hq', 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertStatus(404);
        $this->assertStringContainsString('总部人员', $resp->json('error'));
    }

    public function test_archived_other_scope_does_not_block(): void
    {
        // 管理行已归档，但删除员工维度不受影响
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::YM, 2, '经理A', type: 'manager', archived: true);

        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token])->assertOk();

        $this->assertSame(1, DB::table('payroll_results')->where('year_month', self::YM)->count());
    }

    public function test_archived_same_scope_blocks(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::YM, 2, '经理A', type: 'manager', archived: true);

        $resp = $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'type' => 'manager', 'force' => true],
            ['X-Token' => $this->token]);
        $resp->assertStatus(400);
        $this->assertStringContainsString('已归档', $resp->json('error'));
        $this->assertSame(2, DB::table('payroll_results')->where('year_month', self::YM)->count());
    }

    public function test_delete_does_not_affect_other_months(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->insertResultRow(self::OTHER_YM, 1, '员工A');
        $this->insertAdjustLog(self::YM, 1, '员工A');
        $this->insertAdjustLog(self::OTHER_YM, 1, '员工A');

        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token])->assertOk();

        $this->assertSame(0, DB::table('payroll_results')->where('year_month', self::YM)->count());
        $this->assertSame(1, DB::table('payroll_results')->where('year_month', self::OTHER_YM)->count());
        $this->assertSame(0, DB::table('payroll_adjust_logs')->where('ym', self::YM)->count());
        $this->assertSame(1, DB::table('payroll_adjust_logs')->where('ym', self::OTHER_YM)->count());
    }

    public function test_delete_logs_api_requires_admin(): void
    {
        $this->getJson('/api/payroll/delete-logs', ['X-Token' => $this->projToken])->assertStatus(403);
    }

    public function test_delete_logs_api_returns_logs(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token])->assertOk();

        $resp = $this->getJson('/api/payroll/delete-logs', ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $logs = $resp->json('logs');
        $this->assertCount(1, $logs);
        $this->assertSame(self::YM, $logs[0]['ym']);
        $this->assertSame('总部管理员', $logs[0]['operator']);
        $this->assertSame(1, $logs[0]['scope']['total_rows']);
    }

    public function test_delete_logs_export_requires_admin(): void
    {
        $this->getJson('/api/payroll/delete-logs/export', ['X-Token' => $this->projToken])->assertStatus(403);
    }

    public function test_delete_logs_export_returns_xlsx(): void
    {
        $this->insertResultRow(self::YM, 1, '员工A');
        $this->postJson('/api/payroll/delete-month', ['ym' => self::YM, 'force' => true],
            ['X-Token' => $this->token])->assertOk();

        $resp = $this->get('/api/payroll/delete-logs/export', ['X-Token' => $this->token]);
        $resp->assertOk();
        $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
    }
}
