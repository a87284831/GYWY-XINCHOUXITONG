<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 工资全量数据包（GET /api/export/summary）汇总页固定结构：
 * 即使某类人员（管理人员/案场人员/总部）当月无核算数据，合计行也固定显示（人数 0、金额 0），
 * 便于核对是否有遗漏。
 */
class ExportSummaryFixedStructureTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private const YM = '2026-09';

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
        // 只种 1 个基层员工，无管理/案场/总部数据
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'staff_legacy_id' => 2, 'project_name' => '测试项目',
            'row_data' => json_encode([
                'project' => '测试项目', 'department' => '客服部', 'position' => '专员', 'name' => '李四',
                'status' => '正式', 'fixed' => 5000, 'base' => 4000, 'gross' => 4500, 'net' => 4500,
            ], JSON_UNESCAPED_UNICODE),
            'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_summary_shows_fixed_total_rows_even_without_mgr_case_hq_data(): void
    {
        $resp = $this->getJson('/api/export/summary?ym=' . self::YM, ['X-Token' => $this->token]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);
        // 找三个合计行 + 总计行（A 列文本匹配）
        $labels = [];
        foreach ($grid as $row) {
            $a = trim((string)($row[0] ?? ''));
            if (in_array($a, ['管理人员合计', '案场人员合计', '物业总部合计', '总计'], true)) {
                $labels[$a] = true;
            }
        }
        $this->assertArrayHasKey('管理人员合计', $labels, '管理人员合计行应固定显示（即使无数据）');
        $this->assertArrayHasKey('案场人员合计', $labels, '案场人员合计行应固定显示（即使无数据）');
        $this->assertArrayHasKey('物业总部合计', $labels, '物业总部合计行应固定显示（即使无数据）');
        $this->assertArrayHasKey('总计', $labels, '总计行应显示');

        @unlink($tmp);
    }
}
