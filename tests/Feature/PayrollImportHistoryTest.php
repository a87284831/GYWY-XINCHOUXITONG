<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 历史工资导入：35 列完整工资表 → payroll_results 归档行。
 * 验证：全字段落库、archived 标记、年中入职入职前月份跳过、重复导入幂等更新、
 * 精简列（缺省列）兼容。
 */
class PayrollImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

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
    }

    private function seedStaff(int $id, string $name, string $hire, float $fixed = 4200, float $base = 3000): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'dept_path' => '测试项目/客服部',
            'status' => '正式', 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => $hire, 'regular_date' => $hire, 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff', 'data' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private const HEADERS = ['序号', '项目', '部门', '岗位', '姓名', '人员状态', '固定月薪', '基本工资', '应出勤', '实际出勤',
        '绩效系数', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴', '月度奖励',
        '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款', '应发工资合计', '养老保险',
        '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计', '专项附加扣除', '本月个税', '实发工资', '备注'];

    /** 构造含两个月份 sheet 的完整 35 列工资表 xlsx，返回 UploadedFile */
    private function makeUpload(array $sheetsData): UploadedFile
    {
        $book = new Spreadsheet();
        $first = true;
        foreach ($sheetsData as $sheetName => $rows) {
            $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle($sheetName);
            $sheet->mergeCells('A1:AI1');
            $sheet->setCellValue('A1', $sheetName . ' 工资表');
            $sheet->fromArray(self::HEADERS, null, 'A2');
            $r = 3;
            foreach ($rows as $row) {
                $sheet->fromArray($row, null, 'A' . $r);
                $r++;
            }
            $first = false;
        }
        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($book))->save($path);
        return new UploadedFile($path, 'history.xlsx', null, null, true);
    }

    private function rowFor(string $name, float $gross, float $soc, float $tax, float $net, string $dept = '客服部'): array
    {
        // 35 列：仅关键字段赋值，其余为 0 / 空
        return [1, '测试项目', $dept, '客服管家', $name, '正式',
            4200, 3000, 26, 26, 1, 3000, 1500, 0, 100, 300, 0, 200, 0, 0, 0, 0, 0, 0,
            $gross, 384, 96, 24, 576, 0, $soc, 0, $tax, $net, ''];
    }

    public function test_full_35col_import_writes_archived_rows_and_skips_before_hire(): void
    {
        $this->seedStaff(1, '张三', '2026-01-01');
        $this->seedStaff(2, '李四', '2026-07-01'); // 年中入职：1月行应跳过

        $file = $this->makeUpload([
            '2026-01' => [
                $this->rowFor('张三', 5100, 1080, 30, 3990),
                $this->rowFor('李四', 5000, 1000, 0, 4000),
            ],
            '2026-02' => [
                $this->rowFor('张三', 5200, 1080, 33.6, 4086.4),
            ],
            '2026-03' => [], // 空月份 Sheet（模板预置但未填写）：应跳过且不报错
        ]);

        $resp = $this->post('/api/payroll/import-history', ['file' => $file],
            ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('ok', true);
        $json = $resp->json();
        $this->assertSame(3, $json['sheets']);
        $this->assertSame(2, $json['inserted']);   // 张三1月/2月；李四1月被跳过；空Sheet0行
        $this->assertSame(1, $json['skipped_before_hire']);
        $this->assertSame([], $json['errors']);

        // 张三 1 月：归档行 + 全字段
        $rec = DB::table('payroll_results')
            ->where('year_month', '2026-01')->where('staff_legacy_id', 1)->first();
        $this->assertNotNull($rec);
        $this->assertTrue((bool) $rec->archived);
        $d = json_decode($rec->row_data, true);
        $this->assertEquals(1, $d['staff_id']);
        $this->assertSame('张三', $d['name']);
        $this->assertSame('客服部', $d['department']);
        $this->assertSame('正式', $d['status']);
        $this->assertEquals(4200, $d['fixed']);
        $this->assertEquals(3000, $d['base_pay']);
        $this->assertEquals(5100, $d['gross']);
        $this->assertEquals(1080, $d['soc_total']);
        $this->assertEquals(30, $d['actual_tax']);
        $this->assertEquals(3990, $d['net']);
        $this->assertSame('', $d['remark']);
        $this->assertTrue($d['imported_history']);
        // 李四 1 月不应存在
        $this->assertNull(DB::table('payroll_results')
            ->where('year_month', '2026-01')->where('staff_legacy_id', 2)->first());
    }

    public function test_reimport_same_month_updates_instead_of_duplicate(): void
    {
        $this->seedStaff(1, '张三', '2026-01-01');
        $upload = fn() => $this->makeUpload([
            '2026-01' => [$this->rowFor('张三', 5100, 1080, 30, 3990)],
        ]);
        $this->post('/api/payroll/import-history', ['file' => $upload()], ['X-Token' => $this->token])->assertOk();
        $resp = $this->post('/api/payroll/import-history', ['file' => $upload()], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('inserted', 0);
        $this->assertSame(1, DB::table('payroll_results')->where('year_month', '2026-01')->count());
    }

    public function test_sparse_columns_and_staff_fallback(): void
    {
        $this->seedStaff(1, '张三', '2026-01-01', 4200, 3000);
        // 精简表：仅 姓名/项目/应发工资合计/本月个税（表头带 * 标记），无部门、无五险一金、无实发
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('2026-01');
        $sheet->fromArray(['姓名*', '项目*', '应发工资合计*', '本月个税*'], null, 'A1');
        $sheet->fromArray(['张三', '测试项目', 6000, 30], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 's.xlsx', null, null, true);

        $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token])
            ->assertOk()->assertJsonPath('inserted', 1);

        $d = json_decode(DB::table('payroll_results')->where('year_month', '2026-01')->first()->row_data, true);
        $this->assertEquals(6000, $d['gross']);
        $this->assertEquals(30, $d['actual_tax']);
        $this->assertEquals(0, $d['soc_total']);
        // 实发自动公式：应发-五险一金-个税-福利
        $this->assertEquals(5970, $d['net']);
        // 留空字段回退人员档案
        $this->assertSame('测试岗', $d['position']);
        $this->assertSame('客服部', $d['department']);
        $this->assertEquals(4200, $d['fixed']);
        $this->assertEquals(3000, $d['base']);
        $this->assertSame('正式', $d['status']);
    }

    public function test_deleted_or_resigned_staff_still_importable_for_history(): void
    {
        // 历史月份工资是事实数据：离职、已删档人员当月确实领薪，必须能导入
        $this->seedStaff(1, '张三', '2026-01-01');
        DB::table('payroll_staff')->where('legacy_id', 1)
            ->update(['status' => '离职', 'resign_date' => '2026-03-15']);
        $this->seedStaff(2, '李四', '2026-01-01');
        DB::table('payroll_staff')->where('legacy_id', 2)->update(['deleted' => true]);

        $file = $this->makeUpload([
            '2026-01' => [
                $this->rowFor('张三', 5100, 1080, 30, 3990),
                $this->rowFor('李四', 5000, 1000, 0, 4000),
            ],
        ]);
        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('errors', []);
    }

    public function test_duplicate_name_disambiguates_by_deleted_flag(): void
    {
        // 同名同项目存在两条档案（正式 + 离职删档）：历史工资应匹配未删档人员
        $this->seedStaff(1, '张三', '2026-01-01');
        $this->seedStaff(2, '张三', '2026-01-01');
        DB::table('payroll_staff')->where('legacy_id', 2)->update(['deleted' => true]);

        $file = $this->makeUpload([
            '2026-01' => [$this->rowFor('张三', 5100, 1080, 30, 3990)],
        ]);
        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('errors', []);

        $rec = DB::table('payroll_results')->where('year_month', '2026-01')->first();
        $this->assertEquals(1, json_decode($rec->row_data, true)['staff_id']);
    }

    public function test_project_mismatch_falls_back_to_unique_name(): void
    {
        // 线下表项目简称/错写导致项目不匹配：同名唯一时按姓名兜底，行归入档案项目
        $this->seedStaff(1, '张三', '2026-01-01', 4200, 3000);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('2026-01');
        $sheet->fromArray(['姓名*', '项目*', '应发工资合计*', '本月个税*'], null, 'A1');
        $sheet->fromArray(['张三', '项目简称写错', 6000, 30], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'm.xlsx', null, null, true);

        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('errors', []);

        // 行归入档案项目，保证与系统项目口径一致
        $d = json_decode(DB::table('payroll_results')->where('year_month', '2026-01')->first()->row_data, true);
        $this->assertSame('测试项目', $d['project']);
        $this->assertEquals(1, $d['staff_id']);
    }

    public function test_project_mismatch_with_ambiguous_name_reports_error(): void
    {
        // 同名两条在档且项目都不匹配线下值：不能猜，必须报错人工核对
        $this->seedStaff(1, '张三', '2026-01-01');
        DB::table('payroll_staff')->insert([
            'legacy_id' => 2, 'name' => '张三', 'project_name' => '其他项目', 'position' => '测试岗',
            'dept_path' => '其他项目/客服部',
            'status' => '正式', 'fixed_monthly' => 4200, 'base_salary' => 3000,
            'hire_date' => '2026-01-01', 'regular_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff', 'data' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('2026-01');
        $sheet->fromArray(['姓名*', '项目*', '应发工资合计*', '本月个税*'], null, 'A1');
        $sheet->fromArray(['张三', '第三种项目名', 6000, 30], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'm.xlsx', null, null, true);

        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk();
        $this->assertSame(0, $resp->json('inserted'));
        $this->assertStringContainsString('重名', $resp->json('errors.0'));
        $this->assertSame(0, DB::table('payroll_results')->count());
    }

    public function test_error_report_generated_and_downloadable(): void
    {
        $this->seedStaff(1, '张三', '2026-01-01');
        $file = $this->makeUpload([
            '2026-01' => [
                $this->rowFor('张三', 5100, 1080, 30, 3990),
                $this->rowFor('王五', 5000, 1000, 0, 4000), // 档案无此人 → 错误
            ],
        ]);
        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('inserted', 1);
        $token = $resp->json('error_file');
        $this->assertNotEmpty($token);

        // 下载报告并解析：应包含失败行明细
        // BinaryFileResponse 的 getContent() 拿不到文件体，须经 getFile() 读
        $dl = $this->get('/api/payroll/import-history/error-report/' . $token, ['X-Token' => $this->token]);
        $dl->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'err') . '.xlsx';
        file_put_contents($tmp, $dl->baseResponse->getFile()->getContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $this->assertSame('王五', $sheet->getCell('D5')->getValue());
        $this->assertStringContainsString('不在系统人员档案中', (string) $sheet->getCell('F5')->getValue());

        // 无效 token → 404
        $this->get('/api/payroll/import-history/error-report/nope', ['X-Token' => $this->token])
            ->assertNotFound();
    }

    public function test_no_error_report_when_all_rows_ok(): void
    {
        $this->seedStaff(1, '张三', '2026-01-01');
        $file = $this->makeUpload([
            '2026-01' => [$this->rowFor('张三', 5100, 1080, 30, 3990)],
        ]);
        $resp = $this->post('/api/payroll/import-history', ['file' => $file], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJsonPath('errors', []);
        $this->assertNull($resp->json('error_file'));
    }
}
