<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 管理/总部工资表导出：季度绩效逐月明细列。
 * 表头在 35 个标准列之后追加 "Q1·1月绩效" / "H1·1月绩效" 列，数据行填金额，合计行求和。
 */
class ExportPerfDetailTest extends TestCase
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

    /** 造一行带 perf_detail 的管理人员核算结果 */
    private function seedMgrRow(int $staffId, string $ym, array $perfDetail): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => '测试项目',
            'row_data' => json_encode(array_merge([
                'project' => '测试项目', 'department' => '管理部', 'position' => '经理', 'name' => '张三',
                'status' => '正式', 'fixed' => 6000, 'base' => 5000, 'req_att' => 22, 'act_att' => 22,
                'coef' => 1, 'base_pay' => 5000, 'perf_pay' => 2850, 'sick_pay' => 0,
                'night' => 0, 'meal' => 0, 'title_sub' => 0, 'reward' => 0, 'welfare' => 0,
                'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
                'gross' => 7850, 'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
                'soc_total' => 0, 'spec_total' => 0, 'actual_tax' => 0, 'net' => 7850, 'remark' => '',
            ], ['perf_detail' => $perfDetail]), JSON_UNESCAPED_UNICODE),
            'archived' => false, 'is_manager_row' => true, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loadSheet(string $content)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $content);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        return $sheet;
    }

    public function test_managers_export_appends_quarterly_detail_columns(): void
    {
        $this->seedMgrRow(1, '2026-04', [
            'type' => 'quarterly', 'period' => '2026-Q1', 'ratio' => 0.95, 'coef' => 1.0,
            'total' => 3000, 'amount' => 2850,
            'months' => [
                ['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ['ym' => '2026-02', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ['ym' => '2026-03', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
            ],
        ]);
        $res = $this->getJson('/api/export/managers?ym=2026-04', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());
        // 35 个标准列之后应有 3 个明细列
        $this->assertEquals('Q1·1月绩效', $sheet->getCell('AJ2')->getValue(), 'AJ2 应为 Q1·1月绩效（第36列表头）');
        $this->assertEquals('Q1·2月绩效', $sheet->getCell('AK2')->getValue());
        $this->assertEquals('Q1·3月绩效', $sheet->getCell('AL2')->getValue());
        // 数据行（第3行）明细列金额
        $this->assertEquals(1000, (float) $sheet->getCell('AJ3')->getValue());
        $this->assertEquals(1000, (float) $sheet->getCell('AL3')->getValue());
        // 合计行（第4行）求和公式
        $this->assertEquals('=SUM(AJ3:AJ3)', $sheet->getCell('AJ4')->getValue());
    }

    public function test_managers_export_distinguishes_half_year_columns(): void
    {
        $this->seedMgrRow(1, '2026-07', [
            'type' => 'quarterly', 'period' => '2026-Q2', 'ratio' => 0.95, 'coef' => 1.0,
            'total' => 3000, 'amount' => 2850,
            'months' => [
                ['ym' => '2026-04', 'perf_att' => 22, 'base' => 1000, 'amount' => 950],
            ],
            'half_year' => [
                'type' => 'half_year', 'period' => '2026-H1', 'ratio' => 0.05, 'coef' => 1.0,
                'total' => 6000, 'amount' => 300,
                'months' => [
                    ['ym' => '2026-04', 'perf_att' => 22, 'base' => 1000, 'amount' => 50],
                ],
            ],
        ]);
        $res = $this->getJson('/api/export/managers?ym=2026-07', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());
        $this->assertEquals('Q2·4月绩效', $sheet->getCell('AJ2')->getValue());
        $this->assertEquals('H1·4月绩效', $sheet->getCell('AK2')->getValue(), '半年度明细列应与季度同月列区分');
        $this->assertEquals(950, (float) $sheet->getCell('AJ3')->getValue());
        $this->assertEquals(50, (float) $sheet->getCell('AK3')->getValue());
    }

    public function test_export_without_perf_detail_keeps_35_columns(): void
    {
        $this->seedMgrRow(1, '2026-03', []);
        // perf_detail 为空数组 → 不追加明细列
        DB::table('payroll_results')->where('staff_legacy_id', 1)
            ->update(['row_data' => json_encode(array_merge(
                json_decode(DB::table('payroll_results')->where('staff_legacy_id', 1)->value('row_data'), true),
                ['perf_detail' => null]
            ))]);
        $res = $this->getJson('/api/export/managers?ym=2026-03', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());
        $this->assertEquals('备注', $sheet->getCell('AI2')->getValue(), 'AI2 应为第35列备注');
        $this->assertNull($sheet->getCell('AJ2')->getValue(), '无 perf_detail 时不应有第36列');
    }
}
