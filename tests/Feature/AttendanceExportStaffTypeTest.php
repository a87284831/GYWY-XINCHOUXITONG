<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 考勤导出按人员类型筛选：口径为 payroll_staff.person_type
 * （回归：旧实现读 data.position_level，该 key 从未被写入，类型筛选必然 404）。
 */
class AttendanceExportStaffTypeTest extends TestCase
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
    }

    private function seedWorld(): void
    {
        DB::table('payroll_staff')->insert([
            ['legacy_id' => 1, 'name' => '张三', 'project_name' => '测试项目', 'position' => '经理',
             'status' => '正式', 'person_type' => 'manager', 'deleted' => false,
             'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()],
            ['legacy_id' => 2, 'name' => '李四', 'project_name' => '测试项目', 'position' => '客服',
             'status' => '正式', 'person_type' => 'staff', 'deleted' => false,
             'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_attendance')->insert([
            'record_key' => '2026-04|测试项目',
            'year_month' => '2026-04', 'project_name' => '测试项目',
            'rows' => json_encode([
                '张三' => ['days' => ['√', '√'], 'req_attend' => 22, 'act_attend' => 22, 'coef' => 1],
                '李四' => ['days' => ['√', '休'], 'req_attend' => 22, 'act_attend' => 21, 'coef' => 1],
            ], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'locked_at' => now(), 'locked_by' => 'hq_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_filter_managers_returns_file(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/export?ym=2026-04&project=' . urlencode('测试项目') . '&staff_type=' . urlencode('管理人员'), ['X-Token' => $this->token]);
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_filter_type_with_no_matching_staff_returns_404(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/export?ym=2026-04&project=' . urlencode('测试项目') . '&staff_type=' . urlencode('案场人员'), ['X-Token' => $this->token]);
        $resp->assertNotFound()->assertJson(['ok' => false]);
    }

    public function test_invalid_staff_type_returns_400(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/export?ym=2026-04&project=' . urlencode('测试项目') . '&staff_type=' . urlencode('外星人'), ['X-Token' => $this->token]);
        $resp->assertStatus(400)->assertJson(['ok' => false]);
    }

    /** 总部人员自 2026-10-18 起不在导出选项内（物业总部走"项目=物业总部"整体导出），传该类型应报参数错误 */
    public function test_hq_staff_type_is_rejected(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/export?ym=2026-04&project=' . urlencode('测试项目') . '&staff_type=' . urlencode('总部人员'), ['X-Token' => $this->token]);
        $resp->assertStatus(400)->assertJson(['ok' => false]);
    }

    /** 物业总部考勤走项目整体导出：不带人员类型即可导出含 person_type=hq 的人员（选项移除后 HQ 导出能力不受影响） */
    public function test_hq_project_export_includes_hq_staff(): void
    {
        DB::table('payroll_staff')->insert([
            ['legacy_id' => 3, 'name' => '王五', 'project_name' => '物业公司总部', 'position' => '总部专员',
             'status' => '正式', 'person_type' => 'hq', 'deleted' => false,
             'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_attendance')->insert([
            'record_key' => '2026-04|物业公司总部',
            'year_month' => '2026-04', 'project_name' => '物业公司总部',
            'rows' => json_encode(['王五' => ['days' => ['√', '√'], 'req_attend' => 22, 'act_attend' => 22]], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'locked_at' => now(), 'locked_by' => 'hq_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resp = $this->get('/api/attendance/export?ym=2026-04&project=' . urlencode('物业公司总部'), ['X-Token' => $this->token]);
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
