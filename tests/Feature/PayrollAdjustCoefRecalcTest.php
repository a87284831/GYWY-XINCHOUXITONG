<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 微调修正：
 *  - 改绩效系数后按同一套公式重算绩效/应发/实发（系数 0 也能恢复）；
 *  - 工资写库与审计日志同一事务，日志失败整体回滚；
 *  - 季度/半年度比例 0~100% 校验。
 */
class PayrollAdjustCoefRecalcTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';
    private const PROJ = '测试项目';
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9501, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'calc_rules.json'], [
            'payload' => json_encode(['rules' => [
                'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
                'performance' => ['enabled' => true, 'probation_excluded' => true],
                'sick_pay' => ['enabled' => false],
                'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                    [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                    [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
                ]],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'symbols.json'], [
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedRow(array $overrides = []): int
    {
        $row = array_merge([
            'project' => self::PROJ, 'name' => '张三', 'fixed' => 15000, 'base' => 10000,
            'req_att' => 22, 'act_att' => 22, 'perf_att' => 22, 'coef' => 0,
            'base_pay_ref' => 10000, 'perf_pay_ref' => 5000,
            'base_pay' => 10000, 'perf_pay' => 0, 'sick_pay' => 0,
            'night' => 0, 'meal' => 0, 'title_sub' => 0, 'reward' => 0, 'backpay' => 0, 'welfare' => 0,
            'punish' => 0, 'late_d' => 0, 'miss_d' => 0, 'other_d' => 0, 'uniform_d' => 0,
            'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
            'soc_total' => 0, 'spec_total' => 0, 'actual_tax' => 0, 'tax_diff' => 0,
            'gross' => 10000, 'net' => 10000, 'remark' => '',
        ], $overrides);
        return (int) DB::table('payroll_results')->insertGetId([
            'year_month' => self::YM, 'project_name' => self::PROJ, 'staff_legacy_id' => 1,
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'archived' => false, 'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function adjust(array $fields)
    {
        return $this->postJson('/api/payroll/adjust', [
            'ym' => self::YM, 'staff_id' => 1, 'fields' => $fields,
        ], ['X-Token' => $this->adminToken]);
    }

    public function test_coef_change_recomputes_perf_gross_net_from_zero(): void
    {
        $this->seedRow(['coef' => 0, 'perf_pay' => 0, 'gross' => 10000]);

        // 0 → 0.8：绩效 = 5000 × 22/22 × 0.8 = 4000
        $resp = $this->adjust(['coef' => 0.8]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertEqualsWithDelta(4000.0, (float) $resp->json('row.perf_pay'), 0.001);
        $this->assertEqualsWithDelta(14000.0, (float) $resp->json('row.gross'), 0.001);
        $this->assertEqualsWithDelta(14000.0, (float) $resp->json('row.net'), 0.001, '累计减除额未超，个税为 0');

        // 0.8 → 1：绩效恢复 5000，应发 15000（不能停在旧值）
        $resp = $this->adjust(['coef' => 1]);
        $resp->assertOk();
        $this->assertEqualsWithDelta(5000.0, (float) $resp->json('row.perf_pay'), 0.001);
        $this->assertEqualsWithDelta(15000.0, (float) $resp->json('row.gross'), 0.001);
        $this->assertEqualsWithDelta(15000.0, (float) $resp->json('row.net'), 0.001);

        $stored = json_decode((string) DB::table('payroll_results')->where('year_month', self::YM)->value('row_data'), true);
        $this->assertEqualsWithDelta(5000.0, (float) $stored['perf_pay'], 0.001);
        $this->assertEqualsWithDelta(15000.0, (float) $stored['gross'], 0.001);
    }

    public function test_adjust_log_written_with_row_in_same_transaction(): void
    {
        $this->seedRow();
        $this->adjust(['coef' => 1])->assertOk();
        $this->assertSame(1, DB::table('payroll_adjust_logs')->where('ym', self::YM)->count());
    }

    public function test_log_failure_rolls_back_row_update(): void
    {
        $this->seedRow(['gross' => 10000, 'perf_pay' => 0, 'coef' => 0]);
        // 让审计日志写库必然失败：移除日志表
        DB::statement('DROP TABLE payroll_adjust_logs');

        $resp = $this->adjust(['coef' => 1]);
        $resp->assertStatus(500);
        $this->assertStringContainsString('回滚', (string) $resp->json('error'));

        $stored = json_decode((string) DB::table('payroll_results')->where('year_month', self::YM)->value('row_data'), true);
        $this->assertEqualsWithDelta(0.0, (float) $stored['coef'], 0.001, '日志失败时工资修改必须回滚');
        $this->assertEqualsWithDelta(0.0, (float) $stored['perf_pay'], 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $stored['gross'], 0.001);
    }

    public function test_quarter_ratio_out_of_range_is_rejected(): void
    {
        $payload = fn ($ratio) => ['rules' => ['pay_rules' => [
            'manager' => ['cycle' => 'quarterly', 'levels' => ['经理级' => ['mode' => 'quarter', 'quarter_ratio' => $ratio]]],
        ]]];

        $this->postJson('/api/calc_rules/save', $payload(1.2), ['X-Token' => $this->adminToken])
            ->assertStatus(400)
            ->assertJsonPath('ok', false);
        $this->postJson('/api/calc_rules/save', $payload(-0.1), ['X-Token' => $this->adminToken])
            ->assertStatus(400);
        $this->postJson('/api/calc_rules/save', $payload(0.5), ['X-Token' => $this->adminToken])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
