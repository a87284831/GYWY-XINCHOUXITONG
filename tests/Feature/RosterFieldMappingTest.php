<?php

namespace Tests\Feature;

use App\Services\DingtalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 花名册字段映射专项（2026-10-01「测试人员」排查衍生修复）：
 * - 岗位职级精确映射（trim 后匹配），未知值不得静默降级为 staff（缺陷B）
 * - 月度薪资标准/月度基本工资 正确写入 fixed_monthly/base_salary
 * - 在职人员在花名册接口无返回时，同步报告必须暴露 roster_missing（观测盲区）
 *
 * 根因备注：本次「测试人员」异常本身是钉钉侧未做智能人事入职登记（花名册无此员工档案），
 * 非代码 bug；本测试锁住映射行为与可观测性，防止同类问题静默。
 */
class RosterFieldMappingTest extends TestCase
{
    use RefreshDatabase;

    private $dt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dt = $this->getMockBuilder(DingtalkService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig', 'getAllDepartments', 'getAllUsers',
                           'getDismissedUsers', 'getDismissedUserInfos', 'getRosterData', 'getDeptPath'])
            ->getMock();
        $this->app->instance(DingtalkService::class, $this->dt);
        Cache::forget('dingtalk_depts_tree');
    }

    private function seedActiveStaff(string $uid = 'u1', array $overrides = []): int
    {
        $maxId = (int) DB::table('payroll_staff')->max('legacy_id') + 1;
        $row = array_merge([
            'legacy_id' => $maxId, 'dingtalk_userid' => $uid, 'name' => '对照员工',
            'project_name' => '测试项目A', 'status' => '试用',
            'fixed_monthly' => 0, 'base_salary' => 0,
            'deleted' => false, 'is_manager' => false, 'is_case_field' => false,
            'person_type' => 'staff',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
        DB::table('payroll_staff')->insert($row);
        return (int) DB::table('payroll_staff')->where('dingtalk_userid', $uid)->value('id');
    }

    private function mockSyncBase(array $rosterReturn): void
    {
        $this->dt->method('getAllDepartments')->willReturn([
            1 => ['name' => '万城服务', 'parent_id' => 0, 'level' => 1],
            2 => ['name' => '测试项目A', 'parent_id' => 1, 'level' => 2],
        ]);
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getAllUsers')->willReturn([
            'u1' => ['name' => '对照员工', 'title' => '保安', '_dept_ids' => [2], 'hired_date' => 0, 'mobile' => ''],
        ]);
        $this->dt->method('getDismissedUsers')->willReturn([]);
        $this->dt->method('getDismissedUserInfos')->willReturn([]);
        $this->dt->method('getRosterData')->willReturn($rosterReturn);
    }

    private function runSync(): array
    {
        return app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();
    }

    private function row(int $id): ?\stdClass
    {
        return DB::table('payroll_staff')->where('id', $id)->first(['person_type', 'fixed_monthly', 'base_salary']);
    }

    public function test_position_level_hq_and_salary_values_mapped(): void
    {
        $id = $this->seedActiveStaff();
        $this->mockSyncBase([
            'u1' => ['岗位职级' => '总部人员', '月度薪资标准' => '6000', '月度基本工资' => '3000'],
        ]);

        $this->runSync();

        $r = $this->row($id);
        $this->assertSame('hq', $r->person_type);
        $this->assertSame('6000', (string) $r->fixed_monthly);
        $this->assertSame('3000', (string) $r->base_salary);
    }

    public function test_position_level_with_whitespace_is_trimmed(): void
    {
        $id = $this->seedActiveStaff();
        $this->mockSyncBase(['u1' => ['岗位职级' => '总部人员 ']]);

        $this->runSync();

        $this->assertSame('hq', $this->row($id)->person_type);
    }

    public function test_unknown_position_level_not_silently_downgraded(): void
    {
        $id = $this->seedActiveStaff('u1', ['person_type' => 'hq']);
        $this->mockSyncBase(['u1' => ['岗位职级' => '神秘职级', '月度薪资标准' => '6000']]);

        $this->runSync();

        // 未知值不得覆盖为 staff（静默降级）；薪资字段照常写入
        $r = $this->row($id);
        $this->assertSame('hq', $r->person_type);
        $this->assertSame('6000', (string) $r->fixed_monthly);
    }

    public function test_base_level_option_maps_explicitly(): void
    {
        $id = $this->seedActiveStaff('u1', ['person_type' => 'hq']);
        $this->mockSyncBase(['u1' => ['岗位职级' => '基层人员']]);

        $this->runSync();

        $this->assertSame('staff', $this->row($id)->person_type);
    }

    public function test_missing_roster_reported_in_stats(): void
    {
        $this->seedActiveStaff();
        // 花名册接口对该在职人员无返回（如未做智能人事入职登记）
        $this->mockSyncBase([]);

        $report = $this->runSync();

        $this->assertSame(1, $report['roster_missing']);
        $this->assertSame(1, $report['roster_total']);
    }

    public function test_present_roster_has_no_missing(): void
    {
        $this->seedActiveStaff();
        $this->mockSyncBase(['u1' => ['性别' => '男']]);

        $report = $this->runSync();

        $this->assertSame(0, $report['roster_missing']);
    }

    public function test_planned_regular_date_synced_to_data(): void
    {
        // 「计划转正日期」离职后仍保留，是无实际转正日离职人员试用期判定的兜底信号
        $id = $this->seedActiveStaff('u1', ['status' => '离职']);
        $this->mockSyncBase(['u1' => ['计划转正日期' => '2026-10-29']]);

        $this->runSync();

        $data = json_decode((string) DB::table('payroll_staff')->where('id', $id)->value('data'), true);
        $this->assertSame('2026-10-29', $data['planned_regular_date'] ?? null);
    }
}
