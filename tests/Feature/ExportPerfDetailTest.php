<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 管理/总部工资表导出：季度绩效不再逐月展开，固定 38 列 + 第 39 列「季度绩效说明」。
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

    /** 造一行管理人员核算结果（可带 perf_quarter / perf_detail） */
    private function seedMgrRow(int $staffId, string $ym, array $extra): void
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
                'perf_detail' => null,
            ], $extra), JSON_UNESCAPED_UNICODE),
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

    public function test_manager_export_has_fixed_39_columns_and_quarter_note(): void
    {
        $this->seedMgrRow(1, '2026-04', ['perf_quarter' => [
            'period' => '2026-Q1', 'months' => [
                ['ym' => '2026-01', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
                ['ym' => '2026-02', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
                ['ym' => '2026-03', 'base' => 1000, 'perf_att' => 22, 'perf_req' => 22, 'amount' => 1000],
            ],
            'base' => 3000, 'att' => 66, 'req' => 66, 'ratio' => 1, 'coef' => 1.0, 'amount' => 3000,
        ]]);
        $res = $this->getJson('/api/export/managers?ym=2026-04', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());

        // 固定 39 列：AL=备注（38），AM=季度绩效说明（39）
        $this->assertSame('备注', $sheet->getCell('AL2')->getValue());
        $this->assertSame('季度绩效说明', $sheet->getCell('AM2')->getValue());
        $this->assertSame('AM', $sheet->getHighestColumn(), '不得再追加逐月明细列');
        // 说明文本：季末月写季度口径
        $this->assertSame('2026Q1｜1-3月｜出勤66/66｜系数1.0', $sheet->getCell('AM3')->getValue());
        // 主表第 14 列（N）仍是季度绩效总额
        $this->assertEquals(2850, (float) $sheet->getCell('N3')->getValue());
    }

    public function test_non_quarter_row_note_blank_and_note_not_money_formatted(): void
    {
        $this->seedMgrRow(1, '2026-03', ['perf_detail' => null]);
        $res = $this->getJson('/api/export/managers?ym=2026-03', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());
        $this->assertSame('季度绩效说明', $sheet->getCell('AM2')->getValue());
        $this->assertNull($sheet->getCell('AM3')->getValue(), '非季末月/非季度绩效法人员留空');
        $this->assertSame('General', $sheet->getStyle('AM3')->getNumberFormat()->getFormatCode(), '说明列不得套金额格式');
    }

    public function test_legacy_quarter_detail_keeps_amount_and_no_detail_columns(): void
    {
        // 升级前落库的季度明细（无 ratio）→ 金额原样展示，但不生成逐月列
        $this->seedMgrRow(1, '2026-04', ['perf_detail' => [
            'period' => '2026-Q1', 'pay_grade' => '经理级', 'coef' => 1.0,
            'months' => [
                ['ym' => '2026-01', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ['ym' => '2026-02', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
                ['ym' => '2026-03', 'perf_att' => 22, 'base' => 1000, 'amount' => 1000],
            ],
        ]]);
        $res = $this->getJson('/api/export/managers?ym=2026-04', ['X-Token' => $this->token]);
        $res->assertOk();
        $sheet = $this->loadSheet($res->streamedContent());
        $this->assertSame('AM', $sheet->getHighestColumn());
        $this->assertNull($sheet->getCell('AM3')->getValue());
        $this->assertEquals(2850, (float) $sheet->getCell('N3')->getValue());
    }
}
