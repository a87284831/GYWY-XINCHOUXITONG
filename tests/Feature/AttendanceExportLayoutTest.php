<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 考勤表导出（GET /api/attendance/export）：
 * 1. 永久离职人员不出现（status=离职 且 resign_date 为 NULL 或早于当月 1 日）
 * 2. 当月离职人员出现（resign_date >= 当月 1 日）——要算他离职当月工资
 * 3. 列序：序号|项目|部门|岗位|姓名|员工状态|入职日期（单项目省项目列）
 * 4. 排序：项目→部门→岗位→姓名
 * 5. 部门合并单元格
 */
class AttendanceExportLayoutTest extends TestCase
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

    private function seedStaff(int $legacyId, string $name, string $status, ?string $resignDate, ?string $hireDate = '2026-01-01', string $deptPath = '测试项目/客服部'): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $legacyId, 'name' => $name, 'project_name' => self::PROJ,
            'position' => '专员', 'status' => $status, 'resign_date' => $resignDate,
            'hire_date' => $hireDate, 'dept_path' => $deptPath,
            'fixed_monthly' => 5000, 'base_salary' => 4000,
            'deleted' => false, 'person_type' => 'staff',
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAttendance(array $staffNames): void
    {
        $days = array_fill(0, 30, '√');
        $rows = [];
        foreach ($staffNames as $name) {
            $rows[$name] = ['days' => $days, 'req_attend' => 22, 'act_attend' => 22];
        }
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => self::YM . '|' . self::PROJ],
            ['year_month' => self::YM, 'project_name' => self::PROJ, 'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_permanent_resigned_excluded_current_month_resigned_included(): void
    {
        // 永久离职（resign_date=NULL）——不该出现
        $this->seedStaff(1, '永久离职者', '离职', null);
        // 上月离职（resign_date=2026-08-20）——不该出现
        $this->seedStaff(2, '上月离职者', '离职', '2026-08-20');
        // 当月离职（resign_date=2026-09-15）——要出现
        $this->seedStaff(3, '当月离职者', '离职', '2026-09-15');
        // 在职正式——要出现
        $this->seedStaff(4, '在职正式', '正式', null);
        // 在职试用——要出现
        $this->seedStaff(5, '在职试用', '试用', null);

        // 永久离职者也种考勤——验证真正的离职过滤（不是靠"没考勤就跳过"的巧合）
        $this->seedAttendance(['永久离职者', '上月离职者', '当月离职者', '在职正式', '在职试用']);

        $resp = $this->getJson('/api/attendance/export?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);

        // 收集所有姓名（B 列起，找姓名列）
        $names = [];
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                $names[(string)$cell] = true;
            }
        }
        $this->assertArrayNotHasKey('永久离职者', $names, '永久离职者（resign_date=NULL）不应出现在考勤表');
        $this->assertArrayNotHasKey('上月离职者', $names, '上月离职者不应出现在当月考勤表');
        $this->assertArrayHasKey('当月离职者', $names, '当月离职者应出现在考勤表（要算离职当月工资）');
        $this->assertArrayHasKey('在职正式', $names);
        $this->assertArrayHasKey('在职试用', $names);

        @unlink($tmp);
    }

    public function test_column_order_includes_project_dept_hire_date_after_status(): void
    {
        $this->seedStaff(10, '张三', '正式', null, '2026-03-15');
        $this->seedAttendance(['张三']);

        $resp = $this->getJson('/api/attendance/export?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $headers = $sheet->toArray(null, true, false, false)[1]; // 第 2 行是表头

        // 单项目省"项目"列：序号|部门|岗位|姓名|员工状态|入职日期|日期列...
        $this->assertSame('序号', $headers[0]);
        $this->assertSame('部门', $headers[1]);
        $this->assertSame('岗位', $headers[2]);
        $this->assertSame('姓名', $headers[3]);
        $this->assertSame('员工状态', $headers[4]);
        $this->assertSame('入职日期', $headers[5]);
        // 入职日期后面应该是日期列（1号，PhpSpreadsheet 可能存为 int）
        $this->assertEquals(1, $headers[6]);

        // 验证数据行：张三的入职日期
        $dataRow = $sheet->toArray(null, true, false, false)[2];
        $this->assertSame('张三', $dataRow[3]);
        $this->assertSame('正式', $dataRow[4]);
        $this->assertSame('2026-03-15', $dataRow[5]);

        @unlink($tmp);
    }

    public function test_staff_ordered_by_project_dept_position_name(): void
    {
        // 同项目不同部门、同部门不同岗位——验证排序
        $this->seedStaff(20, '张三', '正式', null, '2026-01-01', '测试项目/客服部');
        $this->seedStaff(21, '李四', '正式', null, '2026-01-01', '测试项目/客服部');
        $this->seedStaff(22, '王五', '正式', null, '2026-01-01', '测试项目/工程部');
        $this->seedAttendance(['张三', '李四', '王五']);

        $resp = $this->getJson('/api/attendance/export?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);

        // 收集数据行的姓名（D 列，index 3，第 3 行起）
        $names = [];
        for ($i = 2; $i < count($grid); $i++) {
            $n = trim((string)($grid[$i][3] ?? ''));
            if ($n !== '') $names[] = $n;
        }
        // 客服部（张三/李四）在工程部（王五）前面
        $zhaoIdx = array_search('张三', $names);
        $liIdx = array_search('李四', $names);
        $wangIdx = array_search('王五', $names);
        $this->assertNotFalse($zhaoIdx);
        $this->assertNotFalse($liIdx);
        $this->assertNotFalse($wangIdx);
        $this->assertLessThan($wangIdx, $zhaoIdx, '客服部应在工程部前面');
        $this->assertLessThan($wangIdx, $liIdx, '客服部应在工程部前面');

        @unlink($tmp);
    }
}
