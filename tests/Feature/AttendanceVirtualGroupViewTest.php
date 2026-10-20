<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 考勤「查看已上传数据」虚拟汇总选项（管理人员/案场人员）：
 * GET /api/attendance?project=__managers__|__case__ 聚合各项目考勤块中该类别的行。
 *
 * 口径断言：按月 categoryMap 分类（含 person_type_since 生效日回退）、
 * 管理人员不含物业总部、跨项目同名加项目后缀、项目账号不触发聚合。
 */
class AttendanceVirtualGroupViewTest extends TestCase
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
            ['name' => '物业公司总部', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'category' => '', 'value' => 1, 'in_actual' => 1, 'in_required' => 1],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
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

    private function row(int $id, string $name): array
    {
        return ['days' => ['√', '√'], 'req_attend' => 2, 'staff_id' => $id, 'position' => '岗位' . $id];
    }

    private function seedWorld(): void
    {
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $this->staff(2, '经理乙', 'manager', '乙项目');
        $this->staff(3, '案场甲', 'case', '甲项目');
        $this->staff(4, '员工甲', 'staff', '甲项目');
        // 物业总部的管理人员标记：走总部自己的入口，不进管理人员汇总
        $this->staff(5, '总部经理', 'manager', '物业公司总部');
        // 跨项目同名管理人员：显示名需加项目后缀防键冲突
        $this->staff(6, '同名', 'manager', '甲项目');
        $this->staff(7, '同名', 'manager', '乙项目');
        // person_type_since 晚于核算月月末：该月按 staff 核算，不进管理人员汇总
        $this->staff(8, '新提拔', 'manager', '甲项目', ['person_type_since' => '2026-05-01']);

        $this->block('甲项目', [
            '经理甲' => $this->row(1, '经理甲'),
            '案场甲' => $this->row(3, '案场甲'),
            '员工甲' => $this->row(4, '员工甲'),
            '同名' => $this->row(6, '同名'),
            '新提拔' => $this->row(8, '新提拔'),
        ]);
        $this->block('乙项目', [
            '经理乙' => $this->row(2, '经理乙'),
            '同名' => $this->row(7, '同名'),
        ]);
        $this->block('物业公司总部', [
            '总部经理' => $this->row(5, '总部经理'),
        ]);
    }

    public function test_managers_group_aggregates_only_month_category_rows(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertTrue($data['ok']);
        $keys = array_keys($data['rows']);
        $this->assertEqualsCanonicalizing(['同名（甲项目）', '同名（乙项目）', '经理甲', '经理乙'], $keys);
        // 统计同步聚合
        $this->assertEquals(2, $data['stats']['经理甲']['attend']);
        // 虚拟组无单一锁定状态
        $this->assertFalse($data['locked']);
    }

    public function test_case_group_aggregates_case_rows(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance?ym=' . self::YM . '&project=__case__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertSame(['案场甲'], array_keys($data['rows']));
    }

    public function test_empty_group_returns_no_rows(): void
    {
        // 不 seed 任何考勤块：聚合结果为空
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $resp = $this->get('/api/attendance?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertSame([], $data['rows']);
    }

    public function test_project_account_cannot_trigger_virtual_aggregation(): void
    {
        $this->seedWorld();
        // 项目账号即使传哨兵值也被强制覆盖为自己的项目（单项目块，不做类别过滤）
        $resp = $this->get('/api/attendance?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->projToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertSame('甲项目', $data['project']);
        $this->assertEqualsCanonicalizing(['员工甲', '同名', '案场甲', '经理甲', '新提拔'], array_keys($data['rows']));
    }

    public function test_status_reports_virtual_group_has_data(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/status?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $data = $resp->json();
        // 管理人员（经理甲/经理乙/同名行在各项目块中）与案场（案场甲）均有数据
        $this->assertTrue($data['virtual']['__managers__']);
        $this->assertTrue($data['virtual']['__case__']);
    }

    public function test_status_virtual_false_when_month_has_no_blocks(): void
    {
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $this->staff(2, '案场甲', 'case', '甲项目');
        // 该月无任何考勤块 → 两个虚拟组均无数据（前端按钮置灰依据）
        $resp = $this->get('/api/attendance/status?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $data = $resp->json();
        $this->assertFalse($data['virtual']['__managers__']);
        $this->assertFalse($data['virtual']['__case__']);
    }

    public function test_status_virtual_managers_excludes_hq_rows(): void
    {
        // 仅物业总部块有经理行：按口径不进管理人员汇总 → __managers__ 为 false
        $this->staff(5, '总部经理', 'manager', '物业公司总部');
        $this->block('物业公司总部', ['总部经理' => $this->row(5, '总部经理')]);
        $resp = $this->get('/api/attendance/status?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $this->assertFalse($resp->json('virtual.__managers__'));
    }
}
