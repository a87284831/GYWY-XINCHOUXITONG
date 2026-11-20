<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * 工资表导出双版本：
 * - plain：既有 39 列版式不变；
 * - formula：40 列（新增扣款合计）、四条派生列公式、合计行 ROUND(SUM)、校验区、说明页、汇总跨表引用。
 */
class PayrollExportFormulaTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $staffSeq = 100;

    private const YM = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9101, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('f', 64);
        Cache::put('payroll_api_token:' . $this->token, $id, now()->addHours(8));
        foreach (['甲项目', '乙项目'] as $p) {
            DB::table('payroll_projects')->insert([
                'name' => $p, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'project' => '甲项目', 'department' => '客服部', 'position' => '专员', 'name' => '张三',
            'status' => '正式', 'hire_date' => '2026-01-01',
            'fixed' => 5000, 'base' => 4000, 'req_att' => 22, 'act_att' => 22, 'coef' => 1,
            'base_pay' => 4000, 'perf_pay' => 1000, 'sick_pay' => 0, 'night' => 0, 'meal' => 0,
            'title_sub' => 0, 'reward' => 0, 'backpay' => 0, 'welfare' => 0,
            'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
            'gross' => 5000, 'pen' => 500, 'med' => 200, 'une' => 100, 'house' => 400, 'big' => 50,
            'soc_total' => 1250, 'spec_total' => 0, 'actual_tax' => 0, 'tax_diff' => 0,
            'net' => 3750, 'remark' => '', 'perf_detail' => null,
        ], $overrides);
    }

    private function seedRow(array $overrides = [], bool $mgr = false, bool $case = false, bool $hq = false): void
    {
        $row = $this->row($overrides);
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $row['project'], 'staff_legacy_id' => $this->staffSeq++,
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'archived' => true,
            'is_manager_row' => $mgr, 'is_case_row' => $case, 'is_hq_row' => $hq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loadSheet(string $content, int $index = 0)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $content);
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        return [$book, $book->getSheet($index)];
    }

    private function exportProject(string $query = ''): string
    {
        $resp = $this->getJson('/api/export/project?ym=' . self::YM . '&project=' . urlencode('甲项目') . $query,
            ['X-Token' => $this->token]);
        $resp->assertOk();
        return $resp->streamedContent();
    }

    private function exportSummary(string $query = ''): string
    {
        $resp = $this->getJson('/api/export/summary?ym=' . self::YM . $query, ['X-Token' => $this->token]);
        $resp->assertOk();
        return $resp->streamedContent();
    }

    /** 纯数值版：现有 39 列与版式保持不变 */
    public function test_plain_mode_keeps_legacy_39_columns_without_notes_sheet(): void
    {
        $this->seedRow();
        [$book, $sheet] = $this->loadSheet($this->exportProject('&mode=plain'));

        $grid = $sheet->toArray(null, true, false, false);
        $headers = $grid[1];
        $this->assertCount(39, $headers);
        $this->assertNotContains('扣款合计', $headers, '纯数值版不应新增扣款合计列');
        $this->assertSame('应发工资合计', $headers[26]);
        $this->assertSame('实发工资', $headers[36]);
        $this->assertSame('季度绩效说明', $headers[38]);
        $this->assertSame(1, $book->getSheetCount(), '纯数值版单项目导出不应追加说明页');

        // 合计行仍为 A:K 合并，金额列为 SUM 公式
        $this->assertSame('合计（1人）', $sheet->getCell('A4')->getValue());
        $this->assertArrayHasKey('A4:K4', $sheet->getMergeCells());
        $this->assertSame('=SUM(AA3:AA3)', $sheet->getCell('AA4')->getValue());

        // 0 值留空：夜班/话费补贴、餐补为 0 → 空白单元格
        $this->assertNull($sheet->getCell('P3')->getValue(), '纯数值版 0 值应留空');
        $this->assertNull($sheet->getCell('Q3')->getValue(), '纯数值版 0 值应留空');
        // 第二行表头双色：奖励/补贴浅绿、扣罚/扣款浅红
        $this->assertSame('E2EFDA', $sheet->getStyle('P2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('F8D7DA', $sheet->getStyle('V2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('E2EFDA', $sheet->getStyle('AA2')->getFill()->getStartColor()->getRGB());
    }

    /** 两版列结构必须完全一致：39 列、列名与顺序逐列相同 */
    public function test_plain_and_formula_have_identical_39_column_headers(): void
    {
        $this->seedRow();
        $plain = $this->loadSheet($this->exportProject('&mode=plain'))[1]->toArray(null, true, false, false)[1];
        $formula = $this->loadSheet($this->exportProject('&mode=formula'))[1]->toArray(null, true, false, false)[1];

        $this->assertCount(39, $plain);
        $this->assertCount(39, $formula);
        $this->assertSame($plain, $formula, '带公式版与纯数值版列名、顺序必须逐列一致');
        $this->assertNotContains('扣款合计', $formula, '不得再出现扣款合计列');
    }

    /** 带公式版：39 列（与纯数值版一致）、三条派生列公式、缓存值与系统值一致 */
    public function test_formula_detail_has_formulas_and_cached_system_values(): void
    {
        $this->seedRow([
            'punish' => 10, 'late_d' => 5, 'gross' => 4985, 'actual_tax' => 30, 'net' => 3705,
            'remark' => '=1+1',
        ]);
        [$book, $sheet] = $this->loadSheet($this->exportProject('&mode=formula'));

        $headers = $sheet->toArray(null, true, false, false)[1];
        $this->assertCount(39, $headers);
        $this->assertSame('应发工资合计', $headers[26]);
        $this->assertSame('五险一金合计', $headers[32]);
        $this->assertSame('实发工资', $headers[36]);
        $this->assertSame('备注', $headers[37]);
        $this->assertSame('季度绩效说明', $headers[38]);
        $this->assertSame(2, $book->getSheetCount(), '公式版单项目导出应包含说明页');
        $this->assertSame('说明', $book->getSheet(1)->getTitle());

        // 三条派生列公式
        $this->assertSame('=ROUND(I3*K3/J3,2)', $sheet->getCell('M3')->getValue(), '应发基本工资应为公式');
        $this->assertSame('=ROUND((H3-I3)*L3*K3/J3,2)', $sheet->getCell('N3')->getValue(), '应发绩效工资应为公式');
        $this->assertNull($sheet->getCell('O3')->getValue(), '病假工资为 0 时应留空');

        // 三条汇总公式（扣款项已在应发公式中内联求和）
        $this->assertSame('=ROUND(SUM(M3:U3)-SUM(V3:Z3),2)', $sheet->getCell('AA3')->getValue());
        $this->assertSame('=ROUND(SUM(AB3:AF3),2)', $sheet->getCell('AG3')->getValue());
        $this->assertSame('=ROUND(AA3-AG3-AI3-U3+AJ3,2)', $sheet->getCell('AK3')->getValue());

        // 缓存值 = 系统值
        $this->assertEqualsWithDelta(4000.0, (float) $sheet->getCell('M3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $sheet->getCell('N3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(4985.0, (float) $sheet->getCell('AA3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1250.0, (float) $sheet->getCell('AG3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(3705.0, (float) $sheet->getCell('AK3')->getOldCalculatedValue(), 0.001);

        // 0 值留空与表头双色
        $this->assertNull($sheet->getCell('P3')->getValue(), '公式版 0 值也应留空');
        $this->assertSame('E2EFDA', $sheet->getStyle('P2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('F8D7DA', $sheet->getStyle('V2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('E2EFDA', $sheet->getStyle('AA2')->getFill()->getStartColor()->getRGB());

        // 合计行公式 + 缓存值
        $this->assertSame('=ROUND(SUM(AA3:AA3),2)', $sheet->getCell('AA4')->getValue());
        $this->assertEqualsWithDelta(4985.0, (float) $sheet->getCell('AA4')->getOldCalculatedValue(), 0.001);

        // 文本注入：=1+1 按文本存
        $this->assertSame('=1+1', $sheet->getCell('AL3')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('AL3')->getDataType());

        // 校验区：应发工资合计 差异为 0
        $this->assertSame('校验区（系统值 vs 公式值）', $sheet->getCell('A6')->getValue());
        $this->assertSame('应发工资合计', $sheet->getCell('A8')->getValue());
        $this->assertEqualsWithDelta(4985.0, (float) $sheet->getCell('B8')->getValue(), 0.001);
        $this->assertSame('=AA4', $sheet->getCell('C8')->getValue());
        $this->assertSame('=ROUND(B8-C8,2)', $sheet->getCell('D8')->getValue());
        $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell('D8')->getOldCalculatedValue(), 0.001);
        $this->assertSame('一致', $sheet->getCell('E8')->getOldCalculatedValue());
        $this->assertNotEmpty($sheet->getStyle('D8:D10')->getConditionalStyles(), '差异列应有非 0 红底加粗条件格式');
    }

    /** 三列派生公式（基本/绩效/病假）按核算规则生效，校验区差异为 0 */
    public function test_formula_base_perf_sick_follow_calc_rules(): void
    {
        $this->seedRow([
            'act_att' => 20, 'req_att' => 22, 'coef' => 0.8, 'perf_att' => 20,
            'base_pay' => 3636.36, 'perf_pay' => 727.27, 'sick_days' => 2, 'sick_pay' => 152.73,
            'gross' => 4516.36, 'soc_total' => 1250, 'actual_tax' => 0, 'net' => 3266.36,
        ]);
        [$book, $sheet] = $this->loadSheet($this->exportProject('&mode=formula'));

        $this->assertSame('=ROUND(I3*K3/J3,2)', $sheet->getCell('M3')->getValue());
        $this->assertSame('=ROUND((H3-I3)*L3*K3/J3,2)', $sheet->getCell('N3')->getValue());
        $this->assertSame('=ROUND(2*I3*0.7*0.6/J3,2)', $sheet->getCell('O3')->getValue());
        $this->assertEqualsWithDelta(3636.36, (float) $sheet->getCell('M3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(727.27, (float) $sheet->getCell('N3')->getOldCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(152.73, (float) $sheet->getCell('O3')->getOldCalculatedValue(), 0.001);

        // 0 值空白（夜班/餐补/本月个税均为 0），非零数值仍为数值类型
        $this->assertNull($sheet->getCell('P3')->getValue());
        $this->assertNull($sheet->getCell('Q3')->getValue());
        $this->assertNull($sheet->getCell('AI3')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('K3')->getDataType());

        // 校验区新增三列合计项，差异均为 0
        $this->assertSame('应发基本工资合计', $sheet->getCell('A11')->getValue());
        $this->assertSame('应发绩效工资合计', $sheet->getCell('A12')->getValue());
        $this->assertSame('病假工资合计', $sheet->getCell('A13')->getValue());
        $this->assertSame('=M4', $sheet->getCell('C11')->getValue());
        $this->assertSame('=N4', $sheet->getCell('C12')->getValue());
        $this->assertSame('=O4', $sheet->getCell('C13')->getValue());
        foreach ([11, 12, 13] as $rr) {
            $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell("D{$rr}")->getOldCalculatedValue(), 0.001);
            $this->assertSame('一致', $sheet->getCell("E{$rr}")->getOldCalculatedValue());
        }
        $this->assertNotEmpty($sheet->getStyle('D8:D13')->getConditionalStyles());
    }

    /** 整包公式版：Sheet 顺序、汇总跨表引用、说明页全表校验、打开时重算 */
    public function test_formula_summary_cross_sheet_refs_notes_and_recalc_flag(): void
    {
        $this->seedRow(['project' => '甲项目'], false, false, false);
        $this->seedRow(['project' => '甲项目', 'name' => '李四'], false, false, false);
        $this->seedRow(['project' => '乙项目', 'name' => '王五'], false, false, false);
        $this->seedRow(['project' => '甲项目', 'name' => '赵管理', 'gross' => 8000, 'net' => 6750], true, false, false);
        $this->seedRow(['project' => '甲项目', 'name' => '钱案场'], false, true, false);
        $this->seedRow(['project' => '物业公司总部', 'name' => '孙总部'], false, false, true);

        $content = $this->exportSummary('&mode=formula');
        [$book, $summary] = $this->loadSheet($content);

        $names = $book->getSheetNames();
        $this->assertSame('汇总', $names[0]);
        $this->assertSame('管理人员汇总', $names[1]);
        $this->assertSame('案场人员汇总', $names[2]);
        $this->assertSame('总部人员汇总', $names[3]);
        $this->assertContains('甲项目 2026-09工资表', $names);
        $this->assertContains('乙项目 2026-09工资表', $names);
        $this->assertContains('2026-09管理人员工资表', $names);
        $this->assertContains('2026-09案场人员工资表', $names);
        $this->assertContains('2026-09总部人员工资表', $names);
        $this->assertSame('说明', end($names));

        // 汇总项目行：基层应发引用项目明细页合计行（甲项目 2 人 → 合计行 5）
        $grid = $summary->toArray(null, true, false, false);
        $rowOf = [];
        foreach ($grid as $i => $row) {
            $a = trim((string) ($row[0] ?? ''));
            if (in_array($a, ['甲项目', '乙项目', '合计', '管理人员合计', '案场人员合计', '物业总部合计', '总计'], true)) {
                $rowOf[$a] = $i + 1;
            }
        }
        $rJia = $rowOf['甲项目'];
        $this->assertSame("='甲项目 2026-09工资表'!AA5", $summary->getCell("C{$rJia}")->getValue());
        $this->assertStringContainsString("COUNTA('甲项目 2026-09工资表'!E3:E4)", (string) $summary->getCell("B{$rJia}")->getValue());
        $this->assertStringContainsString("COUNTIF('2026-09管理人员工资表'!B3:B3", (string) $summary->getCell("D{$rJia}")->getValue());
        $this->assertStringContainsString("SUMIF('2026-09管理人员工资表'!", (string) $summary->getCell("E{$rJia}")->getValue());
        $this->assertSame("=B{$rJia}+D{$rJia}", $summary->getCell("F{$rJia}")->getValue());
        $this->assertSame("=ROUND(C{$rJia}+E{$rJia},2)", $summary->getCell("G{$rJia}")->getValue());

        $rGrand = $rowOf['总计'];
        $this->assertSame("=F{$rowOf['合计']}", $summary->getCell("B{$rGrand}")->getValue());
        $this->assertSame("=G{$rowOf['合计']}", $summary->getCell("C{$rGrand}")->getValue());
        $this->assertStringStartsWith('=ROUND(', (string) $summary->getCell("G{$rGrand}")->getValue());

        // 说明页全表校验
        $notes = $book->getSheetByName('说明');
        $this->assertNotNull($notes);
        $noteGrid = $notes->toArray(null, true, false, false);
        $sumRow = null; $grandRow = null; $diffRow = null;
        foreach ($noteGrid as $i => $row) {
            $a = trim((string) ($row[0] ?? ''));
            if ($a === '明细页应发合计之和') $sumRow = $i + 1;
            if ($a === '汇总页总计应发') $grandRow = $i + 1;
            if ($a === '差异') $diffRow = $i + 1;
        }
        $this->assertNotNull($sumRow);
        $this->assertStringContainsString('SUM(', (string) $notes->getCell("B{$sumRow}")->getValue());
        $this->assertSame("='汇总'!G{$rGrand}", $notes->getCell("B{$grandRow}")->getValue());
        $this->assertEqualsWithDelta(0.0, (float) $notes->getCell("B{$diffRow}")->getOldCalculatedValue(), 0.001);
        $this->assertSame('一致', $notes->getCell('B' . ($diffRow + 1))->getOldCalculatedValue());

        // 打开时重算标记
        $tmp = tempnam(sys_get_temp_dir(), 'zipxlsx') . '.xlsx';
        file_put_contents($tmp, $content);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('fullCalcOnLoad="1"', (string) $xml);

        // 辅助汇总页：逐项目 SUMIF
        $mgrSum = $book->getSheetByName('管理人员汇总');
        $this->assertStringContainsString("SUMIF('2026-09管理人员工资表'!", (string) $mgrSum->getCell('C3')->getValue());
    }

    /** 全部项目 zip：公式版每个文件都是 39 列且带公式 */
    public function test_projects_all_zip_formula_mode(): void
    {
        $this->seedRow(['project' => '甲项目'], false, false, false);
        $this->seedRow(['project' => '乙项目', 'name' => '李四'], false, false, false);

        $resp = $this->getJson('/api/export/projects_all?ym=' . self::YM . '&mode=formula', ['X-Token' => $this->token]);
        $resp->assertOk();
        $zipPath = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
        file_put_contents($zipPath, $resp->streamedContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(2, $zip->numFiles);
        $bytes = $zip->getFromIndex(0);
        $zip->close();
        @unlink($zipPath);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $sheet = IOFactory::load($tmp)->getSheet(0);
        @unlink($tmp);
        $this->assertSame('应发工资合计', $sheet->getCell('AA2')->getValue());
        $this->assertStringStartsWith('=ROUND(SUM(', (string) $sheet->getCell('AA3')->getValue());
    }

    /** 空值与半点金额不产生异常，校验区差异为 0 */
    public function test_formula_handles_empty_and_half_amounts(): void
    {
        $this->seedRow([
            'base_pay' => 0.5, 'perf_pay' => 0.0, 'gross' => 0.5, 'pen' => 0, 'med' => 0, 'une' => 0,
            'house' => 0, 'big' => 0, 'soc_total' => 0, 'actual_tax' => 0, 'net' => 0.5,
        ]);
        // 极简空值行：仅项目与姓名
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => '甲项目', 'staff_legacy_id' => $this->staffSeq++,
            'row_data' => json_encode(['project' => '甲项目', 'name' => '空值'], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        [$book, $sheet] = $this->loadSheet($this->exportProject('&mode=formula'));
        // 两行数据 → 合计行 5，校验区 应发工资合计 行 9
        $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell('D9')->getOldCalculatedValue(), 0.001);
        $this->assertSame('一致', $sheet->getCell('E9')->getOldCalculatedValue());
        $this->assertSame(2, $book->getSheetCount());
    }

    /** 逐行校验：不只比合计，按人逐列比对系统值与明细公式值，差异为 0 */
    public function test_formula_check_area_compares_each_person_row_by_row(): void
    {
        $this->seedRow(['name' => '张三']);
        $this->seedRow(['name' => '李四', 'fixed' => 6000, 'base' => 4500, 'base_pay' => 4500, 'perf_pay' => 1500,
            'gross' => 6000, 'pen' => 500, 'med' => 200, 'une' => 100, 'house' => 400, 'big' => 50,
            'soc_total' => 1250, 'actual_tax' => 0, 'net' => 4750]);

        [$book, $sheet] = $this->loadSheet($this->exportProject('&mode=formula'));

        $headRow = null;
        $grid = $sheet->toArray(null, true, false, false);
        foreach ($grid as $i => $row) {
            if (trim((string) ($row[0] ?? '')) === '逐行校验（系统值 - 本表公式值，逐人逐列）') {
                $headRow = $i + 2; // 标题下一行是表头
                break;
            }
        }
        $this->assertNotNull($headRow, '公式版必须包含逐行校验区');
        $this->assertSame('序号', $sheet->getCell("A{$headRow}")->getValue());
        $this->assertSame('姓名', $sheet->getCell("B{$headRow}")->getValue());
        $this->assertSame('应发基本工资差异', $sheet->getCell("C{$headRow}")->getValue());

        $first = $headRow + 1;
        $second = $headRow + 2;
        $this->assertSame(1, (int) $sheet->getCell("A{$first}")->getValue());
        $this->assertSame('张三', $sheet->getCell("B{$first}")->getValue());
        $this->assertSame(2, (int) $sheet->getCell("A{$second}")->getValue());
        $this->assertSame('李四', $sheet->getCell("B{$second}")->getValue());

        // 系统值 - 明细公式值；明细数据行 3、4
        $this->assertSame('=ROUND(4000-M3,2)', $sheet->getCell("C{$first}")->getValue());
        $this->assertSame('=ROUND(1000-N3,2)', $sheet->getCell("D{$first}")->getValue());
        $this->assertSame('=ROUND(4500-M4,2)', $sheet->getCell("C{$second}")->getValue());
        $this->assertSame('=ROUND(1500-N4,2)', $sheet->getCell("D{$second}")->getValue());
        $this->assertSame('=ROUND(6000-AA4,2)', $sheet->getCell("F{$second}")->getValue());

        foreach ([$first, $second] as $r) {
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $c) {
                $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell("{$c}{$r}")->getOldCalculatedValue(), 0.001,
                    "{$c}{$r} 逐行差异应为 0");
            }
            $this->assertSame('一致', $sheet->getCell("I{$r}")->getOldCalculatedValue());
        }
        $this->assertNotEmpty($sheet->getStyle("C{$first}:H{$second}")->getConditionalStyles(),
            '逐行差异列应有非 0 红底条件格式');
    }

    /** 说明页必须标注常量列，避免把「无法由本表列复现」的单元格误认为公式错误 */
    public function test_notes_sheet_documents_constant_columns_and_row_check(): void
    {
        $this->seedRow();
        [$book] = $this->loadSheet($this->exportProject('&mode=formula'));
        $notes = $book->getSheetByName('说明');
        $this->assertNotNull($notes);
        $text = '';
        foreach ($notes->toArray(null, true, false, false) as $row) {
            $text .= implode(' ', array_filter($row, fn ($v) => $v !== null && $v !== '')) . "\n";
        }
        $this->assertStringContainsString('常量列', $text);
        $this->assertStringContainsString('应发基本工资', $text);
        $this->assertStringContainsString('应发绩效工资', $text);
        $this->assertStringContainsString('病假工资', $text);
        $this->assertStringContainsString('逐行校验', $text);
    }
}
