<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 数据导出 → 考勤导出（GET /api/export/attendance）
 *
 * 覆盖：项目（全部/单项目）与人员类型（全部/管理/案场）组合、
 * 合并表/全量数据包两种打包方式、未锁定项目提示不静默跳过、项目账号强制本项目。
 */
class AttendanceExportRangeTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $projToken;
    private const YM = '2026-11';

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

        $this->staff(1, '张甲管', 'manager', '甲项目');
        $this->staff(2, '李甲案', 'case', '甲项目');
        $this->staff(3, '王甲基', 'staff', '甲项目');
        $this->staff(4, '赵乙管', 'manager', '乙项目');
        $this->staff(5, '钱乙案', 'case', '乙项目');
        $this->staff(6, '孙总部', 'hq', '物业公司总部');
        $this->staff(7, '周总部管', 'manager', '物业公司总部');

        $this->block('甲项目', ['张甲管', '李甲案', '王甲基']);
        $this->block('乙项目', ['赵乙管', '钱乙案']);
        $this->block('物业公司总部', ['孙总部', '周总部管']);
    }

    private function staff(int $id, string $name, string $type, string $project): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => $project,
            'position' => '岗位' . $id, 'status' => '正式', 'person_type' => $type,
            'dept_path' => $project . '/部门' . $id, 'deleted' => false,
            'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function block(string $project, array $names, bool $locked = true): void
    {
        $days = array_fill(0, 30, '√');
        $rows = [];
        foreach ($names as $n) {
            $rows[$n] = ['days' => $days, 'req_attend' => 22, 'act_attend' => 22, 'coef' => 1,
                'stat_personal' => 0, 'stat_sick' => 0, 'stat_maternity' => 0, 'stat_paid' => 0,
                'stat_miss' => 0, 'stat_absent' => 0, 'stat_late' => 0, 'stat_early' => 0];
        }
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . $project,
            'year_month' => self::YM, 'project_name' => $project,
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'data' => json_encode(['ym' => self::YM, 'project' => $project, 'rows' => $rows, 'order' => $names], JSON_UNESCAPED_UNICODE),
            'locked' => $locked, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function exportBook(string $token, string $query): Spreadsheet
    {
        $resp = $this->get('/api/export/attendance?' . $query, ['X-Token' => $token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        return $book;
    }

    /** 取某张 Sheet 的数据行姓名（按表头定位姓名列） */
    private function names(Spreadsheet $book, int $sheetIndex = 0): array
    {
        $grid = $book->getSheet($sheetIndex)->toArray(null, true, false, false);
        $headers = $grid[2] ?? [];
        $nameIdx = array_search('姓名', $headers, true);
        $this->assertNotFalse($nameIdx, '表头应包含姓名列');
        $out = [];
        for ($i = 3; $i < count($grid); $i++) {
            $v = trim((string) ($grid[$i][$nameIdx] ?? ''));
            if ($v !== '') $out[] = $v;
        }
        return $out;
    }

    public function test_merged_all_managers_one_sheet_with_project_column(): void
    {
        $book = $this->exportBook($this->adminToken, 'ym=' . self::YM . '&project=all&person_type=manager&mode=merged');
        $this->assertSame(1, $book->getSheetCount());
        $headers = $book->getSheet(0)->toArray(null, true, false, false)[2];
        $this->assertSame('序号', $headers[0]);
        $this->assertSame('项目', $headers[1], '全部项目合并表必须输出项目列');
        $names = $this->names($book);
        $this->assertContains('张甲管', $names);
        $this->assertContains('赵乙管', $names);
        $this->assertNotContains('李甲案', $names, '案场人员不应出现在管理人员表');
        $this->assertNotContains('钱乙案', $names);
        $this->assertNotContains('周总部管', $names, '管理人员口径不含总部载体项目');
    }

    public function test_merged_single_project_case_has_no_project_column(): void
    {
        $book = $this->exportBook($this->adminToken, 'ym=' . self::YM . '&project=' . urlencode('甲项目') . '&person_type=case&mode=merged');
        $headers = $book->getSheet(0)->toArray(null, true, false, false)[2];
        $this->assertSame('序号', $headers[0]);
        $this->assertSame('部门', $headers[1], '单项目表不输出项目列');
        $this->assertSame(['李甲案'], $this->names($book));
        $this->assertStringContainsString('【甲项目】', (string) $book->getSheet(0)->getCell('A1')->getValue());
        $this->assertStringContainsString('（案场人员）', (string) $book->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_bundle_one_sheet_per_project(): void
    {
        $book = $this->exportBook($this->adminToken, 'ym=' . self::YM . '&project=' . urlencode('乙项目') . '&person_type=manager&mode=bundle');
        // 全量数据包固定全部项目；总部只有被排除的管理标记，故只有甲/乙两张表
        $this->assertSame(2, $book->getSheetCount());
        $this->assertSame(['甲项目', '乙项目'], $book->getSheetNames());
        $this->assertSame(['张甲管'], $this->names($book, 0));
        $this->assertSame(['赵乙管'], $this->names($book, 1));
    }

    public function test_unlocked_project_is_reported_and_skipped(): void
    {
        DB::table('payroll_attendance')->where('record_key', self::YM . '|乙项目')->update(['locked' => false]);

        $preview = $this->getJson('/api/export/attendance?ym=' . self::YM . '&project=all&person_type=case&mode=merged&dry_run=1', ['X-Token' => $this->adminToken]);
        $preview->assertOk()->assertJson([
            'ok' => true,
            'exported' => ['甲项目'],
            'skipped_unlocked' => ['乙项目'],
        ]);

        $book = $this->exportBook($this->adminToken, 'ym=' . self::YM . '&project=all&person_type=case&mode=merged');
        $names = $this->names($book);
        $this->assertContains('李甲案', $names);
        $this->assertNotContains('钱乙案', $names, '未锁定项目不导出，且必须先提示');
    }

    public function test_project_scope_can_only_export_own_project(): void
    {
        $book = $this->exportBook($this->projToken, 'ym=' . self::YM . '&project=' . urlencode('乙项目') . '&person_type=case&mode=merged');
        $this->assertSame(['李甲案'], $this->names($book), '项目账号即使传其他项目也只能导本项目');
        $this->assertStringContainsString('【甲项目】', (string) $book->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_invalid_params_rejected(): void
    {
        $this->getJson('/api/export/attendance?ym=' . self::YM . '&person_type=alien', ['X-Token' => $this->adminToken])
            ->assertStatus(400)->assertJson(['ok' => false]);
        $this->getJson('/api/export/attendance?ym=' . self::YM . '&mode=alien', ['X-Token' => $this->adminToken])
            ->assertStatus(400)->assertJson(['ok' => false]);
        $this->getJson('/api/export/attendance?ym=2026-13', ['X-Token' => $this->adminToken])
            ->assertStatus(400)->assertJson(['ok' => false]);
    }

    public function test_no_locked_data_returns_404_with_skipped_list(): void
    {
        DB::table('payroll_attendance')->update(['locked' => false]);
        $resp = $this->getJson('/api/export/attendance?ym=' . self::YM . '&project=all&person_type=manager&mode=merged', ['X-Token' => $this->adminToken]);
        $resp->assertNotFound()->assertJson(['ok' => false]);
        $this->assertContains('甲项目', $resp->json('skipped_unlocked'));
    }
}
