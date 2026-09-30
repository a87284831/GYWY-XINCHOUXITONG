<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 人员档案保存的字段权限护栏（2026-10-01）：
 * 钉钉为唯一权威源——有 dingtalk_userid 绑定的行，同步字段
 * （姓名/职位/入职/转正/离职日期/固定月薪/基本工资/岗位职级/个人信息）
 * 不得通过 /api/staff/save 本地覆盖（防 API 直调）；
 * 无绑定的历史导入行/本地行仍允许本地维护。
 * 工资标准变更的合法本地路径是「调薪与记录」（留痕）。
 */
class StaffSaveSyncFieldGuardTest extends TestCase
{
    use RefreshDatabase;

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

        DB::table('payroll_projects')->insert([
            'name' => '测试项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedStaff(int $id, string $name, ?string $dtUid): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'dingtalk_userid' => $dtUid, 'name' => $name,
            'project_name' => '测试项目', 'position' => '保安', 'status' => '正式',
            'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2025-01-01', 'regular_date' => '2025-04-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode(['phone' => '13900000000'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postSave(array $staff)
    {
        return $this->postJson('/api/staff/save', ['staff' => $staff], ['X-Token' => $this->token]);
    }

    public function test_bound_row_cannot_override_synced_fields(): void
    {
        $this->seedStaff(1, '张三', 'dt-1');

        $resp = $this->postSave([
            'id' => 1, 'project' => '测试项目',
            'name' => '张三改', 'position' => '保安队长', 'person_type' => 'manager',
            'fixed_monthly' => 99999, 'base_salary' => 88888,
            'hire_date' => '2000-01-01', 'regular_date' => '2000-01-01', 'resign_date' => '2000-01-01',
            'phone' => '13811112222', 'gender' => '女',
        ]);
        $resp->assertOk()->assertJsonPath('ok', true);

        $row = DB::table('payroll_staff')->where('legacy_id', 1)->first();
        // 同步字段全部保持原值（钉钉为唯一权威源）
        $this->assertSame('张三', $row->name);
        $this->assertSame('保安', $row->position);
        $this->assertSame('staff', $row->person_type);
        $this->assertSame(6000.0, (float) $row->fixed_monthly);
        $this->assertSame(5000.0, (float) $row->base_salary);
        $this->assertSame('2025-01-01', substr((string) $row->hire_date, 0, 10));
        $this->assertSame('2025-04-01', substr((string) $row->regular_date, 0, 10));
        $this->assertNull($row->resign_date);
        // 个人信息（data JSON）同样不被覆盖
        $data = json_decode((string) $row->data, true);
        $this->assertSame('13900000000', $data['phone'] ?? null);
        $this->assertArrayNotHasKey('gender', $data);
    }

    public function test_unbound_row_remains_locally_editable(): void
    {
        $this->seedStaff(2, '李四', null);

        $resp = $this->postSave([
            'id' => 2, 'project' => '测试项目',
            'name' => '李四改', 'position' => '客服主管', 'person_type' => 'case',
            'fixed_monthly' => 7000, 'base_salary' => 5500,
        ]);
        $resp->assertOk()->assertJsonPath('ok', true);

        $row = DB::table('payroll_staff')->where('legacy_id', 2)->first();
        $this->assertSame('李四改', $row->name);
        $this->assertSame('客服主管', $row->position);
        $this->assertSame('case', $row->person_type);
        $this->assertSame(7000.0, (float) $row->fixed_monthly);
        $this->assertSame(5500.0, (float) $row->base_salary);
    }
}
