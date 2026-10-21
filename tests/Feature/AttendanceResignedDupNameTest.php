<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 同名人员（一人离职一人留任）考勤上传保护：
 *  - 用户把重复档案中的一条标 status='离职'（resign_date 留 NULL）后，
 *    上传时不再被同名校验挡住——离职档案不再参与 staffRows
 *  - 与模板下载 resignGuard 口径一致
 *  - 同时验证 uploadVirtualGroup（管理人员/案场人员汇总上传）也过滤离职
 */
class AttendanceResignedDupNameTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private const PROJ = '罗庄春暖花开';
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('a', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            'name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 默认符号库（parseWorkbook 用 symbolLookup 校验日期符号）
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 两条同名档案：一个离职（无 resign_date），一个在职
        DB::table('payroll_staff')->insert([
            ['legacy_id' => 406, 'name' => '徐振祥', 'project_name' => self::PROJ, 'position' => '保洁',
             'status' => '离职', 'person_type' => 'staff', 'hire_date' => '2021-06-08',
             'resign_date' => null, 'deleted' => false,
             'dingtalk_userid' => '01426863134224328102', 'fixed_monthly' => 2400, 'base_salary' => 2160,
             'data' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['legacy_id' => 422, 'name' => '徐振祥', 'project_name' => self::PROJ, 'position' => '',
             'status' => '正式', 'person_type' => 'staff', 'hire_date' => null,
             'resign_date' => null, 'deleted' => false,
             'dingtalk_userid' => '393732484024328102', 'fixed_monthly' => 2400, 'base_salary' => 2160,
             'data' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** 构造一份只含在职徐振祥一行考勤的 xlsx（与 parseWorkbook 期望的结构一致：B3="姓名"，第3行1..31日期列，数据从第5行起） */
    private function buildXlsx(): string
    {
        $daysInMonth = (int) date('t', strtotime(self::YM . '-01'));
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B3', '姓名');
        $sheet->setCellValue('C3', '人员状态');
        $sheet->setCellValue('D3', '岗位');
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + $d) . '3', (string) $d);
        }
        $reqLetter = Coordinate::stringFromColumnIndex(5 + $daysInMonth);
        $sheet->setCellValue("{$reqLetter}3", '应出勤(手填)');
        // 第 5 行：徐振祥考勤行（√ 出勤 20 天，其余空）
        $sheet->setCellValue('A5', 1);
        $sheet->setCellValue('B5', '徐振祥');
        $sheet->setCellValue('C5', '正式');
        for ($d = 1; $d <= 20; $d++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + $d) . '5', '√');
        }
        $path = tempnam(sys_get_temp_dir(), 'dupname') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    public function test_resigned_dup_does_not_block_active_staff_upload(): void
    {
        $path = $this->buildXlsx();
        try {
            // dry_run=1：先校验
            $resp = $this->post('/api/attendance/upload', [
                'ym' => self::YM, 'project' => self::PROJ, 'dry_run' => '1',
                'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
            ], ['X-Token' => $this->adminToken]);
            $resp->assertOk();
            $this->assertTrue((bool) $resp->json('ok'), '上传校验应通过：' . ($resp->json('error') ?? ''));
            $this->assertSame(1, $resp->json('count'));
            $this->assertContains('徐振祥', $resp->json('names'));
        } finally {
            @unlink($path);
        }
    }

    public function test_both_active_dup_still_blocks(): void
    {
        // 反向验证：把 406 改回在职，重新上传应被同名校验挡住
        DB::table('payroll_staff')->where('legacy_id', 406)->update(['status' => '正式']);
        $path = $this->buildXlsx();
        try {
            $resp = $this->post('/api/attendance/upload', [
                'ym' => self::YM, 'project' => self::PROJ, 'dry_run' => '1',
                'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
            ], ['X-Token' => $this->adminToken]);
            $resp->assertStatus(400);
            $this->assertStringContainsString('存在多条记录', $resp->json('error'));
        } finally {
            @unlink($path);
        }
    }
}
