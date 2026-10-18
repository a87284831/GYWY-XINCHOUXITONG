<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 考勤数据删除（POST /api/attendance/delete）三种视角：
 * - 项目账号：只能删自己项目的考勤块（传其他项目/虚拟哨兵被强制覆盖）
 * - 管理员删具体项目：整块清空
 * - 管理员虚拟组（管理人员/案场人员）：从各项目考勤块剔除该类人员行，
 *   其他人员行保留，删空的项目块整块删除；任一涉及项目锁定/归档时整体拒绝
 * 删除成功写 payroll_delete_logs 审计日志。
 */
class AttendanceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $projToken;
    private const YM = '2026-04';

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'jia_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('a', 64);
        $this->projToken = str_repeat('b', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
            ['name' => '乙项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
            ['name' => '物业总部', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function staff(int $id, string $name, string $type, string $project, array $extra = []): void
    {
        DB::table('payroll_staff')->insert([[
            'legacy_id' => $id, 'name' => $name, 'project_name' => $project,
            'position' => '岗位' . $id, 'status' => '正式', 'person_type' => $type,
            'deleted' => false, 'hire_date' => '2025-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra]);
    }

    private function block(string $project, array $rows): void
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . $project,
            'year_month' => self::YM, 'project_name' => $project,
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'locked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rowsOf(string $project): array
    {
        $rec = DB::table('payroll_attendance')->where('record_key', self::YM . '|' . $project)->first();
        return $rec ? (json_decode((string) $rec->rows, true) ?: []) : [];
    }

    private function row(int $id): array
    {
        return ['days' => ['√', '√'], 'req_attend' => 2, 'staff_id' => $id];
    }

    private function archive(string $project): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'staff_legacy_id' => 999, 'project_name' => $project,
            'row_data' => '{}', 'archived' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lock(string $project): void
    {
        DB::table('payroll_attendance')->where('record_key', self::YM . '|' . $project)->update(['locked' => true]);
    }

    private function seedManagerWorld(): void
    {
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $this->staff(2, '经理乙', 'manager', '乙项目');
        $this->staff(3, '案场甲', 'case', '甲项目');
        $this->staff(4, '员工甲', 'staff', '甲项目');
        $this->block('甲项目', [
            '经理甲' => $this->row(1),
            '案场甲' => $this->row(3),
            '员工甲' => $this->row(4),
        ]);
        $this->block('乙项目', ['经理乙' => $this->row(2)]);
    }

    public function test_project_account_deletes_own_project_block(): void
    {
        $this->seedManagerWorld();
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '甲项目'], ['X-Token' => $this->projToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['stats']['blocks']);
        $this->assertSame(3, $data['stats']['rows']);
        $this->assertNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '甲项目')->first());
        // 其他项目不受影响
        $this->assertNotNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '乙项目')->first());
    }

    public function test_project_account_cannot_delete_other_project(): void
    {
        $this->seedManagerWorld();
        // 传乙项目被强制覆盖为自己的项目：删的是甲项目块，乙项目原样保留
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '乙项目'], ['X-Token' => $this->projToken]);
        $resp->assertOk();
        $this->assertNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '甲项目')->first());
        $this->assertSame(['经理乙'], array_keys($this->rowsOf('乙项目')));
    }

    public function test_project_account_cannot_trigger_virtual_delete(): void
    {
        $this->seedManagerWorld();
        // 项目账号传虚拟哨兵被强制覆盖为自己的项目：只删自己项目的块
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__managers__'], ['X-Token' => $this->projToken]);
        $resp->assertOk();
        $this->assertNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '甲项目')->first());
        $this->assertNotNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '乙项目')->first());
    }

    public function test_admin_deletes_specific_project_with_log(): void
    {
        $this->seedManagerWorld();
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '甲项目'], ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $this->assertSame(3, $resp->json('stats.rows'));
        $log = DB::table('payroll_delete_logs')->where('ym', self::YM)->first();
        $this->assertNotNull($log);
        $scope = json_decode((string) $log->scope_info, true);
        $this->assertSame('attendance', $scope['scope_type']);
        $this->assertSame('考勤（甲项目）', $scope['scope_label']);
        $this->assertSame(3, $scope['rows']);
        $this->assertSame('总部管理员', $log->operator);
    }

    public function test_virtual_group_delete_keeps_other_rows_and_deletes_empty_block(): void
    {
        $this->seedManagerWorld();
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__managers__'], ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        // 甲项目块：仅剔除管理人员行（经理甲），案场/员工行保留
        $this->assertEqualsCanonicalizing(['案场甲', '员工甲'], array_keys($this->rowsOf('甲项目')));
        // 乙项目块被删空 → 整块删除
        $this->assertNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '乙项目')->first());
        $this->assertSame(2, $resp->json('stats.rows'));
        $this->assertSame(2, $resp->json('stats.blocks'));
        // 审计日志含删除名单（超10人截断为"前10人+等N人"）
        $scope = json_decode((string) DB::table('payroll_delete_logs')->where('ym', self::YM)->value('scope_info'), true);
        $this->assertSame('考勤（全公司管理人员）', $scope['scope_label']);
        $this->assertSame('经理甲、经理乙', $scope['deleted_names'] ?? '');
    }

    public function test_virtual_group_delete_rejected_when_any_block_locked(): void
    {
        $this->seedManagerWorld();
        $this->lock('乙项目');
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__managers__'], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(400);
        $this->assertStringContainsString('先解锁', $resp->json('error'));
        $this->assertStringContainsString('乙项目', $resp->json('error'));
        // 整体拒绝：甲项目块一个字都不动
        $this->assertCount(3, $this->rowsOf('甲项目'));
        $this->assertNull(DB::table('payroll_delete_logs')->where('ym', self::YM)->first());
    }

    public function test_virtual_group_delete_rejected_when_any_project_archived(): void
    {
        $this->seedManagerWorld();
        $this->archive('甲项目');
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__managers__'], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(400);
        $this->assertStringContainsString('归档', $resp->json('error'));
        $this->assertCount(3, $this->rowsOf('甲项目'));
        $this->assertNotNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . '乙项目')->first());
    }

    public function test_case_group_delete_keeps_manager_rows(): void
    {
        $this->seedManagerWorld();
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__case__'], ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        // 仅案场行被剔除，管理人员行保留
        $this->assertEqualsCanonicalizing(['经理甲', '员工甲'], array_keys($this->rowsOf('甲项目')));
        $this->assertSame(1, $resp->json('stats.rows'));
        $this->assertSame(1, $resp->json('stats.blocks'));
    }

    public function test_delete_archived_month_rejected(): void
    {
        $this->seedManagerWorld();
        $this->archive('甲项目');
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '甲项目'], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(400);
        $this->assertCount(3, $this->rowsOf('甲项目'));
    }

    public function test_delete_locked_project_rejected(): void
    {
        $this->seedManagerWorld();
        $this->lock('甲项目');
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '甲项目'], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(400);
        $this->assertCount(3, $this->rowsOf('甲项目'));
    }

    public function test_virtual_group_delete_no_data_returns_400(): void
    {
        // 人员档案有管理人员但该月无任何考勤块
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $resp = $this->post('/api/attendance/delete', ['ym' => self::YM, 'project' => '__managers__'], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(400);
        $this->assertStringContainsString('暂无', $resp->json('error'));
    }
}
