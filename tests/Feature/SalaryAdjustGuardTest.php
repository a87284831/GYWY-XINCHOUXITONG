<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 调薪/转正定薪护栏（2026-10-01 用户决策：系统内不允许调薪，薪资以钉钉花名册为准）：
 * - 有 dingtalk_userid 绑定的人员，POST /api/salary_adjust 必须被拒绝（403），
 *   fixed_monthly/base_salary/salary_history/payroll_salary_adjustments 均不得变化；
 * - 无绑定的历史导入行/本地行仍允许本地调薪留痕；
 * - GET /api/staff 输出布尔 dingtalk_bound（不暴露 userid 本体），供前端禁用入口。
 */
class SalaryAdjustGuardTest extends TestCase
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

    private function postAdjust(int $staffId)
    {
        return $this->postJson('/api/salary_adjust', [
            'staff_id' => $staffId, 'type' => '调薪',
            'effective_date' => '2026-10-01',
            'fixed_monthly' => 8000, 'base_salary' => 6500, 'note' => '测试调薪',
        ], ['X-Token' => $this->token]);
    }

    public function test_bound_staff_salary_adjust_is_rejected(): void
    {
        $this->seedStaff(1, '张三', 'dt-1');

        $resp = $this->postAdjust(1);
        $resp->assertStatus(403);
        $this->assertStringContainsString('钉钉', (string) $resp->json('error'));

        // 薪资主字段不变
        $row = DB::table('payroll_staff')->where('legacy_id', 1)->first();
        $this->assertSame(6000.0, (float) $row->fixed_monthly);
        $this->assertSame(5000.0, (float) $row->base_salary);
        // 不留调薪流水
        $this->assertSame(0, DB::table('payroll_salary_adjustments')->count());
        // 不追加 salary_history
        $data = json_decode((string) $row->data, true);
        $this->assertArrayNotHasKey('salary_history', $data);
    }

    public function test_bound_staff_regular_salary_adjust_is_rejected(): void
    {
        // 转正定薪同属薪资变更，绑定人员同样禁止
        $this->seedStaff(2, '王五', 'dt-2');

        $resp = $this->postJson('/api/salary_adjust', [
            'staff_id' => 2, 'type' => '转正',
            'effective_date' => '2026-10-01',
            'fixed_monthly' => 7000, 'base_salary' => 5500, 'note' => '转正',
        ], ['X-Token' => $this->token]);
        $resp->assertStatus(403);

        $row = DB::table('payroll_staff')->where('legacy_id', 2)->first();
        $this->assertSame(6000.0, (float) $row->fixed_monthly);
        $this->assertSame('2025-04-01', substr((string) $row->regular_date, 0, 10));
        $this->assertSame(0, DB::table('payroll_salary_adjustments')->count());
    }

    public function test_unbound_staff_salary_adjust_remains_allowed(): void
    {
        $this->seedStaff(3, '李四', null);

        $resp = $this->postAdjust(3);
        $resp->assertOk()->assertJsonPath('ok', true);

        $row = DB::table('payroll_staff')->where('legacy_id', 3)->first();
        $this->assertSame(8000.0, (float) $row->fixed_monthly);
        $this->assertSame(6500.0, (float) $row->base_salary);
        $data = json_decode((string) $row->data, true);
        $this->assertNotEmpty($data['salary_history'] ?? []);
        $this->assertSame(1, DB::table('payroll_salary_adjustments')->count());
    }

    public function test_staff_list_exposes_dingtalk_bound_flag(): void
    {
        $this->seedStaff(4, '赵六', 'dt-4');
        $this->seedStaff(5, '钱七', null);

        $resp = $this->getJson('/api/staff?kw=' . urlencode(''), ['X-Token' => $this->token]);
        $resp->assertOk();
        $byId = collect($resp->json('staff'))->keyBy('id');
        $this->assertTrue($byId[4]['dingtalk_bound']);
        $this->assertFalse($byId[5]['dingtalk_bound']);
        // 不泄露 userid 本体
        $this->assertArrayNotHasKey('dingtalk_userid', $byId[4]);
    }
}
