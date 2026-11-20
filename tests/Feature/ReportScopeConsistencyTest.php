<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 报表中心口径与 /api/summary 一致：
 *  - 项目合计 = 基层 + 管理；案场、总部分别单列；
 *  - 人均应发的分子分母同时只取基层 + 管理；
 *  - 驾驶舱、人力报表、薪酬报表三个入口同一口径。
 */
class ReportScopeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9501, 'username' => 'rep_admin', 'name' => '报表管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9502, 'username' => 'rep_proj', 'name' => '甲项目人力',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('r', 64);
        $this->projToken = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => '甲项目', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
            ['name' => '物业公司总部', 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_budgets')->insert([
            'year' => 2026, 'project_name' => '甲项目',
            'data' => json_encode(['annual' => 120000, 'months' => ['10' => 10000]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 基层 1000 / 管理 5000 / 案场 800 / 总部 9999
        $this->row('员工甲', 1000, 900, 1000, 0, 5000, 4000, false, false);
        $this->row('经理甲', 5000, 4500, 0, 1000, 6000, 5000, true, false);
        $this->row('案场甲', 800, 700, 0, 800, 2000, 1200, false, true);
        $this->row('总部王', 9999, 8888, 0, 0, 0, 0, false, false, true, '物业公司总部');

        foreach ([[1, '员工甲', 'staff'], [2, '经理甲', 'manager'], [3, '案场甲', 'case'], [4, '总部王', 'hq']] as [$sid, $name, $type]) {
            DB::table('payroll_staff')->insert([
                'legacy_id' => $sid, 'name' => $name, 'project_name' => $type === 'hq' ? '物业公司总部' : '甲项目',
                'position' => '岗位', 'status' => '正式', 'person_type' => $type,
                'fixed_monthly' => 5000, 'base_salary' => 4000, 'hire_date' => '2025-01-01',
                'regular_date' => '2025-04-01', 'resign_date' => null, 'deleted' => false,
                'data' => json_encode(['education' => '本科', 'hometown' => '广东']),
                'dept_path' => ($type === 'hq' ? '物业公司总部' : '甲项目') . '/客服部',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function row(string $name, float $gross, float $net, float $basePay, float $perfPay,
                        float $fixed, float $base, bool $mgr, bool $case, bool $hq = false, string $project = '甲项目'): void
    {
        static $sid = 700;
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => $project, 'staff_legacy_id' => $sid++,
            'row_data' => json_encode(['project' => $project, 'name' => $name, 'gross' => $gross, 'net' => $net,
                'base_pay' => $basePay, 'perf_pay' => $perfPay, 'fixed' => $fixed, 'base' => $base,
                'soc_total' => 100, 'actual_tax' => 10], JSON_UNESCAPED_UNICODE),
            'archived' => true, 'is_manager_row' => $mgr, 'is_case_row' => $case, 'is_hq_row' => $hq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_salary_report_splits_categories_and_matches_summary(): void
    {
        $r = $this->get('/api/report/salary?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $kpis = collect($r->json('kpis'))->keyBy('key');

        $this->assertSame(6000.0, (float) $kpis['gross']['value'], '项目合计 = 基层 + 管理');
        $this->assertSame(5400.0, (float) $kpis['net']['value']);
        $this->assertSame(2, (int) $kpis['count']['value']);
        $this->assertSame(3000.0, (float) $kpis['avg']['value'], '人均分子分母同时只取基层 + 管理');

        $b = $r->json('breakdown');
        $this->assertSame(1000.0, (float) $b['base']['gross']);
        $this->assertSame(5000.0, (float) $b['manager']['gross']);
        $this->assertSame(800.0, (float) $b['case']['gross']);
        $this->assertSame(9999.0, (float) $b['hq']['gross']);
        $this->assertSame(800.0, (float) $r->json('case_total.gross'));
        $this->assertSame(1, (int) $r->json('case_total.headcount'));

        $pc = collect($r->json('project_compare'));
        $this->assertCount(1, $pc, '纯总部载体项目不进入项目对比');
        $this->assertSame(6000.0, (float) $pc->firstWhere('label', '甲项目')['gross']);
        $this->assertSame(800.0, (float) $pc->firstWhere('label', '甲项目')['case_gross']);

        // 构成只统计项目口径，案场/总部不混入
        $comp = collect($r->json('composition'))->keyBy('label');
        $this->assertSame(1000.0, (float) $comp['基本工资']['value']);

        // 与 /api/summary 同口径
        $s = $this->get('/api/summary?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $s->assertOk();
        $this->assertSame((float) $s->json('total.gross'), (float) $kpis['gross']['value']);
        $this->assertSame((float) $s->json('total.base_gross'), (float) $b['base']['gross']);
        $this->assertSame((float) $s->json('total.manager_gross'), (float) $b['manager']['gross']);
        $this->assertSame((float) $s->json('total.case_gross'), (float) $b['case']['gross']);
    }

    public function test_project_account_sees_same_category_numbers_for_own_project(): void
    {
        $r = $this->get('/api/report/salary?ym=' . self::YM, ['X-Token' => $this->projToken]);
        $r->assertOk();
        $kpis = collect($r->json('kpis'))->keyBy('key');
        $this->assertSame(6000.0, (float) $kpis['gross']['value']);
        $this->assertSame(800.0, (float) $r->json('breakdown.case.gross'));
        $this->assertSame(0.0, (float) $r->json('breakdown.hq.gross'), '总部单列不计入项目账号口径');
    }

    public function test_hr_report_uses_same_split_for_cost_and_avg_pay(): void
    {
        $r = $this->get('/api/report/hr?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $cost = collect($r->json('cost_by_project'))->firstWhere('label', '甲项目');
        $this->assertNotNull($cost);
        $this->assertSame(6000.0, (float) $cost['gross']);
        $this->assertSame(2, (int) $cost['headcount']);
        $this->assertSame(800.0, (float) $cost['case_gross']);
        $this->assertSame(3000.0, (float) $r->json('kpis.avg_pay'));
        $this->assertSame(800.0, (float) $r->json('cost_breakdown.case.gross'));
    }

    public function test_dashboard_uses_same_split(): void
    {
        $r = $this->get('/api/dashboard?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $r->assertOk();
        $this->assertSame(6000.0, (float) $r->json('month_gross'));
        $this->assertSame(5400.0, (float) $r->json('month_net'));
        $this->assertSame(2, (int) $r->json('month_headcount'));
        $this->assertSame(3000.0, (float) $r->json('avg_gross'));
        $this->assertSame(800.0, (float) $r->json('month_breakdown.case.gross'));
        $this->assertSame(9999.0, (float) $r->json('month_breakdown.hq.gross'));
        $this->assertSame(800.0, (float) $r->json('projects.0.case_gross'));
        $this->assertSame(6000.0, (float) $r->json('months.9.gross'));
    }
}
