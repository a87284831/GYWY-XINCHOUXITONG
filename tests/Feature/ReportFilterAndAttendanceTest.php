<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 报表中心筛选（M5）、考勤存值优先（M6）、人员导出过滤（M8）回归。
 */
class ReportFilterAndAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();

        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
            '事' => ['事假', true, false, 0.0, '事假'],
        ];
        $items = [];
        foreach ($def as $symbol => [$name, $req, $act, $value, $cat]) {
            $items[] = ['symbol' => $symbol, 'name' => $name, 'desc' => '',
                'in_required' => $req, 'in_actual' => $act, 'value' => $value, 'category' => $cat];
        }
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9601, 'username' => 'rf_admin', 'name' => '报表管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9602, 'username' => 'rf_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('a', 64);
        $this->projToken = str_repeat('b', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
            ['name' => '乙项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->row('基层甲', '甲项目', 1000, false, false, false);
        $this->row('管理甲', '甲项目', 5000, true, false, false);
        $this->row('案场甲', '甲项目', 800, false, true, false);
        $this->row('基层乙', '乙项目', 2000, false, false, false);

        $this->staff(1, '基层甲', '甲项目', 'staff');
        $this->staff(2, '管理甲', '甲项目', 'manager');
        $this->staff(3, '案场甲', '甲项目', 'case');
        $this->staff(4, '基层乙', '乙项目', 'staff');
        $this->staff(5, '离职甲', '甲项目', 'staff', true);

        $this->attendance('甲项目', [
            '基层甲' => ['days' => ['√', '事', '休'], 'req_attend' => 22, 'act_attend' => 20],
            '管理甲' => ['days' => ['√', '√'], 'req_attend' => 21, 'act_attend' => 21],
            '案场甲' => ['days' => ['√'], 'req_attend' => 20, 'act_attend' => 19],
        ]);
        // 乙项目不填统计列：应回退数符号（2/2）
        $this->attendance('乙项目', [
            '基层乙' => ['days' => ['√', '√']],
        ]);
    }

    private function row(string $name, string $project, float $gross, bool $mgr, bool $case, bool $hq): void
    {
        static $sid = 800;
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $project, 'staff_legacy_id' => $sid++,
            'row_data' => json_encode(['project' => $project, 'name' => $name, 'gross' => $gross, 'net' => $gross - 100,
                'base_pay' => $gross, 'perf_pay' => 0, 'soc_total' => 0, 'actual_tax' => 0], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => $mgr, 'is_case_row' => $case, 'is_hq_row' => $hq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(int $id, string $name, string $project, string $type, bool $deleted = false): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => $project,
            'position' => '岗位', 'status' => '正式', 'person_type' => $type,
            'fixed_monthly' => 5000, 'base_salary' => 4000, 'hire_date' => '2025-01-01',
            'regular_date' => '2025-04-01', 'resign_date' => null, 'deleted' => $deleted,
            'data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'dept_path' => $project . '/客服部', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attendance(string $project, array $rows): void
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . $project, 'year_month' => self::YM, 'project_name' => $project,
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_attendance_report_uses_stored_values_before_symbol_count(): void
    {
        $r = $this->get('/api/report/attendance?ym=' . self::YM . '&project=甲项目', ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $p = collect($r->json('projects'))->firstWhere('project', '甲项目');
        $this->assertSame(3, (int) $p['headcount']);
        $this->assertSame(63.0, (float) $p['required'], '应出勤取手填 22+21+20，不按符号数');
        $this->assertSame(60.0, (float) $p['actual'], '实际出勤取手填 20+21+19');

        // 未填统计列的月份回退数符号
        $r2 = $this->get('/api/report/attendance?ym=' . self::YM . '&project=乙项目', ['X-Token' => $this->adminToken]);
        $p2 = collect($r2->json('projects'))->firstWhere('project', '乙项目');
        $this->assertSame(2.0, (float) $p2['required']);
        $this->assertSame(2.0, (float) $p2['actual']);
    }

    public function test_attendance_report_filters_by_category(): void
    {
        $r = $this->get('/api/report/attendance?ym=' . self::YM . '&project=甲项目&category=manager', ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $p = collect($r->json('projects'))->firstWhere('project', '甲项目');
        $this->assertSame(1, (int) $p['headcount']);
        $this->assertSame(21.0, (float) $p['required']);
        $this->assertSame(21.0, (float) $p['actual']);
    }

    public function test_salary_report_filters_by_project_and_category(): void
    {
        $r = $this->get('/api/report/salary?ym=' . self::YM . '&category=case', ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $kpis = collect($r->json('kpis'))->keyBy('key');
        $this->assertSame(800.0, (float) $kpis['gross']['value']);
        $this->assertSame(1, (int) $kpis['count']['value']);

        $r2 = $this->get('/api/report/salary?ym=' . self::YM . '&project=乙项目', ['X-Token' => $this->adminToken]);
        $kpis2 = collect($r2->json('kpis'))->keyBy('key');
        $this->assertSame(2000.0, (float) $kpis2['gross']['value']);
        $this->assertSame(1, (int) $kpis2['count']['value']);
    }

    public function test_staff_export_excludes_deleted_by_default(): void
    {
        $default = $this->get('/api/staff/export', ['X-Token' => $this->adminToken]);
        $default->assertOk();
        $names = $this->excelNames($default->streamedContent());
        $this->assertContains('基层甲', $names);
        $this->assertNotContains('离职甲', $names, '默认导出必须过滤已删除人员');

        $all = $this->get('/api/staff/export?include_deleted=1', ['X-Token' => $this->adminToken]);
        $all->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'staff') . '.xlsx';
        file_put_contents($tmp, $all->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $header = $sheet->rangeToArray('A1:AE1')[0];
        $this->assertContains('是否已删除', $header);
        $namesAll = [];
        foreach ($sheet->rangeToArray('A2:B' . $sheet->getHighestRow()) as $row) $namesAll[] = (string) $row[1];
        $this->assertContains('离职甲', $namesAll);
    }

    private function excelNames(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'staff') . '.xlsx';
        file_put_contents($tmp, $content);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $names = [];
        foreach ($sheet->rangeToArray('A2:B' . $sheet->getHighestRow()) as $row) $names[] = (string) $row[1];
        return $names;
    }
}
