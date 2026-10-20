<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 管理人员/案场人员考勤表：下拉虚拟选项的模板下载（跨项目、按项目分组、带出已填数据）
 * 与汇总上传（按人合并进各项目考勤块，不影响块内其他人）。
 *
 * 哨兵值：project=__managers__（管理人员）/ __case__（案场人员）。
 */
class AttendanceGroupTemplateTest extends TestCase
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
                ['symbol' => '√', 'category' => '', 'value' => 1, 'in_actual' => 1],
                ['symbol' => '休', 'category' => '', 'value' => 0, 'in_actual' => 0],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(int $id, string $name, string $type, string $project, array $extra = []): void
    {
        DB::table('payroll_staff')->insert([[
            'legacy_id' => $id, 'name' => $name, 'project_name' => $project,
            'position' => '岗位' . $id, 'status' => '正式', 'person_type' => $type,
            'deleted' => false, 'dingtalk_userid' => 'dt' . $id,
            'hire_date' => '2025-01-01', 'created_at' => now(), 'updated_at' => now(),
        ] + $extra]);
    }

    private function seedWorld(): void
    {
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $this->staff(2, '经理乙', 'manager', '乙项目');
        $this->staff(3, '案场甲', 'case', '甲项目');
        $this->staff(4, '员工甲', 'staff', '甲项目');
        $this->staff(5, '员工乙', 'staff', '乙项目');
        $this->staff(6, '总部王', 'hq', '物业公司总部');
    }

    /** 构造可被 parseWorkbook 解析的最小考勤 xlsx，返回临时路径（$statusHeader 兼容新旧表头） */
    private function makeWorkbook(array $people, string $statusHeader = '人员状态'): string
    {
        $days = (int) date('t', strtotime(self::YM . '-01'));
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B3', '姓名')->setCellValue('C3', $statusHeader)->setCellValue('D3', '岗位');
        for ($d = 1; $d <= $days; $d++) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4 + $d) . '3', (string) $d);
        }
        $reqLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5 + $days);
        $sheet->setCellValue("{$reqLetter}3", '应出勤(手填)');
        $r = 5;
        foreach ($people as $i => $p) {
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValue("B{$r}", $p['name']);
            if (isset($p['status'])) $sheet->setCellValue("C{$r}", $p['status']);
            $syms = $p['days'] ?? [];
            for ($d = 1; $d <= $days; $d++) {
                $sym = $syms[$d - 1] ?? '';
                if ($sym !== '') {
                    $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4 + $d) . $r, $sym);
                }
            }
            if (isset($p['req'])) $sheet->setCellValue("{$reqLetter}{$r}", $p['req']);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'attgrp') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path, string $group, string $token, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/attendance/upload', [
            'ym' => self::YM, 'project' => $group,
            'file' => new UploadedFile($path, 'g.xlsx', null, null, true),
        ] + $extra, ['X-Token' => $token]);
    }

    /** 读取下载响应中的姓名列（表头第3行按“姓名”动态定位，兼容单项目/多项目两种列序；数据从第5行开始） */
    private function namesFromDownload(\Illuminate\Testing\TestResponse $resp): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dl') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $nameCol = null;
        for ($c = 1; $c <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()); $c++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            if (trim((string) $sheet->getCell("{$letter}3")->getValue()) === '姓名') {
                $nameCol = $letter;
                break;
            }
        }
        $names = [];
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            $v = trim((string) $sheet->getCell("{$nameCol}{$r}")->getValue());
            if ($v !== '') $names[] = $v;
        }
        return $names;
    }

    private function blockRows(string $project): array
    {
        $row = DB::table('payroll_attendance')->where('record_key', self::YM . '|' . $project)->first();
        return $row ? (json_decode((string) $row->rows, true) ?: []) : [];
    }

    // ===== 模板下载 =====

    public function test_manager_template_lists_non_hq_managers_grouped_by_project(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $names = $this->namesFromDownload($resp);
        // 含两个项目的管理人员（按项目分组排列，具体项目先后依赖数据库排序，此处比较集合）；不含基层/案场/物业总部
        $this->assertEqualsCanonicalizing(['经理甲', '经理乙'], $names);
        $this->assertStringContainsString('filename*=utf-8', strtolower($resp->headers->get('Content-Disposition') ?? ''));
        $this->assertStringContainsString(rawurlencode('管理人员'), $resp->headers->get('Content-Disposition') ?? '');
    }

    public function test_case_template_lists_only_case_staff(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=__case__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $this->assertSame(['案场甲'], $this->namesFromDownload($resp));
    }

    public function test_group_template_prefills_existing_attendance_symbols(): void
    {
        $this->seedWorld();
        // 甲项目块已上传：经理甲 1日出勤√；乙项目块无数据
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|甲项目', 'year_month' => self::YM, 'project_name' => '甲项目',
            'rows' => json_encode(['经理甲' => ['days' => array_pad(['√'], 31, ''), 'req_attend' => 22]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->adminToken]);
        $tmp = tempnam(sys_get_temp_dir(), 'dl') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        // 找到经理甲所在行与姓名列（表头第3行按“姓名”定位）；日期区从姓名列后第3列起（员工状态、入职日期），1 日 = 该列
        $nameCol = null;
        for ($c = 1; $c <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()); $c++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            if (trim((string) $sheet->getCell("{$letter}3")->getValue()) === '姓名') { $nameCol = $letter; break; }
        }
        $this->assertNotNull($nameCol);
        $jiaRow = null;
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            if (trim((string) $sheet->getCell("{$nameCol}{$r}")->getValue()) === '经理甲') { $jiaRow = $r; break; }
        }
        $this->assertNotNull($jiaRow);
        $day1Col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($nameCol) + 3
        );
        $this->assertSame('√', trim((string) $sheet->getCell("{$day1Col}{$jiaRow}")->getValue()));
    }

    public function test_project_role_with_sentinel_only_gets_own_project_template(): void
    {
        $this->seedWorld();
        // 项目角色伪造哨兵：服务端强制覆盖为本项目，拿到的是甲项目表，看不到乙项目/总部任何人
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->projToken]);
        $resp->assertOk();
        $this->assertEqualsCanonicalizing(['经理甲', '案场甲', '员工甲'], $this->namesFromDownload($resp));
    }

    // ===== 汇总上传：按人合并 =====

    public function test_group_upload_merges_into_each_project_block_and_keeps_other_rows(): void
    {
        $this->seedWorld();
        // 甲项目块已有基层员工甲的数据（项目表先传过）
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|甲项目', 'year_month' => self::YM, 'project_name' => '甲项目',
            'rows' => json_encode(['员工甲' => ['days' => array_pad(['√'], 31, '')]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = $this->makeWorkbook([
            ['name' => '经理甲', 'days' => array_pad(['√', '休'], 30, '')],
            ['name' => '经理乙', 'days' => array_pad(['休'], 30, '')],
        ]);
        $this->upload($path, '__managers__', $this->adminToken)->assertOk()->assertJson(['ok' => true, 'count' => 2]);

        $jia = $this->blockRows('甲项目');
        $yi = $this->blockRows('乙项目');
        $this->assertArrayHasKey('员工甲', $jia, '块内基层员工数据必须保留');
        $this->assertArrayHasKey('经理甲', $jia);
        $this->assertSame('√', $jia['经理甲']['days'][0]);
        $this->assertSame(1, $jia['经理甲']['staff_id']);
        $this->assertSame('dt1', $jia['经理甲']['dingtalk_userid']);
        $this->assertArrayHasKey('经理乙', $yi);
        $this->assertCount(1, $yi, '乙项目块应只有经理乙');
    }

    public function test_group_upload_dry_run_does_not_write(): void
    {
        $this->seedWorld();
        $path = $this->makeWorkbook([['name' => '案场甲', 'days' => array_pad(['√'], 30, '')]]);
        $this->upload($path, '__case__', $this->adminToken, ['dry_run' => '1'])
            ->assertOk()->assertJson(['ok' => true, 'preview' => true, 'count' => 1]);
        $this->assertNull(DB::table('payroll_attendance')->where('record_key', self::YM . '|甲项目')->first());
    }

    public function test_group_upload_rejects_name_outside_group(): void
    {
        $this->seedWorld();
        // 员工甲是基层，不在管理人员表受理范围
        $path = $this->makeWorkbook([['name' => '员工甲', 'days' => array_pad(['√'], 30, '')]]);
        $resp = $this->upload($path, '__managers__', $this->adminToken);
        $resp->assertStatus(400);
        $this->assertStringContainsString('员工甲', (string) $resp->json('error'));
    }

    public function test_group_upload_rejects_cross_project_duplicate_names(): void
    {
        $this->seedWorld();
        $this->staff(7, '经理甲', 'manager', '乙项目'); // 与 legacy_id=1 同名跨项目
        $path = $this->makeWorkbook([['name' => '经理甲', 'days' => array_pad(['√'], 30, '')]]);
        $resp = $this->upload($path, '__managers__', $this->adminToken);
        $resp->assertStatus(400);
        $this->assertStringContainsString('经理甲', (string) $resp->json('error'));
    }

    public function test_group_upload_rejects_when_any_involved_project_locked(): void
    {
        $this->seedWorld();
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|乙项目', 'year_month' => self::YM, 'project_name' => '乙项目',
            'rows' => json_encode(['员工乙' => ['days' => array_pad(['√'], 31, '')]], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = $this->makeWorkbook([
            ['name' => '经理甲', 'days' => array_pad(['√'], 30, '')],
            ['name' => '经理乙', 'days' => array_pad(['√'], 30, '')],
        ]);
        $this->upload($path, '__managers__', $this->adminToken)->assertStatus(400);
        // 甲项目未锁定也不能部分写入（整单拒绝）
        $this->assertSame([], $this->blockRows('甲项目'));
    }

    public function test_project_role_with_sentinel_cannot_touch_other_projects(): void
    {
        $this->seedWorld();
        // 项目角色伪造哨兵上传乙项目人员：哨兵被强制覆盖为甲项目，经理乙不在甲项目档案 → 拒绝，乙项目块无写入
        $path = $this->makeWorkbook([['name' => '经理乙', 'days' => array_pad(['√'], 30, '')]]);
        $this->upload($path, '__managers__', $this->projToken)->assertStatus(400);
        $this->assertSame([], $this->blockRows('乙项目'));
        $this->assertSame([], $this->blockRows('甲项目'));
    }

    // ===== 项目表上传：整表覆盖（2026-10-18 口径）=====

    public function test_project_upload_overwrites_whole_block_removing_absent_rows(): void
    {
        $this->seedWorld();
        // 旧块含经理甲+员工甲；本次项目表只传员工甲（经理甲的行不在表里）
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|甲项目', 'year_month' => self::YM, 'project_name' => '甲项目',
            'rows' => json_encode([
                '经理甲' => ['days' => array_pad(['休'], 31, '')],
                '员工甲' => ['days' => array_pad(['休'], 31, '')],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = $this->makeWorkbook([['name' => '员工甲', 'days' => array_pad(['√'], 30, '')]]);
        $this->upload($path, '甲项目', $this->adminToken)->assertOk()->assertJson(['overwrite' => true]);

        $jia = $this->blockRows('甲项目');
        $this->assertArrayNotHasKey('经理甲', $jia, '整表覆盖：不在新表中的旧人员行被移除');
        $this->assertSame('√', $jia['员工甲']['days'][0]);
        $this->assertCount(1, $jia);
    }

    // ===== 福利列（已发福利/奖励）=====

    /** 读取模板响应第 3 行表头（0 基数组） */
    private function headersFromDownload(\Illuminate\Testing\TestResponse $resp): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hdr') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $out = [];
        for ($c = 1; $c <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()); $c++) {
            $out[] = trim((string) $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '3')->getValue());
        }
        return $out;
    }

    public function test_template_contains_welfare_column_right_after_reward(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=甲项目', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $headers = $this->headersFromDownload($resp);
        $idx = array_search('已发福利/奖励', $headers, true);
        $this->assertNotFalse($idx, '考勤模板必须包含「已发福利/奖励」列');
        $this->assertSame('月度奖励金额', $headers[$idx - 1], '福利列应紧随「月度奖励金额」之后');
    }

    public function test_template_welfare_value_roundtrips_on_upload(): void
    {
        $this->seedWorld();
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=甲项目', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'rt') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $nameCol = null; $welfareCol = null;
        for ($c = 1; $c <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()); $c++) {
            $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $h = trim((string) $sheet->getCell("{$L}3")->getValue());
            if ($h === '姓名') $nameCol = $L;
            if ($h === '已发福利/奖励') $welfareCol = $L;
        }
        $this->assertNotNull($nameCol); $this->assertNotNull($welfareCol);
        $targetRow = null;
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            if (trim((string) $sheet->getCell("{$nameCol}{$r}")->getValue()) === '员工甲') { $targetRow = $r; break; }
        }
        $this->assertNotNull($targetRow);
        $sheet->setCellValue("{$welfareCol}{$targetRow}", 150);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet->getParent()))->save($tmp);
        $this->upload($tmp, '甲项目', $this->adminToken)->assertOk();
        @unlink($tmp);
        $rows = $this->blockRows('甲项目');
        $this->assertSame(150.0, (float) ($rows['员工甲']['welfare'] ?? 0), '模板填写的福利应被解析入库');
    }

    public function test_group_template_prefills_welfare_from_project_block(): void
    {
        $this->seedWorld();
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|甲项目', 'year_month' => self::YM, 'project_name' => '甲项目',
            'rows' => json_encode(['经理甲' => ['days' => array_pad(['√'], 31, ''), 'welfare' => 88]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resp = $this->get('/api/attendance/template?ym=' . self::YM . '&project=__managers__', ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'pf') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $nameCol = null; $welfareCol = null;
        for ($c = 1; $c <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()); $c++) {
            $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $h = trim((string) $sheet->getCell("{$L}3")->getValue());
            if ($h === '姓名') $nameCol = $L;
            if ($h === '已发福利/奖励') $welfareCol = $L;
        }
        $this->assertNotNull($welfareCol, '虚拟组模板必须含福利列');
        $row = null;
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            if (trim((string) $sheet->getCell("{$nameCol}{$r}")->getValue()) === '经理甲') { $row = $r; break; }
        }
        $this->assertNotNull($row);
        $this->assertSame(88, (int) $sheet->getCell("{$welfareCol}{$row}")->getValue(), '虚拟组模板应带出福利值');
    }

    public function test_upload_accepts_new_status_header(): void
    {
        // 回归：2026-10-09 模板表头「人员状态」改为「员工状态」但解析端未同步，
        // 导致新表头时代上传的考勤 status 被丢弃（查看页状态列显示"-"）。
        // FIELD_MAP 现双表头兼容，新表头需正常入库。
        $this->staff(1, '经理甲', 'manager', '甲项目');
        $path = $this->makeWorkbook([
            ['name' => '经理甲', 'status' => '正式', 'days' => array_pad(['√'], 5, '')],
        ], '员工状态');
        $resp = $this->upload($path, '__managers__', $this->adminToken);
        $resp->assertOk();
        $rows = $this->blockRows('甲项目');
        $this->assertSame('正式', $rows['经理甲']['status'] ?? null, '「员工状态」表头的状态值应入库');
    }
}
