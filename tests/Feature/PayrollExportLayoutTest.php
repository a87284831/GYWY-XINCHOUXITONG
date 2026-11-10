<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 工资表导出（GET /api/export/project）：
 * 1. 列序含"入职日期"，且在"人员状态"后
 * 2. 入职日期数据行值正确（来自 row_data['hire_date']）
 * 3. 人员状态口径用档案 status（非 rowStatus 动态推算）
 * 4. 部门相同行合并单元格（C 列）
 */
class PayrollExportLayoutTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private const YM = '2026-09';
    private const PROJ = '测试项目';

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
            'name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 种一条核算结果行（row_data JSON），模拟 PayrollCalculator::computeRow 输出。
     * 关键字段：project/department/position/name/status/hire_date + 货币列。
     */
    private function seedResult(int $staffId, string $name, string $department, string $position, string $status, string $hireDate, string $project = self::PROJ): void
    {
        $row = [
            'staff_id' => $staffId, 'project' => $project, 'department' => $department,
            'position' => $position, 'name' => $name, 'status' => $status, 'hire_date' => $hireDate,
            'fixed' => 5000, 'base' => 4000, 'req_att' => 22, 'act_att' => 22, 'coef' => 1,
            'base_pay' => 4000, 'perf_pay' => 1000, 'sick_pay' => 0,
            'night' => 0, 'meal' => 0, 'title_sub' => 0, 'reward' => 0, 'welfare' => 0,
            'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
            'gross' => 5000, 'pen' => 500, 'med' => 200, 'une' => 100, 'house' => 400, 'big' => 50,
            'soc_total' => 1250, 'spec_total' => 0, 'actual_tax' => 0, 'tax_diff' => 0, 'net' => 3750,
            'remark' => '', 'perf_detail' => null,
        ];
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $project,
            'staff_legacy_id' => $staffId, 'archived' => true,
            'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_hire_date_column_after_status_and_value_correct(): void
    {
        $this->seedResult(10, '张三', '客服部', '专员', '正式', '2026-03-15');

        $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);
        $headers = $grid[1]; // 第 2 行表头

        // COLUMNS 含项目列：序号|项目|部门|岗位|姓名|人员状态|入职日期|固定月薪...
        $this->assertSame('序号', $headers[0]);
        $this->assertSame('项目', $headers[1]);
        $this->assertSame('部门', $headers[2]);
        $this->assertSame('岗位', $headers[3]);
        $this->assertSame('姓名', $headers[4]);
        $this->assertSame('人员状态', $headers[5]);
        $this->assertSame('入职日期', $headers[6], '入职日期必须在人员状态之后');
        $this->assertSame('固定月薪', $headers[7]);

        // 数据行：张三的入职日期
        $dataRow = $grid[2];
        $this->assertSame('张三', $dataRow[4]);
        $this->assertSame('正式', $dataRow[5]);
        $this->assertSame('2026-03-15', $dataRow[6], '入职日期值应来自 row_data.hire_date');

        @unlink($tmp);
    }

    public function test_status_uses_archive_status_not_dynamic_rowStatus(): void
    {
        // 档案 status='试用'，但本月已过预计转正日 —— 若用 rowStatus 会推算为"正式"
        // 用户要求口径一致：直接读档案 status，应显示"试用"
        $this->seedResult(20, '李四', '客服部', '专员', '试用', '2026-01-01');

        $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $dataRow = $sheet->toArray(null, true, false, false)[2];

        $this->assertSame('试用', $dataRow[5], '人员状态应直接读档案 status，不动态推算');

        @unlink($tmp);
    }

    public function test_department_merged_cells_for_same_dept(): void
    {
        // 同部门 2 人 + 另一部门 1 人，验证 C 列（部门）合并
        $this->seedResult(30, '张三', '客服部', '专员', '正式', '2026-01-01');
        $this->seedResult(31, '李四', '客服部', '主管', '正式', '2026-02-01');
        $this->seedResult(32, '王五', '工程部', '工程师', '正式', '2026-03-01');

        $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);

        // 数据行 3,4 = 客服部（应合并 C3:C4）；数据行 5 = 工程部（不合并）
        $mergedRanges = $sheet->getMergeCells();
        $hasC3C4 = false;
        foreach ($mergedRanges as $range => $v) {
            if ($range === 'C3:C4') $hasC3C4 = true;
        }
        $this->assertTrue($hasC3C4, '客服部 2 人应合并 C3:C4');

        // 合并后首格值=客服部，居中
        $this->assertSame('客服部', $sheet->getCell('C3')->getValue());
        $align = $sheet->getStyle('C3')->getAlignment();
        $this->assertSame('center', $align->getHorizontal());
        $this->assertSame('center', $align->getVertical());

        @unlink($tmp);
    }

    /**
     * 回归：页面项目筛选默认「全部项目」（project 传空），总部必须能导出；
     * 且锁定（archived=true）与未锁定（archived=false）两种情况都能导出。
     */
    public function test_export_all_projects_default_works_locked_and_unlocked(): void
    {
        $this->seedResult(50, '张三', '客服部', '专员', '正式', '2026-01-01', '甲项目');
        $this->seedResult(51, '李四', '工程部', '工程师', '正式', '2026-02-01', '乙项目');

        foreach ([true, false] as $archived) {
            DB::table('payroll_results')->update(['archived' => $archived]);

            $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=', ['X-Token' => $this->token]);
            $resp->assertOk();
            $content = $resp->streamedContent();
            $this->assertNotEmpty($content, 'archived=' . var_export($archived, true) . ' 时应能导出');

            $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
            file_put_contents($tmp, $content);
            $grid = IOFactory::load($tmp)->getSheet(0)->toArray(null, true, false, false);
            @unlink($tmp);

            $names = array_map(fn ($r) => trim((string) ($r[4] ?? '')), $grid);
            $this->assertContains('张三', $names, 'project 留空应导出全部项目明细');
            $this->assertContains('李四', $names, 'project 留空应导出全部项目明细');
        }
    }

    /** 回归：项目账号的核定（archived）前置校验不能被削弱 */
    public function test_project_account_export_still_gated_by_archived(): void
    {
        DB::table('payroll_roles')->insert([
            'role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
            'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj', 'name' => '项目会计',
            'role' => 'project', 'project_name' => self::PROJ, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ptok = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $ptok, $pid, now()->addHours(8));

        $this->seedResult(60, '张三', '客服部', '专员', '正式', '2026-01-01');
        DB::table('payroll_results')->update(['archived' => false]);

        // 未核定 → 仍拒绝
        $this->getJson('/api/export/project?ym=' . self::YM . '&project=', ['X-Token' => $ptok])
            ->assertStatus(403);

        DB::table('payroll_results')->update(['archived' => true]);
        $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=', ['X-Token' => $ptok]);
        $resp->assertOk();
        $this->assertNotEmpty($resp->streamedContent());
    }
}
