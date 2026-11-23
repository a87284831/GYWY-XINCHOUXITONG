<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 查看已上传考勤表：按人员档案部门排序，同部门保持上传行序，无部门排最后。
 */
class AttendanceViewDeptSortTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9801, 'username' => 'att_admin', 'name' => '考勤管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('c', 64);
        Cache::put('payroll_api_token:' . $this->token, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            'name' => '测试项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->staff(1, '张三', '测试项目/环境部', '保洁');
        $this->staff(2, '李四', '测试项目/工程部', '工程维修');
        $this->staff(3, '王五', '测试项目/环境部', '保洁');
        $this->staff(4, '赵六', '', '临时工');
        $this->staff(5, '钱七', '测试项目/秩序部', '秩序员');

        // 上传行序：张三、李四、王五、赵六、钱七（故意与部门顺序交错）
        $rows = [
            '张三' => ['staff_id' => 1, 'position' => '保洁', 'status' => '正式', 'days' => []],
            '李四' => ['staff_id' => 2, 'position' => '工程维修', 'status' => '正式', 'days' => []],
            '王五' => ['staff_id' => 3, 'position' => '保洁', 'status' => '正式', 'days' => []],
            '赵六' => ['staff_id' => 4, 'position' => '临时工', 'status' => '正式', 'days' => []],
            // 旧数据没有 staff_id：按姓名兜底映射部门
            '钱七' => ['position' => '秩序员', 'status' => '正式', 'days' => []],
        ];
        DB::table('payroll_attendance')->insert([
            'record_key' => '2026-10|测试项目', 'year_month' => '2026-10', 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'data' => json_encode(['ym' => '2026-10', 'project' => '测试项目', 'order' => array_keys($rows)], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(int $id, string $name, string $deptPath, string $position): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目',
            'position' => $position, 'status' => '正式', 'person_type' => 'staff',
            'fixed_monthly' => 3000, 'base_salary' => 2500, 'hire_date' => '2025-01-01',
            'regular_date' => '2025-04-01', 'resign_date' => null, 'deleted' => false,
            'data' => json_encode([], JSON_UNESCAPED_UNICODE), 'dept_path' => $deptPath,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_attendance_view_sorted_by_department_with_upload_order_inside_department(): void
    {
        $r = $this->get('/api/attendance?ym=2026-10&project=测试项目', ['X-Token' => $this->token]);
        $r->assertOk();
        $order = $r->json('order');
        // 工程部 < 环境部 < 秩序部（字节序）；环境部内保持上传顺序 张三→王五；无部门最后
        $this->assertSame(['李四', '张三', '王五', '钱七', '赵六'], $order);
        $this->assertSame('工程部', $r->json('depts.李四'));
        $this->assertSame('环境部', $r->json('depts.张三'));
        $this->assertSame('环境部', $r->json('depts.王五'));
        $this->assertSame('秩序部', $r->json('depts.钱七'), '无 staff_id 时按姓名兜底');
        $this->assertSame('', $r->json('depts.赵六'));
    }
}
