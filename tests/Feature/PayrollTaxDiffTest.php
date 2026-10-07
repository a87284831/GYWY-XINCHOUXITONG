<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 个税补差（tax_diff）：
 *  - 微调可填正/负数，实发 = 实发公式结果 + tax_diff
 *  - 只改实发：应发合计、本月个税、累计预扣均不受影响（补差不再计税）
 *  - 旧数据（row_data 无 tax_diff 字段）重算安全，按 0 处理
 *  - 归档月仍禁止微调
 *  - 导出明细表在「实发工资」列前新增「个税补差」列
 */
class PayrollTaxDiffTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-07';
    private const PROJ = '测试项目';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        $this->seedBase();
    }

    private function seedBase(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'sick_pay' => ['enabled' => true, 'params' => ['factor_a' => 0.7, 'factor_b' => 0.6, 'sick_base' => 'base']],
            'meal_subsidy' => ['mode' => 'full'],
            'allowances' => ['mode' => 'full'],
            'deduction_rules' => [
                'miss_punch' => ['enabled' => true, 'first_3' => 30, 'after_3' => 50],
                'absent' => ['enabled' => true, 'multiplier' => 3],
            ],
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false, 'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
            ]], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_staff')->insert([
            'legacy_id' => 2, 'name' => '正常人', 'project_name' => self::PROJ, 'position' => '测试岗',
            'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '初始', 'note' => '']],
                'special_deductions' => [], 'regular_date' => '', 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $d = array_fill(0, 31, '休');
        for ($i = 0; $i < 20; $i++) $d[$i] = '√';
        $att = ['days' => $d, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => ''];
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ, 'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode(['正常人' => $att], JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Services\PayrollCalculator())->calculate(self::YM, [self::PROJ]);
    }

    private function rowFromDb(): array
    {
        return json_decode((string) DB::table('payroll_results')
            ->where('year_month', self::YM)->where('staff_legacy_id', 2)->value('row_data'), true) ?: [];
    }

    private function adjust(array $fields, string $reason = '测试补差'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payroll/adjust', [
            'ym' => self::YM, 'staff_id' => 2, 'fields' => $fields, 'reason' => $reason,
        ], ['X-Token' => $this->adminToken]);
    }

    public function test_positive_tax_diff_increases_net_only(): void
    {
        $before = $this->rowFromDb();

        $this->adjust(['tax_diff' => 200])->assertOk();
        $after = $this->rowFromDb();

        $this->assertEqualsWithDelta((float) $before['net'] + 200.0, (float) $after['net'], 0.001);
        // 应发、个税、累计应纳税所得额均不受影响（补差不参与计税）
        $this->assertEqualsWithDelta((float) $before['gross'], (float) $after['gross'], 0.001);
        $this->assertEqualsWithDelta((float) $before['actual_tax'], (float) $after['actual_tax'], 0.001);
        $this->assertEqualsWithDelta((float) $before['cum_taxable'], (float) $after['cum_taxable'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $after['tax_diff'], 0.001);

        // 微调日志记录 tax_diff 旧值→新值
        $log = DB::table('payroll_adjust_logs')->where('ym', self::YM)->orderBy('id')->first();
        $items = json_decode((string) $log->changes, true);
        $this->assertSame('tax_diff', $items[0]['field']);
        $this->assertEqualsWithDelta(0.0, (float) $items[0]['old'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $items[0]['new'], 0.001);
    }

    public function test_negative_tax_diff_decreases_net(): void
    {
        $before = $this->rowFromDb();

        $this->adjust(['tax_diff' => -150.5])->assertOk();
        $after = $this->rowFromDb();

        $this->assertEqualsWithDelta((float) $before['net'] - 150.5, (float) $after['net'], 0.001);
        $this->assertEqualsWithDelta(-150.5, (float) $after['tax_diff'], 0.001);
    }

    public function test_tax_diff_survives_explicit_tax_overwrite_recalc(): void
    {
        // 先补差 +100，再显式改个税（触发 userGaveTax 分支的实发公式重算）
        $this->adjust(['tax_diff' => 100])->assertOk();
        $resp = $this->adjust(['actual_tax' => 0])->assertOk();
        $row = $resp->json('row');

        $expectedNet = round((float) $row['gross'] - (float) $row['soc_total']
            - (float) $row['actual_tax'] - (float) $row['welfare'] + 100.0, 2);
        $this->assertEqualsWithDelta($expectedNet, (float) $row['net'], 0.001,
            '显式改个税后的实发重算仍应叠加个税补差');
    }

    public function test_archived_row_cannot_adjust_tax_diff(): void
    {
        DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 2)
            ->update(['archived' => true]);
        $this->adjust(['tax_diff' => 100])->assertStatus(400);
    }

    public function test_legacy_row_without_tax_diff_field_recomputes_safely(): void
    {
        // 模拟旧月份数据：row_data 无 tax_diff 字段（如已核算的 8 月），微调其他字段重算不报错、实发不受补差影响
        $row = $this->rowFromDb();
        unset($row['tax_diff']);
        DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 2)
            ->update(['row_data' => json_encode($row, JSON_UNESCAPED_UNICODE)]);

        $before = $this->rowFromDb();
        $resp = $this->adjust(['reward' => 100], '旧数据兼容')->assertOk();
        $after = $resp->json('row');

        $this->assertEqualsWithDelta((float) $before['net'] + 100.0, (float) $after['net'], 0.001,
            '无 tax_diff 字段的旧行按 0 处理，实发仅随 reward 变化');
        $this->assertEqualsWithDelta(0.0, (float) ($after['tax_diff'] ?? 0), 0.001);
    }

    public function test_project_export_has_tax_diff_column_before_net(): void
    {
        $this->adjust(['tax_diff' => 88.88])->assertOk();

        $res = $this->get('/api/export/project?ym=' . self::YM . '&project=' . urlencode(self::PROJ),
            ['X-Token' => $this->adminToken]);
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        // 表头：第34列「个税补差」在第35列「实发工资」之前，第36列「备注」
        $this->assertSame('个税补差', $sheet->getCell('AH2')->getValue());
        $this->assertSame('实发工资', $sheet->getCell('AI2')->getValue());
        $this->assertSame('备注', $sheet->getCell('AJ2')->getValue());
        // 数据行（第3行）补差值
        $this->assertEqualsWithDelta(88.88, (float) $sheet->getCell('AH3')->getValue(), 0.001);
    }
}
