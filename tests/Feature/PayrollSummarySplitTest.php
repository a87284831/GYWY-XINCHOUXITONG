<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 项目汇总按类别分列：基层 / 管理 / 案场。
 *  - 项目合计 = 基层 + 管理（人数与应发/实发）；
 *  - 案场单独列出，不计入项目合计；
 *  - 物业公司总部等载体项目不展示；
 *  - 项目账号只能看到汇总金额（本项目），不返回人员明细。
 */
class PayrollSummarySplitTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9401, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9402, 'username' => 'jia_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('s', 64);
        $this->projToken = str_repeat('q', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->row('员工甲', 1000, 900, false, false);
        $this->row('经理甲', 5000, 4500, true, false);
        $this->row('案场甲', 2000, 1800, false, true);
        $this->row('总部王', 9999, 8888, false, false, true, '物业公司总部');
    }

    private function row(string $name, float $gross, float $net, bool $mgr, bool $case, bool $hq = false, string $project = '甲项目'): void
    {
        static $sid = 600;
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $project, 'staff_legacy_id' => $sid++,
            'row_data' => json_encode(['project' => $project, 'name' => $name, 'gross' => $gross, 'net' => $net], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => $mgr, 'is_case_row' => $case, 'is_hq_row' => $hq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_summary_splits_base_manager_and_case(): void
    {
        $resp = $this->get('/api/summary?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();

        $items = collect($resp->json('items'));
        $this->assertCount(1, $items, '物业公司总部等载体项目不应出现在项目汇总里');
        $item = $items->firstWhere('project', '甲项目');
        $this->assertNotNull($item);

        $this->assertSame(1, $item['base_headcount']);
        $this->assertSame(1, $item['manager_headcount']);
        $this->assertSame(1, $item['case_headcount']);
        $this->assertEqualsWithDelta(1000.0, $item['base_gross'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $item['manager_gross'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $item['case_gross'], 0.001);

        // 项目合计 = 基层 + 管理；案场不计入
        $this->assertSame(2, $item['headcount'], '项目合计人数 = 基层 + 管理');
        $this->assertEqualsWithDelta(6000.0, $item['gross'], 0.001, '项目合计应发 = 基层 + 管理');
        $this->assertEqualsWithDelta(5400.0, $item['net'], 0.001);

        $total = $resp->json('total');
        $this->assertEqualsWithDelta(6000.0, $total['gross'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $total['base_gross'] + $total['manager_gross'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $total['case_gross'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $resp->json('case_total.gross'), 0.001, '案场单独合计');
        $this->assertSame(1, $resp->json('case_total.headcount'));

        // 总部人员单独返回，不并入项目合计
        $hq = collect($resp->json('hq_items'))->firstWhere('project', '物业公司总部');
        $this->assertNotNull($hq, '总部人员应在 hq_items 中单独返回');
        $this->assertSame(1, $hq['headcount']);
        $this->assertEqualsWithDelta(9999.0, $hq['gross'], 0.001);
        $this->assertEqualsWithDelta(8888.0, $hq['net'], 0.001);
        $this->assertSame(1, $resp->json('hq_total.headcount'));
        $this->assertEqualsWithDelta(9999.0, $resp->json('hq_total.gross'), 0.001);
        $this->assertEqualsWithDelta(8888.0, $resp->json('hq_total.net'), 0.001);
        $this->assertStringNotContainsString('总部王', (string) $resp->getContent(), '只返回汇总，不返回总部人员姓名');
    }

    public function test_project_scope_sees_aggregates_only_for_own_project(): void
    {
        $resp = $this->get('/api/summary?ym=' . self::YM, ['X-Token' => $this->projToken]);
        $resp->assertOk();

        $items = collect($resp->json('items'));
        $this->assertCount(1, $items);
        $item = $items->firstWhere('project', '甲项目');
        $this->assertEqualsWithDelta(1000.0, $item['base_gross'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $item['manager_gross'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $item['gross'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $item['case_gross'], 0.001);

        // 只有汇总金额，不返回人员明细（无姓名/逐人字段）
        $this->assertArrayNotHasKey('name', $item);
        $this->assertStringNotContainsString('经理甲', (string) $resp->getContent());
        // 项目账号看不到总部人员
        $this->assertCount(0, $resp->json('hq_items'));
        $this->assertSame(0, $resp->json('hq_total.headcount'));
        $this->assertEqualsWithDelta(0.0, $resp->json('hq_total.gross'), 0.001);
    }

    public function test_summary_export_contains_hq_section(): void
    {
        $res = $this->get('/api/summary/export?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertSame('PK', substr($content, 0, 2));

        $file = sys_get_temp_dir() . '/payroll_summary_hq_test.xlsx';
        file_put_contents($file, $content);
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
            $text = json_encode($sheet->toArray(null, true, true, false), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('总部人员（单独核算，不计入项目合计）', $text);
            $this->assertStringContainsString('总部合计（不计入项目合计）', $text);
            $this->assertStringContainsString('物业公司总部', $text);
            // 总部应发 9999 / 实发 8888 在导出中可见（单元格已套千分位格式）
            $this->assertStringContainsString('9,999', $text);
            $this->assertStringContainsString('8,888', $text);
        } finally {
            @unlink($file);
        }
    }
}
