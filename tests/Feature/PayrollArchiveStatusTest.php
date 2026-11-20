<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 导出前只读归档状态查询：项目账号在点击导出前即可知道本月是否已归档/已核算。
 */
class PayrollArchiveStatusTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9601, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9602, 'username' => 'jia_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('x', 64);
        $this->projToken = str_repeat('y', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertRow('甲项目', false);
        $this->insertRow('乙项目', false);
    }

    private function insertRow(string $project, bool $archived): void
    {
        static $sid = 700;
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $project, 'staff_legacy_id' => $sid++,
            'row_data' => json_encode(['project' => $project, 'name' => '员工', 'gross' => 1000], JSON_UNESCAPED_UNICODE),
            'archived' => $archived, 'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_project_scope_sees_unarchived_state(): void
    {
        $resp = $this->get('/api/payroll/archive-status?ym=' . self::YM, ['X-Token' => $this->projToken]);
        $resp->assertOk()->assertJson([
            'ok' => true, 'ym' => self::YM, 'project' => '甲项目',
            'calculated' => true, 'archived' => false,
        ]);
    }

    public function test_project_scope_sees_archived_state(): void
    {
        DB::table('payroll_results')->where('project_name', '甲项目')->update(['archived' => true]);
        $resp = $this->get('/api/payroll/archive-status?ym=' . self::YM, ['X-Token' => $this->projToken]);
        $resp->assertOk()->assertJson(['calculated' => true, 'archived' => true]);
    }

    public function test_project_scope_not_calculated_when_no_rows(): void
    {
        $resp = $this->get('/api/payroll/archive-status?ym=2026-09', ['X-Token' => $this->projToken]);
        $resp->assertOk()->assertJson(['calculated' => false, 'archived' => false]);
    }

    public function test_admin_requires_all_projects_archived(): void
    {
        $resp = $this->get('/api/payroll/archive-status?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk()->assertJson(['project' => null, 'archived' => false]);

        DB::table('payroll_results')->where('year_month', self::YM)->update(['archived' => true]);
        $resp = $this->get('/api/payroll/archive-status?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk()->assertJson(['archived' => true]);
    }

    public function test_invalid_month_is_rejected(): void
    {
        $this->get('/api/payroll/archive-status?ym=2026-13', ['X-Token' => $this->projToken])
            ->assertStatus(400);
    }
}
