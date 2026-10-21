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
 * 考勤表绩效系数来源约束（2026-10-21 用户口径）：
 *  - 季度绩效法人员的系数只能走季度末月弹窗录入，考勤表该列填了非 1.0 的值 → 整单拒绝
 *  - 留空或填 1（模板默认中性值）→ 放行
 *  - 月度绩效法人员照旧可从考勤表上传系数
 *  - 回归：模板导出的「绩效系数」表头必须能被上传解析识别（FIELD_MAP）
 */
class AttendanceQuarterCoefGuardTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private const PROJ = '物业总部';
    private const COEF_COL = 37; // 日期列之后（1..31 日占 5..35，应出勤 36，绩效系数 37）
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9010, 'username' => 'hq_admin2', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('b', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            'name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 总部按季度绩效法；基层员工按月
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'calc_rules.json',
            'payload' => json_encode(['rules' => ['pay_rules' => [
                'hq' => ['cycle' => 'quarter_grade', 'levels' => ['经理级' => ['mode' => 'quarter']]],
                'staff' => ['cycle' => 'monthly', 'ratio' => 1.0],
            ]]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_staff')->insert([
            ['legacy_id' => 8001, 'name' => '王玉栋', 'project_name' => self::PROJ, 'position' => '财务经理',
             'status' => '正式', 'person_type' => 'hq', 'hire_date' => '2020-01-01',
             'fixed_monthly' => 20000, 'base_salary' => 5000, 'data' => '{}',
             'created_at' => now(), 'updated_at' => now()],
            ['legacy_id' => 8002, 'name' => '赵国昌', 'project_name' => self::PROJ, 'position' => '主管',
             'status' => '正式', 'person_type' => 'staff', 'hire_date' => '2022-01-01',
             'fixed_monthly' => 8000, 'base_salary' => 4000, 'data' => '{}',
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** 构造考勤 xlsx；$coef 为 null 表示该列留空 */
    private function buildXlsx(string $name, ?float $coef): string
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
        $coefLetter = Coordinate::stringFromColumnIndex(self::COEF_COL);
        $sheet->setCellValue("{$coefLetter}3", '绩效系数');
        $sheet->setCellValue('A5', 1);
        $sheet->setCellValue('B5', $name);
        $sheet->setCellValue('C5', '正式');
        for ($d = 1; $d <= 20; $d++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + $d) . '5', '√');
        }
        if ($coef !== null) $sheet->setCellValue("{$coefLetter}5", $coef);
        $path = tempnam(sys_get_temp_dir(), 'qcoef') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path)
    {
        return $this->post('/api/attendance/upload', [
            'ym' => self::YM, 'project' => self::PROJ, 'dry_run' => '1',
            'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
        ], ['X-Token' => $this->adminToken]);
    }

    public function test_quarter_grade_staff_with_coef_is_rejected(): void
    {
        $path = $this->buildXlsx('王玉栋', 0.8);
        try {
            $resp = $this->upload($path);
            $resp->assertStatus(400);
            $this->assertStringContainsString('季度绩效法', (string) $resp->json('error'));
            $this->assertStringContainsString('王玉栋', (string) $resp->json('error'));
            // 整单拒绝：不写入任何考勤块
            $this->assertSame(0, DB::table('payroll_attendance')->where('year_month', self::YM)->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_quarter_grade_staff_with_blank_or_one_coef_passes(): void
    {
        foreach ([null, 1.0] as $v) {
            $path = $this->buildXlsx('王玉栋', $v);
            try {
                $resp = $this->upload($path);
                $resp->assertOk();
                $this->assertTrue((bool) $resp->json('ok'), '留空/1.0 应放行：' . ($resp->json('error') ?? ''));
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_monthly_staff_can_still_upload_coef(): void
    {
        $path = $this->buildXlsx('赵国昌', 0.8);
        try {
            $resp = $this->upload($path);
            $resp->assertOk();
            $this->assertTrue((bool) $resp->json('ok'), '月度法应放行：' . ($resp->json('error') ?? ''));
        } finally {
            @unlink($path);
        }
    }

    public function test_exported_coef_header_is_parsed_and_stored(): void
    {
        $path = $this->buildXlsx('赵国昌', 0.75);
        try {
            $resp = $this->post('/api/attendance/upload', [
                'ym' => self::YM, 'project' => self::PROJ,
                'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
            ], ['X-Token' => $this->adminToken]);
            $resp->assertOk();
        } finally {
            @unlink($path);
        }
        $rec = DB::table('payroll_attendance')->where('year_month', self::YM)->where('project_name', self::PROJ)->first();
        $this->assertNotNull($rec, '正式上传应写入考勤块');
        $rows = json_decode((string) $rec->rows, true);
        // 回归：导出模板表头写的是「绩效系数」，解析必须能识别并入库存值
        $this->assertEqualsWithDelta(0.75, (float) ($rows['赵国昌']['coef'] ?? 0), 0.001);
    }
}
