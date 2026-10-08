<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 选人下拉数据源 /api/org/staff-options：
 *  - 不得截断：超过 300 人时必须全量返回（生产 1408 人曾因 limit(300) 只见 6/18 个项目）
 *  - 离职人员不显示（生产 509 个"未分配项目"档案里 493 个是离职，曾混入选人下拉）
 *  - 项目账号仍只见本项目人员
 *  - kw 关键词搜索（姓名/岗位）不受影响
 */
class StaffOptionsTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function seedStaff(int $n, array $projects): void
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'legacy_id' => 10000 + $i, 'name' => '员工' . $i,
                'project_name' => $projects[$i % count($projects)], 'position' => '操作工',
                'status' => '正式', 'fixed_monthly' => 5000, 'base_salary' => 4000,
                'hire_date' => '2026-01-01', 'resign_date' => null,
                'deleted' => false, 'person_type' => 'staff', 'data' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('payroll_staff')->insert($chunk);
        }
    }

    public function test_returns_all_staff_beyond_300_across_projects(): void
    {
        $this->seedStaff(305, ['甲项目', '乙项目', '丙项目']);

        $resp = $this->getJson('/api/org/staff-options', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $staff = $resp->json('staff');

        $this->assertCount(305, $staff, '超过300人不得截断');
        $projs = array_unique(array_column($staff, 'project'));
        sort($projs);
        $this->assertSame(['丙项目', '乙项目', '甲项目'], array_values($projs), '所有项目的人都应可见');
    }

    public function test_excludes_resigned_staff(): void
    {
        $this->seedStaff(3, ['甲项目']);
        DB::table('payroll_staff')->where('legacy_id', 10001)->update(['status' => '离职']);
        DB::table('payroll_staff')->where('legacy_id', 10002)->update(['status' => '试用']);

        $resp = $this->getJson('/api/org/staff-options', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $names = array_column($resp->json('staff'), 'name');

        $this->assertNotContains('员工1', $names, '离职人员不得出现在选人下拉');
        $this->assertContains('员工2', $names, '试用人员应显示');
        $this->assertContains('员工3', $names, '正式人员应显示');
    }

    public function test_kw_search_still_filters_by_name_or_position(): void
    {
        $this->seedStaff(20, ['甲项目']);
        DB::table('payroll_staff')->where('legacy_id', 10001)->update(['name' => '张特殊']);
        DB::table('payroll_staff')->where('legacy_id', 10002)->update(['position' => '电气工程师']);

        $resp = $this->getJson('/api/org/staff-options?kw=' . urlencode('特殊'), ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $staff = $resp->json('staff');
        $this->assertCount(1, $staff);
        $this->assertSame('张特殊', $staff[0]['name']);

        $resp2 = $this->getJson('/api/org/staff-options?kw=' . urlencode('电气'), ['X-Token' => $this->adminToken]);
        $staff2 = $resp2->json('staff');
        $this->assertCount(1, $staff2);
        $this->assertSame('员工2', $staff2[0]['name']);
    }

    public function test_project_account_only_sees_own_project(): void
    {
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj_user', 'name' => '项目账号',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $pToken, $pid, now()->addHours(8));

        $this->seedStaff(6, ['甲项目', '乙项目']);

        $resp = $this->getJson('/api/org/staff-options', ['X-Token' => $pToken]);
        $resp->assertOk();
        $staff = $resp->json('staff');
        $this->assertCount(3, $staff, '项目账号只应看到本项目3人');
        $this->assertSame(['甲项目'], array_values(array_unique(array_column($staff, 'project'))));
    }
}
