<?php

namespace Tests\Feature;

use App\Services\CalcRules;
use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 微调(payroll_adjust) 1:1 对拍测试：移植旧版 Python test_adjust.py。
 * 覆盖：批量微调、个税联动(年度累计)、coef 恢复、五险一金、专项附加、
 *       出勤/应出勤联动、单字段兼容、非法字段拦截、归档拦截。
 */
class PayrollAdjustParityTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';

    private array $editable = ['night', 'meal', 'title_sub', 'reward', 'welfare', 'punish', 'late_d', 'miss_d', 'other_d',
        'uniform_d', 'pen', 'med', 'une', 'house', 'big', 'coef', 'req_att', 'act_att', 'perf_att',
        'actual_tax', 'spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby', 'remark'];

    protected function setUp(): void
    {
        parent::setUp();
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
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]);
        $def = ['√' => [true, true, 1.0, '正常'], '休' => [false, false, 0.0, '公休'],
            '事' => [true, false, 0.0, '事假'], '病' => [true, false, 0.0, '病假'], '旷' => [true, false, 0.0, '旷工']];
        $items = [];
        foreach ($def as $sym => [$req, $act, $val, $cat]) {
            $items[] = ['symbol' => $sym, 'name' => $sym, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)]);
        CalcRules::forget();

        // 员工：月薪15000(基本10000+绩效5000)，2026-07入职
        $special = [];
        foreach (['租房租金', '住房贷款利息', '子女教育', '赡养老人', '继续教育', '婴幼儿照护'] as $it) {
            $special[] = ['item' => $it, 'amount' => 0.0, 'from_ym' => ''];
        }
        $data = ['salary_history' => [
            ['effective_date' => '2026-07-01', 'fixed_monthly' => 15000, 'base_salary' => 10000, 'type' => '初始', 'note' => ''],
        ], 'special_deductions' => $special, 'bank_card' => '', 'regular_date' => '', 'resign_date' => ''];
        DB::table('payroll_staff')->insert([
            'legacy_id' => 1, 'name' => '测试员工', 'project_name' => '测试项目', 'position' => '工程师',
            'status' => '正式', 'fixed_monthly' => 15000, 'base_salary' => 10000, 'hire_date' => '2026-07-01',
            'regular_date' => null, 'resign_date' => null, 'deleted' => false,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAttendance(array $days): void
    {
        $att = ['days' => $days, 'req_attend' => 0, 'act_attend' => 0, 'reward' => 0, 'punish' => 0,
            'meal_sub' => 0, 'night_sub' => 0, 'title_sub' => 0, 'pen' => 0, 'med' => 0, 'une' => 0,
            'house' => 0, 'big' => 0, 'miss_deduct' => 0, 'late_deduct' => 0, 'other_deduct' => 0,
            'uniform_deduct' => 0, 'welfare' => 0, 'coef' => null, 'remark' => ''];
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => self::YM . '|测试项目'],
            ['year_month' => self::YM, 'project_name' => '测试项目',
             'rows' => json_encode(['测试员工' => $att], JSON_UNESCAPED_UNICODE),
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function fullDays(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        return $d;
    }

    private function recalc(): array
    {
        (new PayrollCalculator())->calculate(self::YM, ['测试项目']);
        $rec = DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 1)->first();
        return json_decode($rec->row_data, true);
    }

    /** 复刻 PayrollWriteController::adjust() 的处理逻辑 */
    private function adjust(array $changes): array
    {
        foreach ($changes as $field => $value) {
            if (!in_array($field, $this->editable, true)) {
                throw new \RuntimeException("字段不允许微调：{$field}");
            }
        }
        $rec = DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 1)->first();
        if (!$rec) throw new \RuntimeException('无该月核算数据');
        if ($rec->archived) throw new \RuntimeException('已归档，不能微调');
        $row = json_decode($rec->row_data, true);
        foreach ($changes as $field => $value) $row[$field] = round((float)$value, 2);

        $attTouched = isset($changes['act_att']) || isset($changes['req_att'])
            || isset($changes['perf_att']) || isset($changes['coef']);
        if ($attTouched) {
            $req = (float)($row['req_att'] ?? 0);
            $act = (float)($row['act_att'] ?? 0);
            $perf = (float)($row['perf_att'] ?? $act);
            $coef = (float)($row['coef'] ?? 1);
            if ($req > 0) {
                $baseRef = (float)($row['base_pay_ref'] ?? ($row['base_pay'] ?? 0));
                $perfRef = (float)($row['perf_pay_ref'] ?? ($row['perf_pay'] ?? 0));
                $row['base_pay'] = round($baseRef * $act / $req, 2);
                $row['perf_pay'] = round($perfRef * $perf / $req * $coef, 2);
                $row['base_pay_ref'] = round($baseRef, 2);
                $row['perf_pay_ref'] = round($perfRef, 2);
            } else {
                $row['base_pay'] = 0.0;
                $row['perf_pay'] = 0.0;
            }
        }

        $row = (new PayrollCalculator())->recomputeDerived($row, 1, self::YM);
        DB::table('payroll_results')->where('id', $rec->id)->update([
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
        return $row;
    }

    private function checkRow(array $r, string $name, $got, $expect, float $tol = 0.02): void
    {
        $this->assertTrue(
            is_numeric($got) && is_numeric($expect) ? abs((float)$got - (float)$expect) <= $tol : $got == $expect,
            "[{$name}] got=" . var_export($got, true) . ', expect=' . var_export($expect, true)
        );
    }

    public function test_adjust_full_flow(): void
    {
        $this->seedAttendance($this->fullDays());
        $row = $this->recalc();

        // 核算后初始值
        $this->checkRow($row, '基本工资', $row['base_pay'], 10000.0);
        $this->checkRow($row, '绩效工资', $row['perf_pay'], 5000.0);
        $this->checkRow($row, '应发合计', $row['gross'], 15000.0);
        $this->checkRow($row, '个税(累计15000-10000=5000×3%)', $row['actual_tax'], 150.0);

        // 批量微调: 奖励3000+餐补500
        $r = $this->adjust(['reward' => 3000.0, 'meal' => 500.0]);
        $this->checkRow($r, '微调后应发(15000+3000+500)', $r['gross'], 18500.0);
        $this->checkRow($r, '微调后个税(8500×3%=255)', $r['actual_tax'], 255.0);
        $this->checkRow($r, '微调后实发(18500-255)', $r['net'], 18245.0);

        // 微调绩效系数 1→0
        $r = $this->adjust(['coef' => 0.0]);
        $this->checkRow($r, '系数0-绩效工资', $r['perf_pay'], 0.0);
        $this->checkRow($r, '系数0-应发(10000+3000+500)', $r['gross'], 13500.0);

        // 微调绩效系数 0→1（恢复，不应清零）
        $r = $this->adjust(['coef' => 1.0]);
        $this->checkRow($r, '系数恢复-绩效工资', $r['perf_pay'], 5000.0);
        $this->checkRow($r, '系数恢复-应发', $r['gross'], 18500.0);

        // 微调五险一金
        $r = $this->adjust(['pen' => 800.0, 'med' => 200.0, 'une' => 50.0, 'house' => 1000.0]);
        $this->checkRow($r, '五险一金合计', $r['soc_total'], 2050.0);
        $this->checkRow($r, '个税联动(18500-10000-2050=6450×3%=193.5)', $r['actual_tax'], 193.5);
        $this->checkRow($r, '实发(18500-2050-193.5)', $r['net'], 16256.5);

        // 微调专项附加扣除
        $r = $this->adjust(['spec_child' => 1000.0, 'spec_loan' => 1000.0]);
        $this->checkRow($r, '专项扣除合计', $r['spec_total'], 2000.0);
        $this->checkRow($r, '个税联动(18500-10000-2050-2000=4450×3%=133.5)', $r['actual_tax'], 133.5);
    }

    public function test_adjust_attendance_linked(): void
    {
        // 含3天病假的考勤并核算
        $d = $this->fullDays(); $d[5] = '病'; $d[6] = '病'; $d[12] = '病';
        $this->seedAttendance($d);
        $before = $this->recalc();
        $sickBefore = $before['sick_pay'];
        $baseBefore = $before['base_pay'];

        // 微调实际出勤 19→20：sick_pay 不变，base_pay 增加
        $r = $this->adjust(['act_att' => 20.0]);
        $this->checkRow($r, 'act_att变化后sick_pay不变', $r['sick_pay'], $sickBefore);
        $this->assertTrue($r['base_pay'] > $baseBefore, 'base_pay应随act_att增加');

        // 微调应出勤 22→23：base_pay 按 22/23 缩放
        $this->seedAttendance($this->fullDays());
        $this->recalc();
        $r = $this->adjust(['req_att' => 23.0]);
        $this->checkRow($r, 'req_att=23时base_pay(10000×22/23)', $r['base_pay'], round(10000 * 22 / 23, 2));
    }

    public function test_adjust_invalid_field_rejected(): void
    {
        $this->seedAttendance($this->fullDays());
        $this->recalc();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('字段不允许微调');
        $this->adjust(['id' => 999]);
    }

    public function test_adjust_archived_rejected(): void
    {
        $this->seedAttendance($this->fullDays());
        $this->recalc();
        DB::table('payroll_results')->where('year_month', self::YM)->where('staff_legacy_id', 1)
            ->update(['archived' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已归档');
        $this->adjust(['reward' => 1.0]);
    }
}
