<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 绩效工资专项表导出（GET /api/export/performance）：
 * Sheet1「当月绩效发放台账」每人一行（四类人员、月度/季度/半年度、异常显式标注）；
 * Sheet2「周期兑现逐月基数」展开季度/半年度兑现的逐月计提与系数/比例。
 * 取代旧实现"无 project 走 summary / 有 project 走 project"的工资表马甲。
 */
class ExportPerformanceSpecialTest extends TestCase
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

    private function seedRow(array $attr, array $flags = []): void
    {
        $row = array_merge([
            'project' => '测试项目', 'department' => '客服部', 'position' => '专员', 'name' => '李四',
            'status' => '正式', 'fixed' => 5000, 'base' => 4000, 'req_att' => 22, 'act_att' => 22,
            'perf_att' => 22, 'coef' => 1, 'base_pay' => 4000, 'perf_pay' => 500, 'sick_pay' => 0,
            'night' => 0, 'meal' => 0, 'title_sub' => 0, 'reward' => 0, 'welfare' => 0,
            'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
            'gross' => 4500, 'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
            'soc_total' => 0, 'spec_total' => 0, 'actual_tax' => 0, 'net' => 4500, 'remark' => '',
            'perf_detail' => null,
        ], $attr);
        DB::table('payroll_results')->insert([
            'year_month' => $row['ym'], 'staff_legacy_id' => $row['staff_id'],
            'project_name' => $row['project'],
            'row_data' => json_encode(array_diff_key($row, array_flip(['ym', 'staff_id'])), JSON_UNESCAPED_UNICODE),
            'archived' => $flags['archived'] ?? false,
            'is_manager_row' => $flags['manager'] ?? false,
            'is_case_row' => $flags['case'] ?? false,
            'is_hq_row' => $flags['hq'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loadBook(string $content): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $content);
        $book = IOFactory::load($tmp);
        unlink($tmp);
        return $book;
    }

    private function sheetGrid($sheet): array
    {
        // 第3参 false：返回单元格原始值（否则 #,##0.00 格式使 3000 变 "3,000.00" 被 floatval 截断）
        return $sheet->toArray(null, true, false, false);
    }

    private function findRow(array $grid, string $name): ?array
    {
        foreach ($grid as $r) {
            if (($r[4] ?? null) === $name) return $r;
        }
        return null;
    }

    public function test_sheet1_ledger_contains_monthly_and_quarter_rows(): void
    {
        // 基层员工：月度发放 coef=1 perf_pay=500
        $this->seedRow(['ym' => '2026-04', 'staff_id' => 1, 'name' => '李四', 'coef' => 1, 'perf_pay' => 500]);
        // 经理：季度兑现（Q1，季度系数 1，逐月计提 1000×3，兑现 3000）
        $this->seedRow([
            'ym' => '2026-04', 'staff_id' => 2, 'name' => '张三', 'department' => '管理部',
            'position' => '经理', 'perf_pay' => 3000, 'coef' => 0,
            'perf_detail' => [
                'period' => '2026-Q1', 'pay_grade' => '经理级', 'coef' => 1.0,
                'months' => [
                    ['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                    ['ym' => '2026-02', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                    ['ym' => '2026-03', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ],
            ],
        ], ['manager' => true]);

        $res = $this->getJson('/api/export/performance?ym=2026-04', ['X-Token' => $this->token]);
        $res->assertOk();
        $book = $this->loadBook($res->streamedContent());
        $this->assertSame('当月绩效发放台账', $book->getSheet(0)->getTitle());

        $grid = $this->sheetGrid($book->getSheet(0));
        $head = $grid[1];
        $this->assertSame('人员类别', $head[5]);
        $this->assertSame('薪酬档位', $head[6]);
        $this->assertSame('发放方式', $head[7]);
        $this->assertSame('绩效系数', $head[8]);
        $this->assertSame('计提基数', $head[9]);
        $this->assertSame('应发绩效工资', $head[10]);
        $this->assertSame('状态', $head[11]);

        $li = $this->findRow($grid, '李四');
        $this->assertNotNull($li);
        $this->assertSame('基层员工', $li[5]);
        $this->assertSame('月度发放', $li[7]);
        $this->assertEquals(1, (float) $li[8]);
        $this->assertEquals(500, (float) $li[9]);  // 反推基数 = 500/1
        $this->assertEquals(500, (float) $li[10]);
        $this->assertSame('正常', $li[11]);

        $zhang = $this->findRow($grid, '张三');
        $this->assertNotNull($zhang);
        $this->assertSame('管理人员', $zhang[5]);
        $this->assertSame('经理级', $zhang[6]);
        $this->assertSame('季度兑现', $zhang[7]);
        $this->assertEquals(1, (float) $zhang[8]);
        $this->assertEquals(3000, (float) $zhang[9]); // Σ 逐月计提
        $this->assertEquals(3000, (float) $zhang[10]);
        $this->assertSame('正常', $zhang[11]);
    }

    public function test_sheet2_expands_period_months_with_subtotal(): void
    {
        $this->seedRow([
            'ym' => '2026-04', 'staff_id' => 2, 'name' => '张三', 'perf_pay' => 2850,
            'perf_detail' => [
                'period' => '2026-Q1', 'pay_grade' => '经理级', 'ratio' => 0.95, 'coef' => 1.0,
                'months' => [
                    ['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 950],
                    ['ym' => '2026-02', 'perf_att' => 22, 'base' => 1000, 'amount' => 950],
                    ['ym' => '2026-03', 'perf_att' => 22, 'base' => 1000, 'amount' => 950],
                ],
            ],
        ], ['manager' => true]);

        $res = $this->getJson('/api/export/performance?ym=2026-04', ['X-Token' => $this->token]);
        $book = $this->loadBook($res->streamedContent());
        $this->assertCount(2, $book->getSheetNames(), '季度兑现存在时必须生成 Sheet2');
        $s2 = $book->getSheet(1);
        $this->assertSame('周期兑现逐月基数', $s2->getTitle());

        $grid = $this->sheetGrid($s2);
        $months = array_column(array_slice($grid, 2), 6); // 第7列=月份
        $this->assertContains('2026-01', $months);
        $this->assertContains('2026-02', $months);
        $this->assertContains('2026-03', $months);
        // 逐月金额
        $amounts = array_column(array_slice($grid, 2), 9); // 第10列=月计提额
        $this->assertEquals([950, 950, 950], array_map('floatval', array_slice($amounts, 0, 3)));
        // 比例列（第12列）= 0.95
        $this->assertEquals(0.95, (float) $grid[2][11]);
        // 小计行
        $flat = implode('|', array_map(fn ($r) => implode(',', array_map('strval', $r)), $grid));
        $this->assertStringContainsString('小计', $flat);
    }

    public function test_missing_coef_is_shown_explicitly_not_silent_zero(): void
    {
        $this->seedRow([
            'ym' => '2026-04', 'staff_id' => 3, 'name' => '王五', 'perf_pay' => 0,
            'perf_detail' => ['error' => 'missing_coef', 'period' => '2026-Q1', 'pay_grade' => '经理级'],
        ], ['manager' => true]);

        $res = $this->getJson('/api/export/performance?ym=2026-04', ['X-Token' => $this->token]);
        $book = $this->loadBook($res->streamedContent());
        $grid = $this->sheetGrid($book->getSheet(0));
        $wang = $this->findRow($grid, '王五');
        $this->assertNotNull($wang);
        $this->assertEquals(0, (float) $wang[10]);
        $this->assertStringContainsString('缺', $wang[11]);
        $this->assertStringContainsString('系数', $wang[11]);
        $this->assertSame(1, $book->getSheetCount(), '全部兑现失败时不生成 Sheet2');
    }

    public function test_quarter_plus_half_year_mode_and_sheet2_blocks(): void
    {
        $this->seedRow([
            'ym' => '2026-07', 'staff_id' => 4, 'name' => '赵六', 'perf_pay' => 3300,
            'perf_detail' => [
                'period' => '2026-Q2', 'pay_grade' => '经理级', 'ratio' => 0.95, 'coef' => 1.0,
                'months' => [['ym' => '2026-04', 'perf_att' => 22, 'base' => 1000, 'amount' => 3000]],
                'half_year' => [
                    'period' => '2026-H1', 'ratio' => 0.05, 'coef' => 1.0,
                    'months' => [
                        ['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 6000],
                    ],
                ],
            ],
        ], ['manager' => true]);

        $res = $this->getJson('/api/export/performance?ym=2026-07', ['X-Token' => $this->token]);
        $book = $this->loadBook($res->streamedContent());
        $grid = $this->sheetGrid($book->getSheet(0));
        $zhao = $this->findRow($grid, '赵六');
        $this->assertSame('季度兑现+半年度兑现', $zhao[7]);
        $this->assertEquals(3300, (float) $zhao[10]);

        $s2 = $this->sheetGrid($book->getSheet(1));
        $types = array_column(array_slice($s2, 2), 4); // 第5列=兑现类型
        $this->assertContains('季度', $types);
        $this->assertContains('半年度', $types);
    }

    public function test_monthly_coef_zero_leaves_base_blank(): void
    {
        $this->seedRow(['ym' => '2026-08', 'staff_id' => 5, 'name' => '孙七', 'coef' => 0, 'perf_pay' => 0]);
        $res = $this->getJson('/api/export/performance?ym=2026-08', ['X-Token' => $this->token]);
        $book = $this->loadBook($res->streamedContent());
        $sun = $this->findRow($this->sheetGrid($book->getSheet(0)), '孙七');
        $this->assertSame('月度发放', $sun[7]);
        $this->assertEmpty($sun[9], '系数为 0 时反推基数无意义，须留空不得伪造成数值');
        $this->assertSame('本月绩效为0', $sun[11]);
    }

    public function test_admin_project_filter_only_returns_that_project(): void
    {
        $this->seedRow(['ym' => '2026-04', 'staff_id' => 1, 'name' => '李四', 'project' => '甲项目']);
        $this->seedRow(['ym' => '2026-04', 'staff_id' => 6, 'name' => '周八', 'project' => '乙项目']);
        $res = $this->getJson('/api/export/performance?ym=2026-04&project=' . urlencode('甲项目'),
            ['X-Token' => $this->token]);
        $book = $this->loadBook($res->streamedContent());
        $grid = $this->sheetGrid($book->getSheet(0));
        $this->assertNotNull($this->findRow($grid, '李四'));
        $this->assertNull($this->findRow($grid, '周八'));
    }

    public function test_project_account_isolation_and_archive_gate(): void
    {
        // 项目账号（isProjectScope 依赖 payroll_roles.scope）
        DB::table('payroll_roles')->insert([
            'role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
            'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj', 'name' => '项目会计',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ptok = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $ptok, $pid, now()->addHours(8));

        $this->seedRow(['ym' => '2026-04', 'staff_id' => 1, 'name' => '本项目员工', 'project' => '甲项目']);
        $this->seedRow(['ym' => '2026-04', 'staff_id' => 6, 'name' => '外项目员工', 'project' => '乙项目']);
        $this->seedRow(['ym' => '2026-04', 'staff_id' => 2, 'name' => '某经理', 'project' => '甲项目',
            'perf_pay' => 3000,
            'perf_detail' => ['period' => '2026-Q1', 'pay_grade' => '经理级', 'coef' => 1.0,
                'months' => [['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000]]]],
            ['manager' => true]);

        // 未归档 → 403
        $this->getJson('/api/export/performance?ym=2026-04', ['X-Token' => $ptok])->assertStatus(403);

        DB::table('payroll_results')->where('year_month', '2026-04')->where('project_name', '甲项目')
            ->update(['archived' => true]);

        $res = $this->getJson('/api/export/performance?ym=2026-04', ['X-Token' => $ptok]);
        $res->assertOk();
        $grid = $this->sheetGrid($this->loadBook($res->streamedContent())->getSheet(0));
        $this->assertNotNull($this->findRow($grid, '本项目员工'));
        $this->assertNull($this->findRow($grid, '外项目员工'), '项目账号不可见外项目');
        $this->assertNull($this->findRow($grid, '某经理'), '项目账号不可见管理人员行');
        // 项目账号即使传 project 也无法越权
        $this->getJson('/api/export/performance?ym=2026-04&project=' . urlencode('乙项目'),
            ['X-Token' => $ptok])->assertOk();
    }

    public function test_no_data_returns_404(): void
    {
        $this->getJson('/api/export/performance?ym=2026-09', ['X-Token' => $this->token])
            ->assertStatus(404);
    }

    public function test_invalid_month_returns_400(): void
    {
        $this->getJson('/api/export/performance?ym=2026-13', ['X-Token' => $this->token])
            ->assertStatus(400);
    }
}
