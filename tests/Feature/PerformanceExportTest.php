<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * 绩效导出独立模块（/api/export/performance-detail[/preview]）：
 * 多人类别 Sheet、管理人员跟随拆分、汇总小计、发放法覆盖、权限与项目隔离。
 */
class PerformanceExportTest extends TestCase
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
        $this->seedRules();
    }

    private function seedRules(): void
    {
        $rules = [
            'performance' => ['enabled' => true, 'probation_excluded' => true, 'quarter_sum' => true],
            'pay_rules' => [
                'staff' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'case' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'manager' => ['cycle' => 'quarter_grade', 'levels' => [
                    '经理级' => ['mode' => 'quarter'],
                    '主管级' => ['mode' => 'monthly'],
                    '专员级' => ['mode' => 'monthly'],
                ]],
                'hq' => ['cycle' => 'quarter_grade', 'levels' => [
                    '经理级' => ['mode' => 'quarter'],
                    '主管级' => ['mode' => 'quarter'],
                    '专员级' => ['mode' => 'quarter'],
                ]],
            ],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function seedStaff(int $id, string $name, string $project, string $grade, string $regular = '2025-01-01'): void
    {
        DB::table('payroll_staff')->updateOrInsert(
            ['legacy_id' => $id],
            [
                'name' => $name, 'project_name' => $project, 'position' => '岗位',
                'status' => '正式', 'fixed_monthly' => 0, 'base_salary' => 0,
                'hire_date' => '2024-01-01', 'regular_date' => $regular, 'resign_date' => null,
                'deleted' => false, 'person_type' => 'staff',
                'data' => json_encode(['pay_grade' => $grade, 'regular_date' => $regular], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    private function seedRow(array $attr, array $flags = [], bool $archived = false): void
    {
        $row = array_merge([
            'project' => '甲项目', 'department' => '客服部', 'position' => '专员', 'name' => '李四',
            'status' => '正式', 'fixed' => 5000, 'base' => 4000, 'req_att' => 22, 'act_att' => 22,
            'perf_att' => 22, 'coef' => 1, 'base_pay' => 4000, 'perf_pay' => 500, 'sick_pay' => 0,
            'night' => 0, 'meal' => 0, 'title_sub' => 0, 'reward' => 0, 'welfare' => 0,
            'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
            'gross' => 4500, 'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
            'soc_total' => 0, 'spec_total' => 0, 'actual_tax' => 0, 'net' => 4500, 'remark' => '',
            'hire_date' => '2024-01-01', 'perf_detail' => null,
        ], $attr);
        DB::table('payroll_results')->insert([
            'year_month' => $row['ym'], 'staff_legacy_id' => $row['staff_id'],
            'project_name' => $row['project'],
            'row_data' => json_encode(array_diff_key($row, array_flip(['ym', 'staff_id'])), JSON_UNESCAPED_UNICODE),
            'archived' => $archived,
            'is_manager_row' => $flags['manager'] ?? false,
            'is_case_row' => $flags['case'] ?? false,
            'is_hq_row' => $flags['hq'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function quarter(array $override = []): array
    {
        return array_merge([
            'period' => '2026-Q3',
            'months' => [
                ['ym' => '2026-07', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
                ['ym' => '2026-08', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
                ['ym' => '2026-09', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
            ],
            'base' => 3000, 'att' => 66, 'req' => 66, 'ratio' => 1,
            'coef' => 0.7, 'amount' => 2100, 'pay_grade' => '经理级',
        ], $override);
    }

    private function loadBook(string $content): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'perf_xlsx_') . '.xlsx';
        file_put_contents($tmp, $content);
        $book = IOFactory::load($tmp);
        unlink($tmp);
        return $book;
    }

    private function grid($sheet): array
    {
        return $sheet->toArray(null, true, false, false);
    }

    private function findRow(array $grid, string $name): ?array
    {
        foreach ($grid as $row) {
            if (($row[3] ?? null) === $name) return $row;
        }
        return null;
    }

    private function sheetByName($book, string $title)
    {
        foreach ($book->getSheetNames() as $i => $name) {
            if ($name === $title) return $book->getSheet($i);
        }
        return null;
    }

    private function seedFollowSet(): void
    {
        $this->seedStaff(1, '李四', '甲项目', '专员级');
        $this->seedStaff(2, '张三', '甲项目', '经理级');
        $this->seedStaff(3, '王五', '乙项目', '主管级');
        $this->seedStaff(4, '赵六', '甲项目', '专员级');
        $this->seedStaff(5, '王玉栋', '物业公司总部', '经理级');

        // 基层员工：月度
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '李四', 'perf_pay' => 500]);
        // 管理人员：经理级=季度
        $this->seedRow([
            'ym' => '2026-10', 'staff_id' => 2, 'name' => '张三', 'department' => '管理部',
            'position' => '经理', 'fixed' => 6000, 'base' => 5000, 'perf_pay' => 2100,
            'perf_quarter' => $this->quarter(),
        ], ['manager' => true]);
        // 管理人员：主管级=月度
        $this->seedRow([
            'ym' => '2026-10', 'staff_id' => 3, 'name' => '王五', 'project' => '乙项目',
            'department' => '管理部', 'position' => '主管', 'fixed' => 6000, 'base' => 5000,
            'coef' => 1, 'perf_pay' => 1000,
        ], ['manager' => true]);
        // 案场人员：月度
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 4, 'name' => '赵六', 'perf_pay' => 400], ['case' => true]);
        // 总部人员：经理级=季度
        $this->seedRow([
            'ym' => '2026-10', 'staff_id' => 5, 'name' => '王玉栋', 'project' => '物业公司总部',
            'department' => '总部', 'position' => '总经理', 'fixed' => 30833.33, 'base' => 21583.33,
            'perf_pay' => 19425,
            'perf_quarter' => $this->quarter([
                'base' => 27750, 'att' => 83, 'req' => 83, 'coef' => 0.7, 'amount' => 19425,
                'months' => [
                    ['ym' => '2026-07', 'base' => 9250, 'perf_att' => 27, 'perf_req' => 27, 'amount' => 9250],
                    ['ym' => '2026-08', 'base' => 9250, 'perf_att' => 28, 'perf_req' => 28, 'amount' => 9250],
                    ['ym' => '2026-09', 'base' => 9250, 'perf_att' => 28, 'perf_req' => 28, 'amount' => 9250],
                ],
            ]),
        ], ['hq' => true]);
    }

    private function getXlsx(string $query): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->getJson('/api/export/performance-detail?' . $query, ['X-Token' => $this->token]);
        $res->assertOk();
        return $this->loadBook($res->streamedContent());
    }

    // ---------------- 用例 ----------------

    public function test_follow_splits_manager_into_quarter_and_monthly_sheets(): void
    {
        $this->seedFollowSet();
        $book = $this->getXlsx('ym=2026-10&cats=staff,manager,case,hq&method=follow');

        $this->assertSame(
            ['基层员工', '管理人员-季度', '管理人员-月度', '案场人员', '总部人员', '汇总', '说明'],
            $book->getSheetNames()
        );

        $mgrQuarter = $this->sheetByName($book, '管理人员-季度');
        $this->assertNotNull($mgrQuarter);
        $this->assertSame('季度系数', $mgrQuarter->getCell('Q2')->getValue());
        $this->assertSame('应发绩效工资', $mgrQuarter->getCell('S2')->getValue());

        $mgrMonthly = $this->sheetByName($book, '管理人员-月度');
        $this->assertNotNull($mgrMonthly);
        $this->assertSame('月系数', $mgrMonthly->getCell('I2')->getValue());
        $this->assertSame('应发绩效工资（月）', $mgrMonthly->getCell('N2')->getValue());
    }

    public function test_quarter_columns_and_amounts(): void
    {
        $this->seedFollowSet();
        $book = $this->getXlsx('ym=2026-10&cats=manager&method=follow');
        $sheet = $book->getSheet(0);
        $this->assertSame('管理人员-季度', $sheet->getTitle());

        $grid = $this->grid($sheet);
        $head = $grid[1];
        $this->assertSame(['序号', '项目', '部门', '姓名', '岗位', '薪酬档位', '固定月薪',
            '7月应出勤天数', '7月转正出勤天数', '8月应出勤天数', '8月转正出勤天数',
            '9月应出勤天数', '9月转正出勤天数',
            '季度合计应出勤天数', '季度合计转正出勤天数', '出勤系数', '季度系数', '标准绩效工资', '应发绩效工资',
            '入职日期', '转正日期', '备注'], $head);

        $zhang = $this->findRow($grid, '张三');
        $this->assertNotNull($zhang);
        $this->assertEquals(6000, (float) $zhang[6]);
        $this->assertEquals(22, (float) $zhang[7]);
        $this->assertEquals(22, (float) $zhang[8]);
        $this->assertEquals(66, (float) $zhang[13]);
        $this->assertEquals(66, (float) $zhang[14]);
        $this->assertEquals(1, (float) $zhang[15]);
        $this->assertEquals(0.7, (float) $zhang[16]);
        $this->assertEquals(3000, (float) $zhang[17]);
        $this->assertEquals(2100, (float) $zhang[18]);
    }

    public function test_summary_has_project_and_category_subtotals(): void
    {
        $this->seedStaff(1, '李四', '甲项目', '专员级');
        $this->seedStaff(2, '周八', '乙项目', '专员级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '李四', 'perf_base_month' => 3000, 'perf_pay' => 500]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '周八', 'project' => '乙项目',
            'fixed' => 4000, 'base' => 2000, 'perf_base_month' => 2000, 'perf_pay' => 400]);

        $book = $this->getXlsx('ym=2026-10&cats=staff&method=follow');
        $summary = $this->sheetByName($book, '汇总');
        $this->assertNotNull($summary);
        $grid = $this->grid($summary);

        $jia = null; $yi = null; $cat = null; $total = null;
        foreach ($grid as $row) {
            if (($row[1] ?? '') === '甲项目') $jia = $row;
            if (($row[1] ?? '') === '乙项目') $yi = $row;
            if (($row[1] ?? '') === '类别小计') $cat = $row;
            if (($row[1] ?? '') === '总计') $total = $row;
        }
        $this->assertNotNull($jia);
        $this->assertNotNull($yi);
        $this->assertEquals(1, (float) $jia[3]);
        $this->assertEquals(500, (float) $jia[6]);
        $this->assertEquals(400, (float) $yi[6]);
        $this->assertNotNull($cat);
        $this->assertEquals(2, (float) $cat[3]);
        $this->assertEquals(900, (float) $cat[6]);
        $this->assertNotNull($total);
        $this->assertEquals(2, (float) $total[3]);
        $this->assertEquals(900, (float) $total[6]);
    }

    public function test_zero_and_one_coef_are_valid_and_missing_coef_is_explicit(): void
    {
        $this->seedStaff(1, '系数零', '甲项目', '经理级');
        $this->seedStaff(2, '系数一', '甲项目', '经理级');
        $this->seedStaff(3, '缺系数', '甲项目', '经理级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '系数零', 'perf_pay' => 0,
            'perf_quarter' => $this->quarter(['coef' => 0.0, 'amount' => 0.0])], ['manager' => true]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '系数一', 'perf_pay' => 3000,
            'perf_quarter' => $this->quarter(['coef' => 1.0, 'amount' => 3000])], ['manager' => true]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 3, 'name' => '缺系数', 'perf_pay' => 0,
            'perf_quarter' => ['error' => 'missing_coef', 'period' => '2026-Q3', 'pay_grade' => '经理级']],
            ['manager' => true]);

        $book = $this->getXlsx('ym=2026-10&cats=manager&method=follow');
        $grid = $this->grid($book->getSheet(0));

        $zero = $this->findRow($grid, '系数零');
        $this->assertEquals(0, (float) $zero[16]);
        $this->assertEquals(3000, (float) $zero[17]);
        $this->assertEquals(0, (float) $zero[18]);
        $this->assertSame('', (string) $zero[21], '系数 0 是合法值，不得标异常');

        $one = $this->findRow($grid, '系数一');
        $this->assertEquals(1, (float) $one[16]);
        $this->assertEquals(3000, (float) $one[18]);

        $missing = $this->findRow($grid, '缺系数');
        $this->assertStringContainsString('缺周期系数', (string) $missing[21]);
    }

    public function test_attendance_ratio_over_one_is_noted_and_previewed(): void
    {
        $this->seedStaff(1, '刘伟', '甲项目', '经理级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '刘伟', 'perf_pay' => 0,
            'perf_quarter' => $this->quarter([
                'base' => 3000, 'att' => 80, 'req' => 72, 'coef' => 0.7, 'amount' => 0.0,
            ])], ['manager' => true]);

        $book = $this->getXlsx('ym=2026-10&cats=manager&method=follow');
        $grid = $this->grid($book->getSheet(0));
        $liu = $this->findRow($grid, '刘伟');
        $this->assertStringContainsString('1.11 > 1.00', (string) $liu[21]);

        $res = $this->getJson('/api/export/performance-detail/preview?ym=2026-10&cats=manager&method=follow',
            ['X-Token' => $this->token]);
        $res->assertOk();
        $this->assertSame('刘伟', $res->json('ratio_over_one.0.name'));
        $this->assertEquals(1.11, (float) $res->json('ratio_over_one.0.ratio'));
    }

    // 口径确认：统一季度视图直接剔除月度绩效法人员（不进表、不进汇总、不报异常）
    public function test_unified_quarter_excludes_monthly_staff(): void
    {
        $this->seedStaff(1, '李四', '甲项目', '专员级');
        $this->seedStaff(2, '张三', '甲项目', '经理级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '李四', 'coef' => 1, 'perf_pay' => 500]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '张三', 'department' => '管理部',
            'perf_quarter' => $this->quarter()], ['manager' => true]);

        $res = $this->getJson('/api/export/performance-detail/preview?ym=2026-10&cats=staff,manager&method=quarter',
            ['X-Token' => $this->token]);
        $res->assertOk();
        $texts = implode('｜', array_column($res->json('warnings'), 'text'));
        $this->assertStringContainsString('已按发放法剔除 1 人', $texts);
        $this->assertStringNotContainsString('绩效数据异常', $texts);
        // 只保留季度型人员：基层整类被剔除后不产出 Sheet
        $this->assertCount(1, $res->json('sheets'));
        $this->assertSame('管理人员', $res->json('sheets.0.title'));
        $this->assertSame(1, $res->json('sheets.0.count'));
        $this->assertSame('张三', $res->json('sheets.0.sample.0.3'));

        $res2 = $this->getJson('/api/export/performance-detail/preview?ym=2026-10&cats=staff&method=quarter',
            ['X-Token' => $this->token]);
        $res2->assertOk();
        $this->assertSame([], $res2->json('sheets'));
    }

    // 对称：统一月度视图剔除季度绩效法人员
    public function test_unified_monthly_excludes_quarter_staff(): void
    {
        $this->seedStaff(2, '张三', '甲项目', '经理级');
        $this->seedStaff(3, '王五', '甲项目', '主管级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '张三', 'department' => '管理部',
            'perf_quarter' => $this->quarter()], ['manager' => true]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 3, 'name' => '王五', 'department' => '管理部',
            'coef' => 1, 'perf_pay' => 1000], ['manager' => true]);

        $res = $this->getJson('/api/export/performance-detail/preview?ym=2026-10&cats=manager&method=monthly',
            ['X-Token' => $this->token]);
        $res->assertOk();
        $texts = implode('｜', array_column($res->json('warnings'), 'text'));
        $this->assertStringContainsString('已按发放法剔除 1 人', $texts);
        $this->assertStringContainsString('季度绩效法', $texts);
        $this->assertSame(1, $res->json('sheets.0.count'));
        $this->assertSame('王五', $res->json('sheets.0.sample.0.3'));
    }

    // 问题2：历史季度数据（perf_detail 无 perf_req）应回填逐月应出勤，且实发额取工资行 perf_pay
    public function test_legacy_quarter_detail_backfills_attendance_days(): void
    {
        $this->seedStaff(1, '张三', '甲项目', '经理级');
        foreach ([['2026-07', 23], ['2026-08', 21], ['2026-09', 22]] as [$m, $req]) {
            $this->seedRow(['ym' => $m, 'staff_id' => 1, 'name' => '张三', 'req_att' => $req,
                'perf_att' => $req, 'perf_pay' => 0], ['manager' => true]);
        }
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '张三', 'perf_pay' => 450,
            'perf_detail' => [
                'period' => '2026-Q3', 'type' => 'quarterly', 'pay_grade' => '经理级', 'coef' => 0.3,
                'months' => [
                    ['ym' => '2026-07', 'perf_att' => 23, 'base' => 500, 'amount' => 500],
                    ['ym' => '2026-08', 'perf_att' => 21, 'base' => 500, 'amount' => 500],
                    ['ym' => '2026-09', 'perf_att' => 22, 'base' => 500, 'amount' => 500],
                ],
            ]], ['manager' => true]);

        $book = $this->getXlsx('ym=2026-10&cats=manager&method=quarter');
        $grid = $this->grid($book->getSheet(0));
        $zhang = $this->findRow($grid, '张三');
        $this->assertNotNull($zhang);
        $this->assertEquals(23, (float) $zhang[7]);    // 7月应出勤天数
        $this->assertEquals(21, (float) $zhang[9]);    // 8月应出勤天数
        $this->assertEquals(22, (float) $zhang[11]);   // 9月应出勤天数
        $this->assertEquals(66, (float) $zhang[13]);   // 季度合计应出勤天数
        $this->assertEquals(1, (float) $zhang[15]);    // 出勤系数
        $this->assertEquals(450, (float) $zhang[18]);  // 应发绩效工资取工资行 perf_pay
    }

    public function test_unified_monthly_columns_and_amounts(): void
    {
        $this->seedStaff(3, '王五', '甲项目', '主管级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 3, 'name' => '王五', 'department' => '管理部',
            'fixed' => 6000, 'base' => 5000, 'coef' => 0.7, 'perf_pay' => 2100,
            'perf_att' => 20, 'perf_req' => 22, 'perf_base_month' => 3000], ['manager' => true]);

        $book = $this->getXlsx('ym=2026-10&cats=manager&method=monthly');
        $sheet = $book->getSheet(0);
        $this->assertSame('管理人员', $sheet->getTitle());
        $grid = $this->grid($sheet);
        $this->assertContains('出勤比例', $grid[1]);
        $this->assertNotContains('季度系数', $grid[1]);
        $this->assertSame('应出勤天数', $grid[1][9]);
        $this->assertSame('绩效出勤天数', $grid[1][10]);

        $wang = $this->findRow($grid, '王五');
        $this->assertEquals(3000, (float) $wang[12]);          // 标准（月）
        $this->assertEquals(2100, (float) $wang[13]);          // 月度实发
        $this->assertStringNotContainsString('折算', (string) $wang[16]);
    }

    public function test_unified_installment_columns_and_amounts(): void
    {
        $this->seedStaff(1, '赵六', '甲项目', '经理级');
        $this->seedStaff(2, '钱七', '甲项目', '经理级');
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '赵六', 'perf_pay' => 3240,
            'perf_detail' => [
                'period' => '2026-Q3', 'pay_grade' => '经理级', 'ratio' => 0.8, 'coef' => 0.9,
                'months' => [
                    ['ym' => '2026-07', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                    ['ym' => '2026-08', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                    ['ym' => '2026-09', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ],
                'half_year' => [
                    'period' => '2026-H1', 'ratio' => 0.2, 'coef' => 0.9,
                    'months' => [['ym' => '2026-01', 'perf_att' => 22, 'base' => 6000, 'amount' => 6000]],
                ],
            ]], ['manager' => true]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '钱七', 'perf_pay' => 1000,
            'perf_quarter' => $this->quarter()], ['manager' => true]);

        $book = $this->getXlsx('ym=2026-10&cats=manager&method=installment');
        $sheet = $book->getSheet(0);
        $this->assertSame('职级季度比例', $sheet->getCell('R2')->getValue());
        $this->assertSame('本期应发', $sheet->getCell('X2')->getValue());

        $grid = $this->grid($sheet);
        $zhao = $this->findRow($grid, '赵六');
        $this->assertEquals(0.8, (float) $zhao[17]);
        $this->assertEquals(3000, (float) $zhao[18]);
        $this->assertEquals(2160, (float) $zhao[19]);
        $this->assertEquals(0.9, (float) $zhao[20]);
        $this->assertEquals(0.2, (float) $zhao[21]);
        $this->assertEquals(1080, (float) $zhao[22]);
        $this->assertEquals(3240, (float) $zhao[23]);

        $qian = $this->findRow($grid, '钱七');
        $this->assertSame('—', $qian[23]);
        $this->assertStringContainsString('非分期兑现', (string) $qian[26]);
    }

    public function test_export_modes_book_summary_and_split(): void
    {
        $this->seedFollowSet();

        $book = $this->getXlsx('ym=2026-10&cats=staff,manager&method=follow');
        $this->assertContains('汇总', $book->getSheetNames());
        $this->assertContains('说明', $book->getSheetNames());

        $summary = $this->getXlsx('ym=2026-10&cats=staff,manager&method=follow&mode=summary');
        $this->assertSame(['汇总'], $summary->getSheetNames());

        $res = $this->getJson('/api/export/performance-detail?ym=2026-10&cats=staff,manager&method=follow&mode=split',
            ['X-Token' => $this->token]);
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'perf_zip_') . '.zip';
        file_put_contents($tmp, $res->streamedContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertGreaterThanOrEqual(3, $zip->numFiles);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $staffXlsx = $zip->getFromName('基层员工_2026-10.xlsx');
        $zip->close();
        unlink($tmp);
        $this->assertContains('基层员工_2026-10.xlsx', $names);
        $this->assertContains('管理人员-季度_2026-10.xlsx', $names);
        $this->assertNotFalse($staffXlsx);
        $this->assertSame('基层员工', $this->loadBook($staffXlsx)->getSheet(0)->getTitle());
    }

    public function test_permission_401_and_perf_export_gate(): void
    {
        $this->seedFollowSet();

        $this->getJson('/api/export/performance-detail/preview?ym=2026-10')
            ->assertStatus(401);

        DB::table('payroll_roles')->insert([
            'role_key' => 'viewer_hr', 'name' => '人力查看', 'scope' => 'all',
            'permissions' => json_encode([]), 'data' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'viewer', 'name' => '查看员',
            'role' => 'viewer_hr', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = str_repeat('v', 64);
        Cache::put('payroll_api_token:' . $token, $id, now()->addHours(8));

        $this->getJson('/api/export/performance-detail/preview?ym=2026-10', ['X-Token' => $token])
            ->assertStatus(403);

        DB::table('payroll_roles')->where('role_key', 'viewer_hr')
            ->update(['permissions' => json_encode(['perf_export'])]);
        $this->getJson('/api/export/performance-detail/preview?ym=2026-10', ['X-Token' => $token])
            ->assertOk();
    }

    public function test_project_scope_requires_archive_and_hides_manager_hq(): void
    {
        $this->seedStaff(1, '本项目员工', '甲项目', '专员级');
        $this->seedStaff(2, '外项目员工', '乙项目', '专员级');
        $this->seedStaff(3, '本项目案场', '甲项目', '专员级');
        $this->seedStaff(4, '某经理', '甲项目', '经理级');

        $this->seedRow(['ym' => '2026-10', 'staff_id' => 1, 'name' => '本项目员工']);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 2, 'name' => '外项目员工', 'project' => '乙项目']);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 3, 'name' => '本项目案场'], ['case' => true]);
        $this->seedRow(['ym' => '2026-10', 'staff_id' => 4, 'name' => '某经理', 'perf_pay' => 2100,
            'perf_quarter' => $this->quarter()], ['manager' => true]);

        DB::table('payroll_roles')->insert([
            'role_key' => 'project_hr', 'name' => '项目人力', 'scope' => 'project',
            'permissions' => json_encode(['perf_export']), 'data' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9003, 'username' => 'proj', 'name' => '项目会计',
            'role' => 'project_hr', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $token, $id, now()->addHours(8));

        $this->getJson('/api/export/performance-detail?ym=2026-10', ['X-Token' => $token])
            ->assertStatus(403);

        DB::table('payroll_results')->where('year_month', '2026-10')->where('project_name', '甲项目')
            ->update(['archived' => true]);

        $res = $this->getJson('/api/export/performance-detail?ym=2026-10&project=' . urlencode('乙项目'),
            ['X-Token' => $token]);
        $res->assertOk();
        $book = $this->loadBook($res->streamedContent());
        $this->assertSame(['基层员工', '案场人员', '汇总', '说明'], $book->getSheetNames());

        $grid = $this->grid($book->getSheet(0));
        $this->assertNotNull($this->findRow($grid, '本项目员工'));
        $this->assertNull($this->findRow($grid, '外项目员工'));
        $this->assertNull($this->findRow($grid, '某经理'));
    }

    public function test_non_quarter_end_guards(): void
    {
        $this->seedStaff(1, '李四', '甲项目', '专员级');
        $this->seedRow(['ym' => '2026-09', 'staff_id' => 1, 'name' => '李四']);

        $this->getJson('/api/export/performance-detail?ym=2026-09&cats=manager&method=quarter',
            ['X-Token' => $this->token])->assertStatus(422);
        $this->getJson('/api/export/performance-detail?ym=2026-09&cats=manager&method=follow',
            ['X-Token' => $this->token])->assertStatus(422);

        $res = $this->getJson('/api/export/performance-detail?ym=2026-09&cats=staff&method=follow',
            ['X-Token' => $this->token]);
        $res->assertOk();
        $this->assertSame(['基层员工', '汇总', '说明'], $this->loadBook($res->streamedContent())->getSheetNames());
    }

    public function test_no_data_and_invalid_month(): void
    {
        $this->getJson('/api/export/performance-detail?ym=2026-11&cats=staff&method=monthly',
            ['X-Token' => $this->token])->assertStatus(404);
        $this->getJson('/api/export/performance-detail?ym=2026-13&cats=staff',
            ['X-Token' => $this->token])->assertStatus(400);
    }
}
