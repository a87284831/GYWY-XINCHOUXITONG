<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 薪资精度统一（问题1，方案A）：
 *  - 所有金额分项落库前统一四舍五入到分（round 2），包括此前未 round 的
 *    night/meal/title_sub/reward/welfare/punish/late_d/uniform_d 与五险分项
 *  - gross/net 一律从"已 round 的分项"求值，保证默认公式下行内严格勾稽（到分）：
 *      gross = 分项代数和；soc_total = 五险分项和；spec_total = 专项六项和；
 *      net = gross - soc_total - actual_tax - welfare
 *  - 微调重算同口径；季度累计基数与逐月展示口径一致（见季度测试）
 * 所有比较转"分"为整数，规避浮点噪音。
 */
class PayrollPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';
    private const PROJ = '测试项目';

    /** 金额必须精确到分：×100 后与整数无可见偏差 */
    private function assertMoneyToCent($value, string $field): void
    {
        $this->assertTrue(is_numeric($value), "{$field} 必须是数值");
        $cents = (float) $value * 100;
        $this->assertTrue(abs($cents - round($cents)) < 1e-6, "{$field} 未精确到分：{$value}");
    }

    /** 落库值到分（整数） */
    private function c($v): int
    {
        return (int) round((float) $v * 100);
    }

    /**
     * 默认公式下的行内勾稽（gross/soc/spec/net），精确到 0 分误差。
     * 右侧按"各落库分项分别到分后整数累加"计算——即人工在 Excel 里核对的口径；
     * 这要求分项自身必须先 round，否则与已 round 的合计对不上。
     */
    private function assertRowBalances(array $r): void
    {
        $grossCents = $this->c($r['base_pay']) + $this->c($r['perf_pay']) + $this->c($r['sick_pay'])
            + $this->c($r['night']) + $this->c($r['meal']) + $this->c($r['title_sub'])
            + $this->c($r['reward']) + $this->c($r['welfare'])
            - $this->c($r['punish']) - $this->c($r['miss_d']) - $this->c($r['late_d'])
            - $this->c($r['other_d']) - $this->c($r['uniform_d']);
        $this->assertSame($grossCents, $this->c($r['gross']),
            "gross({$r['gross']}) 与各分项到分后之和(" . number_format($grossCents / 100, 2) . ')存在尾差');

        $socCents = $this->c($r['pen']) + $this->c($r['med']) + $this->c($r['une'])
            + $this->c($r['house']) + $this->c($r['big']);
        $this->assertSame($socCents, $this->c($r['soc_total']),
            "soc_total({$r['soc_total']}) 与五险分项到分后之和(" . number_format($socCents / 100, 2) . ')存在尾差');

        $specCents = $this->c($r['spec_rent']) + $this->c($r['spec_loan']) + $this->c($r['spec_child'])
            + $this->c($r['spec_elder']) + $this->c($r['spec_edu']) + $this->c($r['spec_baby']);
        $this->assertSame($specCents, $this->c($r['spec_total']),
            "spec_total({$r['spec_total']}) 与专项六项到分后之和(" . number_format($specCents / 100, 2) . ')存在尾差');

        $netCents = $this->c($r['gross']) - $this->c($r['soc_total'])
            - $this->c($r['actual_tax']) - $this->c($r['welfare']);
        $this->assertSame($netCents, $this->c($r['net']),
            "net({$r['net']}) 与 gross-soc-tax-welfare(" . number_format($netCents / 100, 2) . ')存在尾差');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'sick_pay' => ['enabled' => true, 'params' => ['factor_a' => 0.7, 'factor_b' => 0.6, 'sick_base' => 'base']],
            // 折算模式是多位小数的主要来源：min(全额, 全额×实出勤/应出勤)
            'meal_subsidy' => ['mode' => 'prorate'],
            'allowances' => ['mode' => 'prorate'],
            'deduction_rules' => [
                'miss_punch' => ['enabled' => true, 'first_3' => 30, 'after_3' => 50],
                'absent' => ['enabled' => true, 'multiplier' => 3],
            ],
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '半', 'name' => '半天出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 0.5, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false, 'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
            ]], JSON_UNESCAPED_UNICODE)]);
        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $special = [];
        foreach (
            [['租房租金', 1000.0], ['住房贷款利息', 500.0], ['子女教育', 1000.0],
             ['赡养老人', 2000.0], ['继续教育', 400.0], ['婴幼儿照护', 1000.0]] as [$item, $amt]
        ) {
            $special[] = ['item' => $item, 'amount' => $amt, 'from_ym' => ''];
        }
        DB::table('payroll_staff')->insert([
            'legacy_id' => 2, 'name' => '精度员', 'project_name' => self::PROJ, 'position' => '工程师',
            'status' => '正式', 'fixed_monthly' => 15000, 'base_salary' => 10000,
            'hire_date' => '2026-01-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'staff',
            'data' => json_encode([
                'salary_history' => [['effective_date' => '2026-01-01', 'fixed_monthly' => 15000, 'base_salary' => 10000, 'type' => '初始', 'note' => '']],
                'special_deductions' => $special, 'regular_date' => '', 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 21 个全天 + 1 个半天（应出勤 21.5、实出勤 20.5），补贴/五险带非整除小数
        $d = array_fill(0, 31, '休');
        for ($i = 0; $i < 21; $i++) $d[$i] = '√';
        $d[21] = '半';
        $att = ['days' => $d, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 333.33, 'punish' => 27.77, 'meal_sub' => 500.0, 'night_sub' => 300.0, 'title_sub' => 155.55,
            'welfare' => 88.88,
            'pen' => 812.33, 'med' => 203.11, 'une' => 50.77, 'house' => 1015.55, 'big' => 3.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 19.99,
            'coef' => null, 'remark' => ''];
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ, 'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode(['精度员' => $att], JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function calcRow(): array
    {
        (new PayrollCalculator())->calculate(self::YM, [self::PROJ]);
        $rec = DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 2)->first();
        return json_decode($rec->row_data, true);
    }

    private const MONEY_FIELDS = [
        'base_pay', 'perf_pay', 'sick_pay', 'night', 'meal', 'title_sub', 'reward', 'welfare',
        'punish', 'late_d', 'miss_d', 'other_d', 'uniform_d', 'gross',
        'pen', 'med', 'une', 'house', 'big', 'soc_total',
        'spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby', 'spec_total',
        'actual_tax', 'net', 'cum_tax',
    ];

    public function test_every_money_field_is_rounded_to_cent(): void
    {
        $r = $this->calcRow();
        foreach (self::MONEY_FIELDS as $f) {
            $this->assertArrayHasKey($f, $r, "缺少字段 {$f}");
            $this->assertMoneyToCent($r[$f], $f);
        }
        // 折算路径必须产生过多位小数（否则本测试没有真正覆盖到 round 逻辑）
        $this->assertNotEquals((float) $r['meal'], 500.0, 'prorate 下餐补应被折算');
        $this->assertTrue((float) $r['meal'] > 0 && (float) $r['meal'] < 500.0);
    }

    public function test_row_balances_to_the_cent_after_calculation(): void
    {
        $this->assertRowBalances($this->calcRow());
    }

    public function test_row_balances_after_subsidy_adjust(): void
    {
        $this->calcRow();
        $row = (new PayrollCalculator())->recomputeDerived(
            $this->adjustRow(['reward' => 412.30, 'meal' => 555.55]), 2, self::YM
        );
        $this->assertMoneyToCent($row['reward'], 'reward');
        $this->assertMoneyToCent($row['meal'], 'meal');
        $this->assertRowBalances($row);
    }

    public function test_row_balances_after_social_and_spec_adjust(): void
    {
        $this->calcRow();
        $row = (new PayrollCalculator())->recomputeDerived(
            $this->adjustRow(['pen' => 900.01, 'spec_child' => 1333.33]), 2, self::YM
        );
        $this->assertRowBalances($row);
    }

    /** 复刻控制器 adjust 的字段落值（round 2），不写库，仅给 recomputeDerived 喂入 */
    private function adjustRow(array $changes): array
    {
        $rec = DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 2)->first();
        $row = json_decode($rec->row_data, true);
        foreach ($changes as $f => $v) $row[$f] = round((float) $v, 2);
        return $row;
    }
}
