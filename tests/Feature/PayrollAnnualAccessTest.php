<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 全年发放明细权限：项目范围账号一律拒绝（不靠过滤管理行兜底），并留越权审计日志。
 */
class PayrollAnnualAccessTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-05';
    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9201, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9202, 'username' => 'jia_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('n', 64);
        $this->projToken = str_repeat('m', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_staff')->insert([
            'legacy_id' => 501, 'name' => '经理甲', 'project_name' => '甲项目', 'position' => '经理',
            'status' => '正式', 'person_type' => 'manager', 'deleted' => false,
            'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['员工甲', 1000, false], ['经理甲', 5000, true]] as $i => [$name, $gross, $mgr]) {
            DB::table('payroll_results')->insert([
                'year_month' => self::YM, 'project_name' => '甲项目', 'staff_legacy_id' => 500 + $i,
                'row_data' => json_encode(['project' => '甲项目', 'name' => $name, 'gross' => $gross], JSON_UNESCAPED_UNICODE),
                'archived' => true, 'is_manager_row' => $mgr, 'is_case_row' => false, 'is_hq_row' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_project_scope_is_forbidden_and_audited(): void
    {
        Log::spy();

        $data = $this->get('/api/payroll/annual?year=2026', ['X-Token' => $this->projToken]);
        $data->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertStringContainsString('仅总部', (string) $data->json('error'));

        $export = $this->get('/api/payroll/annual/export?year=2026', ['X-Token' => $this->projToken]);
        $export->assertStatus(403)->assertJson(['ok' => false]);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            return $message === 'payroll.annual.forbidden'
                && ($context['action'] ?? '') === 'data'
                && ($context['project'] ?? '') === '甲项目'
                && ($context['path'] ?? '') !== '';
        })->once();
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            return $message === 'payroll.annual.forbidden' && ($context['action'] ?? '') === 'export';
        })->once();
    }

    public function test_admin_can_view_annual_include_manager_rows(): void
    {
        $resp = $this->get('/api/payroll/annual?year=2026', ['X-Token' => $this->adminToken]);
        $resp->assertOk()->assertJson(['ok' => true, 'year' => 2026]);
        $names = array_column($resp->json('rows'), 'name');
        $this->assertContains('员工甲', $names);
        $this->assertContains('经理甲', $names, '总部账号应能看到全部人员（含管理人员）');
    }
}
