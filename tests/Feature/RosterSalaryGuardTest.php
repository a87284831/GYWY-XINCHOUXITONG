<?php

namespace Tests\Feature;

use App\Services\DingtalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 花名册薪资字段防护（2026-10-04 线上事故）：
 * 钉钉花名册「月度薪资标准/月度基本工资」返回非数值（如布尔 true、文本"面议"）时，
 * 不得把脏值写入 decimal 列导致 SQL 1366 整批同步崩溃；应跳过该字段、保留本地原值、
 * 其余字段照常更新，同步报告带回可读的人员级警告。
 * 同时逐人隔离 applyRosterFields 异常，单个人的坏数据不得阻断后续人员同步。
 */
class RosterSalaryGuardTest extends TestCase
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

    private function seedStaff(string $uid, string $name, array $overrides = []): int
    {
        static $seq = 0;
        $seq++;
        DB::table('payroll_staff')->insert(array_merge([
            'legacy_id' => 7000 + $seq, 'dingtalk_userid' => $uid, 'name' => $name,
            'project_name' => '测试项目A', 'status' => '试用', 'position' => '旧岗位',
            'fixed_monthly' => 4500, 'base_salary' => 2000,
            'deleted' => false, 'is_manager' => false, 'is_case_field' => false,
            'person_type' => 'staff',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
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
            'u1' => ['name' => '员工甲', 'title' => '保洁', '_dept_ids' => [2], 'hired_date' => 0, 'mobile' => ''],
            'u2' => ['name' => '员工乙', 'title' => '保安', '_dept_ids' => [2], 'hired_date' => 0, 'mobile' => ''],
        ]);
        $this->dt->method('getDismissedUsers')->willReturn([]);
        $this->dt->method('getDismissedUserInfos')->willReturn([]);
        $this->dt->method('getRosterData')->willReturn($rosterReturn);
    }

    private function runSync(): array
    {
        return app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();
    }

    public function test_boolean_true_salary_skipped_keeps_local_value_and_reports_warning(): void
    {
        $id1 = $this->seedStaff('u1', '员工甲');
        $id2 = $this->seedStaff('u2', '员工乙');
        $this->mockSyncBase([
            'u1' => ['岗位职级' => '管理人员', '月度薪资标准' => true, '月度基本工资' => false],
            'u2' => ['岗位职级' => '基层人员', '月度薪资标准' => '5000'],
        ]);

        $report = $this->runSync(); // 不抛异常即通过第一层

        $r1 = DB::table('payroll_staff')->where('id', $id1)->first(['fixed_monthly', 'base_salary', 'person_type', 'position']);
        // 坏薪资字段不写入，本地原值保留；其余字段（职级/岗位）照常更新
        $this->assertSame('4500', (string) $r1->fixed_monthly);
        $this->assertSame('2000', (string) $r1->base_salary);
        $this->assertSame('manager', $r1->person_type);
        $this->assertSame('保洁', $r1->position);
        // 后续人员不受影响
        $r2 = DB::table('payroll_staff')->where('id', $id2)->first(['fixed_monthly', 'person_type']);
        $this->assertSame('5000', (string) $r2->fixed_monthly);
        $this->assertSame('staff', $r2->person_type);

        $this->assertSame(2, $report['roster_field_errors']);
        $details = array_map(fn ($i) => $i['name'] . '|' . $i['detail'], $report['roster_field_error_items']);
        $this->assertContains('员工甲|月度薪资标准 = true', $details);
        $this->assertContains('员工甲|月度基本工资 = false', $details);
    }

    public function test_garbage_text_salary_skipped(): void
    {
        $id1 = $this->seedStaff('u1', '员工甲');
        $this->seedStaff('u2', '员工乙');
        $this->mockSyncBase(['u1' => ['月度薪资标准' => '面议']]);

        $report = $this->runSync();

        $this->assertSame('4500', (string) DB::table('payroll_staff')->where('id', $id1)->value('fixed_monthly'));
        $this->assertSame(1, $report['roster_field_errors']);
        $this->assertStringContainsString('面议', $report['roster_field_error_items'][0]['detail']);
    }

    public function test_numeric_salary_variants_written_normally(): void
    {
        $id1 = $this->seedStaff('u1', '员工甲');
        $id2 = $this->seedStaff('u2', '员工乙');
        $this->mockSyncBase([
            'u1' => ['月度薪资标准' => 6000, '月度基本工资' => '3000.50'],
            'u2' => ['月度薪资标准' => 0], // 0 是有效薪资
        ]);

        $this->runSync();

        $r1 = DB::table('payroll_staff')->where('id', $id1)->first(['fixed_monthly', 'base_salary']);
        $this->assertSame('6000', (string) $r1->fixed_monthly);
        // 测试库 SQLite 不保留小数尾零（生产 MySQL DECIMAL 会存 3000.50），按数值比较
        $this->assertEqualsWithDelta(3000.5, (float) $r1->base_salary, 0.001);
        $this->assertSame('0', (string) DB::table('payroll_staff')->where('id', $id2)->value('fixed_monthly'));
    }

    public function test_one_person_exception_does_not_block_later_people(): void
    {
        $this->seedStaff('u1', '员工甲');
        $id2 = $this->seedStaff('u2', '员工乙');
        // 无法解析的离职日期会在 applyRosterFields 内抛 DateTime 异常
        $this->mockSyncBase([
            'u1' => ['离职日期' => '胡说八道'],
            'u2' => ['岗位职级' => '案场人员'],
        ]);

        $report = $this->runSync();

        $this->assertSame('case', DB::table('payroll_staff')->where('id', $id2)->value('person_type'));
        $this->assertGreaterThanOrEqual(1, $report['roster_field_errors']);
        $this->assertSame('员工甲', $report['roster_field_error_items'][0]['name']);
    }

    public function test_clean_sync_reports_zero_warnings(): void
    {
        $this->seedStaff('u1', '员工甲');
        $this->seedStaff('u2', '员工乙');
        $this->mockSyncBase([
            'u1' => ['月度薪资标准' => '4000'],
            'u2' => ['性别' => '男'],
        ]);

        $report = $this->runSync();

        $this->assertSame(0, $report['roster_field_errors']);
        $this->assertSame([], $report['roster_field_error_items']);
    }
}
