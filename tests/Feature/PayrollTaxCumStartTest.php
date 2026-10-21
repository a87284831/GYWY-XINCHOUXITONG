<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 个税累计起算「全局两选项」测试（tax.cum_start）：
 *   - jan（默认，=现状）：起算月 = max(入职月, 当年1月)，零回归
 *   - prev_dec（新）：纳税年度起算工资月 = 上年12月（12月工资属下一纳税年度 → 仅自身）；
 *     起算月 = max(入职月, 起算工资月)，无入职日期者取起算工资月
 *   覆盖：跨档（3%→10%）、12月特例（减除1个月）、年中入职独立计税、无入职日期、
 *   历史窗口包含上年12月（核算路径与微调重算路径同口径）。
 */
class PayrollTaxCumStartTest extends TestCase
{
    use RefreshDatabase;

    private function seedRules(string $cumStart): void
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
            'tax' => ['basic_deduction' => 5000, 'cum_start' => $cumStart, 'brackets' => [
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
            ['payload' => json_encode(['items' => $this->symbols()], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function symbols(): array
    {
        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
        ];
        $out = [];
        foreach ($def as $sym => [$name, $req, $act, $val, $cat]) {
            $out[] = ['symbol' => $sym, 'name' => $name, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        return $out;
    }

    /** $hire 传 null 表示无入职日期（花名册缺失），此时薪档生效日回退到一个很早的日期保证出勤段可用 */
    private function seedStaff(int $id, string $name, float $fixed, ?string $hire = '2026-01-01', array $over = []): void
    {
        $eff = $over['effective_date'] ?? $hire ?? '2025-01-01';
        $data = [
            'salary_history' => [[
                'effective_date' => $eff, 'fixed_monthly' => $fixed, 'base_salary' => $fixed,
                'type' => '初始', 'note' => '',
            ]],
            'special_deductions' => [],
            'bank_card' => '',
            'regular_date' => $over['regular_date'] ?? '',
            'resign_date' => $over['resign_date'] ?? '',
        ];
        if (array_key_exists('tax_mode', $over)) $data['tax_mode'] = $over['tax_mode'];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $over['status'] ?? '正式', 'fixed_monthly' => $fixed, 'base_salary' => $fixed,
            'hire_date' => $hire, 'regular_date' => $over['regular_date'] ?? null,
            'resign_date' => $over['resign_date'] ?? null, 'deleted' => false,
            'person_type' => 'staff', 'dingtalk_userid' => null,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedHistory(int $staffId, string $ym, float $gross, float $tax, float $soc = 0.0, float $spec = 0.0): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => $ym, 'staff_legacy_id' => $staffId, 'project_name' => '测试项目',
            'row_data' => json_encode(
                ['gross' => $gross, 'soc_total' => $soc, 'spec_total' => $spec, 'actual_tax' => $tax],
                JSON_UNESCAPED_UNICODE
            ),
            'archived' => false, 'is_manager_row' => 0, 'is_case_row' => 0, 'is_hq_row' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAtt(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        return [
            'days' => $d, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ];
    }

    private function calc(array $rows, string $ym): array
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculate($ym, ['测试项目']);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', $ym)->get() as $r) {
            $out[$r->staff_legacy_id] = json_decode($r->row_data, true);
        }
        return $out;
    }

    private function check(string $name, $got, $expect, float $tol = 0.02): void
    {
        $this->assertTrue(
            is_numeric($got) && is_numeric($expect) ? abs((float)$got - (float)$expect) <= $tol : $got == $expect,
            "[{$name}] got=" . var_export($got, true) . ', expect=' . var_export($expect, true)
        );
    }

    /**
     * 张三：2025-01 入职，月应发 8800。2026-09 核算：
     *   jan        → 起算 2026-01，减除 9 个月 = 45000，累计应纳税 34200（3% 档）→ 当月 114
     *   prev_dec   → 起算 2025-12，减除 10 个月 = 50000，累计应纳税 38000（跨入 10% 档）→ 当月 254
     */
    private function seedZhangSan(): void
    {
        $this->seedStaff(1, '张三', 8800.0, '2025-01-01');
        // 上年 12 月 + 本年 1-8 月各 8800；逐月个税 114（前 9 个月均处于 3% 档）
        $this->seedHistory(1, '2025-12', 8800.0, 114.0);
        for ($m = 1; $m <= 8; $m++) $this->seedHistory(1, sprintf('2026-%02d', $m), 8800.0, 114.0);
    }

    public function test_prev_dec_crosses_bracket_uses_last_december(): void
    {
        $this->seedRules('prev_dec');
        $this->seedZhangSan();
        $row = $this->calc(['张三' => $this->makeAtt()], '2026-09')[1];
        $this->check('减除月数=10', $row['cum_deduction'], 50000.0);
        $this->check('已预扣含上年12月', $row['paid_before'], 1026.0);
        $this->check('累计应纳税所得额', $row['cum_taxable'], 38000.0);
        $this->check('跨入10%档当月个税', $row['actual_tax'], 254.0);
    }

    public function test_jan_mode_unchanged_zero_regression(): void
    {
        $this->seedRules('jan');
        $this->seedZhangSan();
        $row = $this->calc(['张三' => $this->makeAtt()], '2026-09')[1];
        $this->check('减除月数=9', $row['cum_deduction'], 45000.0);
        $this->check('已预扣不含上年12月', $row['paid_before'], 912.0);
        $this->check('累计应纳税所得额', $row['cum_taxable'], 34200.0);
        $this->check('3%档当月个税', $row['actual_tax'], 114.0);
    }

    public function test_prev_dec_december_month_counts_only_itself(): void
    {
        $this->seedRules('prev_dec');
        $this->seedStaff(4, '赵六', 6000.0, '2025-01-01');
        // 2025-12 + 2026-01..11 的历史行；12 月工资属下一纳税年度首月 → 全部不计入
        $this->seedHistory(4, '2025-12', 6000.0, 30.0);
        for ($m = 1; $m <= 11; $m++) $this->seedHistory(4, sprintf('2026-%02d', $m), 6000.0, 30.0);
        $row = $this->calc(['赵六' => $this->makeAtt()], '2026-12')[4];
        $this->check('12月减除=1个月', $row['cum_deduction'], 5000.0);
        $this->check('12月不累计历史已预扣', $row['paid_before'], 0.0);
        // 当月：6000-5000=1000 → 3% = 30
        $this->check('12月当月个税', $row['actual_tax'], 30.0);
    }

    public function test_prev_dec_mid_year_hire_starts_from_hire_month(): void
    {
        $this->seedRules('prev_dec');
        $this->seedStaff(5, '李四', 6000.0, '2026-04-01');
        // 入职前 1-3 月历史行（旧单位残留）不得计入
        for ($m = 1; $m <= 3; $m++) $this->seedHistory(5, sprintf('2026-%02d', $m), 12000.0, 210.0);
        $row = $this->calc(['李四' => $this->makeAtt()], '2026-09')[5];
        // 起算月 = max(2026-04, 2025-12) = 2026-04 → 4~9 月共 6 个月
        $this->check('年中入职减除=6个月', $row['cum_deduction'], 30000.0);
        $this->check('入职前历史不计入', $row['paid_before'], 0.0);
        $this->check('累计应纳税所得额为0', $row['cum_taxable'], 0.0);
        $this->check('当月个税为0', $row['actual_tax'], 0.0);
    }

    public function test_prev_dec_without_hire_date_uses_window_start(): void
    {
        $this->seedRules('prev_dec');
        $this->seedStaff(6, '王五', 6000.0, null);
        $row = $this->calc(['王五' => $this->makeAtt()], '2026-09')[6];
        $this->check('无入职日 prev_dec 起算上年12月=10个月', $row['cum_deduction'], 50000.0);
    }

    public function test_jan_without_hire_date_uses_year_start(): void
    {
        $this->seedRules('jan');
        $this->seedStaff(6, '王五', 6000.0, null);
        $row = $this->calc(['王五' => $this->makeAtt()], '2026-09')[6];
        $this->check('无入职日 jan 起算当年1月=9个月', $row['cum_deduction'], 45000.0);
    }

    public function test_recompute_derived_prev_dec_includes_last_december(): void
    {
        $this->seedRules('prev_dec');
        $this->seedStaff(7, '钱七', 6000.0, '2025-01-01');
        $this->seedHistory(7, '2025-12', 12000.0, 210.0);
        $row = (new PayrollCalculator())->recomputeDerived([
            'base_pay' => 6000.0, 'perf_pay' => 0.0, 'sick_pay' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'spec_total' => 0.0,
        ], 7, '2026-09');
        $this->check('微调重算减除=10个月', $row['cum_deduction'], 50000.0);
        $this->check('微调重算已预扣含上年12月', $row['paid_before'], 210.0);
    }
}
