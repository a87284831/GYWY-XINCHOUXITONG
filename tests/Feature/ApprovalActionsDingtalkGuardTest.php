<?php

namespace Tests\Feature;

use App\Services\ApprovalActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 审批通过联动动作的钉钉权威源护栏（2026-10-01 用户决策 A）：
 * 绑定钉钉的人员——
 *  - 转正审批通过：不写档案（regular_date/position/fixed_monthly/base_salary/salary_history），
 *    以钉钉智能人事/花名册为准，由同步回写；OA 审批单照常通过并留痕；
 *  - 离职审批通过：不写档案离职字段（resign_date/status），但仍停用系统登录账号（访问安全事务）；
 * 未绑定的历史行/本地行维持原联动行为。
 */
class ApprovalActionsDingtalkGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('payroll_projects')->insert([
            'name' => '测试项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedStaff(int $id, ?string $dtUid): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'dingtalk_userid' => $dtUid, 'name' => '员工' . $id,
            'project_name' => '测试项目', 'position' => '保安', 'status' => '试用',
            'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2026-01-01', 'regular_date' => null, 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAccount(int $staffId): void
    {
        DB::table('payroll_accounts')->insert([
            'legacy_id' => 9000 + $staffId, 'staff_id' => $staffId,
            'username' => 'u' . $staffId, 'name' => '员工' . $staffId,
            'role' => 'staff', 'project_name' => '测试项目', 'password_hash' => 'x',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(): object
    {
        return (object) ['username' => 'admin', 'role' => 'admin'];
    }

    public function test_bound_staff_regular_approval_does_not_touch_profile(): void
    {
        $this->seedStaff(1, 'dt-1');

        $result = ApprovalActions::handle('regular_approval', [
            'staff_id' => 1, 'name' => '员工1', 'regular_date' => '2026-04-01',
            'new_position' => '保安队长', 'fixed_monthly' => 8000, 'base_salary' => 6500,
        ], '测试项目', $this->account());

        $this->assertSame('dingtalk_authoritative', $result['skipped'] ?? null);
        $row = DB::table('payroll_staff')->where('legacy_id', 1)->first();
        $this->assertSame('保安', $row->position);
        $this->assertSame('试用', $row->status);
        $this->assertSame(6000.0, (float) $row->fixed_monthly);
        $this->assertSame(5000.0, (float) $row->base_salary);
        $this->assertNull($row->regular_date);
        $data = json_decode((string) $row->data, true);
        $this->assertArrayNotHasKey('salary_history', $data);
    }

    public function test_unbound_staff_regular_approval_still_updates_profile(): void
    {
        $this->seedStaff(2, null);

        $result = ApprovalActions::handle('regular_approval', [
            'staff_id' => 2, 'name' => '员工2', 'regular_date' => '2026-04-01',
            'new_position' => '保安队长', 'fixed_monthly' => 8000, 'base_salary' => 6500,
        ], '测试项目', $this->account());

        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertTrue($result['regular'] ?? false);
        $row = DB::table('payroll_staff')->where('legacy_id', 2)->first();
        $this->assertSame('保安队长', $row->position);
        $this->assertSame(8000.0, (float) $row->fixed_monthly);
        $this->assertSame('2026-04-01', substr((string) $row->regular_date, 0, 10));
    }

    public function test_bound_staff_resign_approval_skips_profile_but_disables_account(): void
    {
        $this->seedStaff(3, 'dt-3');
        $this->seedAccount(3);

        $result = ApprovalActions::handle('resign_approval', [
            'staff_id' => 3, 'name' => '员工3', 'resign_date' => '2026-09-30',
        ], '测试项目', $this->account());

        $this->assertSame('dingtalk_authoritative', $result['skipped'] ?? null);
        $row = DB::table('payroll_staff')->where('legacy_id', 3)->first();
        // 档案离职字段不写（等钉钉同步）
        $this->assertNull($row->resign_date);
        $this->assertSame('试用', $row->status);
        // 系统账号仍被停用（访问安全不依赖钉钉）
        $this->assertEquals(0, (int) DB::table('payroll_accounts')->where('staff_id', 3)->value('enabled'));
    }

    public function test_unbound_staff_resign_approval_updates_profile_and_disables_account(): void
    {
        $this->seedStaff(4, null);
        $this->seedAccount(4);

        $result = ApprovalActions::handle('resign_approval', [
            'staff_id' => 4, 'name' => '员工4', 'resign_date' => '2026-09-30',
        ], '测试项目', $this->account());

        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertTrue($result['resign'] ?? false);
        $row = DB::table('payroll_staff')->where('legacy_id', 4)->first();
        $this->assertSame('2026-09-30', substr((string) $row->resign_date, 0, 10));
        $this->assertSame('离职', $row->status);
        $this->assertEquals(0, (int) DB::table('payroll_accounts')->where('staff_id', 4)->value('enabled'));
    }
}
