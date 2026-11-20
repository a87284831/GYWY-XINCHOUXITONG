<?php

namespace Tests\Feature;

use App\Services\DingtalkService;
use App\Services\StaffCategoryMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 人员列表「已归档，仍计在旧分类」标记：
 * - 钉钉同步改分类后，已归档月份被标记；
 * - 未归档月份不产生标记（行已随分类迁移）；
 * - 多个已归档月份合并展示。
 */
class StaffArchivedMarkerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9701, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('z', 64);
        Cache::put('payroll_api_token:' . $this->token, $adminId, now()->addHours(8));
        Cache::forget('dingtalk_depts_tree');
    }

    private function seedStaff(string $uid = 'u1', string $type = 'staff', string $project = '甲项目'): int
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => 101, 'dingtalk_userid' => $uid, 'name' => '张三',
            'project_name' => $project, 'status' => '正式',
            'fixed_monthly' => 0, 'base_salary' => 0,
            'deleted' => false, 'is_manager' => false, 'is_case_field' => false,
            'person_type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return 101;
    }

    private function seedResult(string $ym, bool $archived = false, string $project = '甲项目'): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'project_name' => $project, 'staff_legacy_id' => 101,
            'row_data' => json_encode(['project' => $project, 'name' => '张三', 'gross' => 1000], JSON_UNESCAPED_UNICODE),
            'archived' => $archived, 'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mockDingtalk(string $level, ?array $depts = null): void
    {
        $dt = $this->getMockBuilder(DingtalkService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig', 'getAllDepartments', 'getAllUsers',
                           'getDismissedUsers', 'getDismissedUserInfos', 'getRosterData', 'getDeptPath'])
            ->getMock();
        $dt->method('getAllDepartments')->willReturn($depts ?? [
            1 => ['name' => '万城服务', 'parent_id' => 0, 'level' => 1],
            2 => ['name' => '甲项目', 'parent_id' => 1, 'level' => 2],
        ]);
        $dt->method('getDeptPath')->willReturn(['万城服务', '甲项目']);
        $dt->method('getAllUsers')->willReturn([
            'u1' => ['name' => '张三', 'title' => '保安', '_dept_ids' => [2], 'hired_date' => 0, 'mobile' => ''],
        ]);
        $dt->method('getDismissedUsers')->willReturn([]);
        $dt->method('getDismissedUserInfos')->willReturn([]);
        $dt->method('getRosterData')->willReturn(['u1' => ['岗位职级' => $level]]);
        $this->app->instance(DingtalkService::class, $dt);
    }

    private function staffRow(): object
    {
        $resp = $this->get('/api/staff', ['X-Token' => $this->token]);
        $resp->assertOk();
        $row = collect($resp->json('staff'))->firstWhere('id', 101);
        $this->assertNotNull($row, '人员列表应返回该员工');
        return (object) $row;
    }

    public function test_dingtalk_sync_category_change_marks_archived_months(): void
    {
        $this->seedStaff();
        $this->seedResult('2026-09', true);
        $this->mockDingtalk('管理人员');

        app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();

        $this->assertSame('manager', DB::table('payroll_staff')->where('legacy_id', 101)->value('person_type'),
            '钉钉同步应完成分类变更');

        $row = $this->staffRow();
        $this->assertSame(['2026-09'], $row->archived_months);
        $this->assertSame('2026-09 已归档，仍计在基层', $row->archived_notice);
        $this->assertSame('基层', $row->archived_from_label);
    }

    public function test_unarchived_months_do_not_produce_marker(): void
    {
        $this->seedStaff();
        $this->seedResult('2026-09', false);
        $this->mockDingtalk('管理人员');

        app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();

        // 未归档行已随分类迁移，没有「仍计在旧分类」的残留
        $this->assertSame(1, (int) DB::table('payroll_results')->where('staff_legacy_id', 101)
            ->where('is_manager_row', true)->count());

        $row = $this->staffRow();
        $this->assertSame([], $row->archived_months);
        $this->assertSame('', $row->archived_notice);
    }

    public function test_multiple_archived_months_are_listed(): void
    {
        $this->seedStaff();
        $this->seedResult('2026-08', true);
        $this->seedResult('2026-09', true);
        $this->seedResult('2026-10', false);

        DB::transaction(fn () => (new StaffCategoryMigration())->migrate(
            101, '张三', 'staff', '甲项目', 'case', '甲项目', 'admin', 'staff_edit', '测试', '2026-01-01'
        ));

        $row = $this->staffRow();
        $this->assertSame(['2026-08', '2026-09'], $row->archived_months);
        $this->assertSame('2026-08、2026-09 已归档，仍计在基层', $row->archived_notice);
    }
}
