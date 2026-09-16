<?php

namespace Tests\Feature;

use App\Services\CalcRules;
use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 薪资核算 1:1 对拍测试：将旧版 Python test_calc.py 的 9 个场景完整移植。
 * 覆盖：缺卡阶梯扣款、旷工(倍数-1)罚款、产假无薪、福利计税、年假带薪、
 *       病假跨调薪段分段、全勤回归、缺卡+旷工+事假组合。
 */
class PayrollParityTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-08';

    private function seedRules(): void
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
            ['payload' => json_encode(['items' => $this->symbols()], JSON_UNESCAPED_UNICODE)]
        );
        CalcRules::forget();
    }

    private function symbols(): array
    {
        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '半' => ['半天', true, true, 0.5, '正常'],
            '值' => ['值班', false, true, 1.0, '值班'],
            '假' => ['带薪假', true, true, 1.0, '年假调休'],
            '缺' => ['缺卡', true, true, 1.0, '缺卡'],
            '迟' => ['迟到', true, true, 1.0, '迟到'],
            '早' => ['早退', true, true, 1.0, '早退'],
            '休' => ['休息日', false, false, 0.0, '公休'],
            '事' => ['事假', true, false, 0.0, '事假'],
            '病' => ['病假', true, false, 0.0, '病假'],
            '产' => ['产假', true, false, 0.0, '产假'],
            '旷' => ['旷工', true, false, 0.0, '旷工'],
        ];
        $out = [];
        foreach ($def as $sym => [$name, $req, $act, $val, $cat]) {
            $out[] = ['symbol' => $sym, 'name' => $name, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        return $out;
    }

    private function seedStaff(int $id, string $name, float $fixed, float $base, string $hire = '2026-07-01', array $over = []): void
    {
        $history = $over['salary_history'] ?? [[
            'effective_date' => $hire, 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'type' => '初始', 'note' => '',
        ]];
        $special = [];
        foreach (['租房租金', '住房贷款利息', '子女教育', '赡养老人', '继续教育', '婴幼儿照护'] as $it) {
            $special[] = ['item' => $it, 'amount' => 0.0, 'from_ym' => ''];
        }
        $data = [
            'salary_history' => $history,
            'special_deductions' => $special,
            'bank_card' => '',
            'regular_date' => $over['regular_date'] ?? '',
            'resign_date' => $over['resign_date'] ?? '',
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $over['status'] ?? '正式', 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => $hire, 'regular_date' => $over['regular_date'] ?? null,
            'resign_date' => $over['resign_date'] ?? null, 'deleted' => $over['deleted'] ?? false,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAtt(array $days, array $over = []): array
    {
        return array_merge([
            'days' => $days, 'req_attend' => 0.0, 'act_attend' => 0.0,
            'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
            'welfare' => 0.0, 'coef' => null, 'remark' => '',
        ], $over);
    }

    private function fullDays(): array
    {
        $d = array_fill(0, 31, '休');
        for ($i = 1; $i <= 22; $i++) $d[$i - 1] = '√';
        return $d;
    }

    private function calc(array $rows): array
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|测试项目', 'year_month' => self::YM, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new PayrollCalculator())->calculate(self::YM, ['测试项目']);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', self::YM)->get() as $r) {
            $out[$r->staff_legacy_id] = json_decode($r->row_data, true);
        }
        return $out;
    }

    private function checkRow(array $r, string $name, $got, $expect, float $tol = 0.02): void
    {
        $this->assertTrue(
            is_numeric($got) && is_numeric($expect) ? abs((float)$got - (float)$expect) <= $tol : $got == $expect,
            "[{$name}] got=" . var_export($got, true) . ', expect=' . var_export($expect, true)
        );
    }

    public function test_scenario1_miss_punch_2times(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '张三', 6000, 5000);
        $d = $this->fullDays(); $d[5] = '缺'; $d[10] = '缺';
        $rows = $this->calc(['张三' => $this->makeAtt($d)]);
        $r = $rows[1];
        $this->checkRow($r, '缺卡扣款(2×30=60)', $r['miss_d'], 60.0);
        $this->checkRow($r, '应发(6000-60)', $r['gross'], 5940.0);
    }

    public function test_scenario2_miss_punch_5times(): void
    {
        $this->seedRules();
        $this->seedStaff(2, '李四', 6000, 5000);
        $d = $this->fullDays();
        foreach ([2, 5, 8, 12, 15] as $i) $d[$i] = '缺';
        $rows = $this->calc(['李四' => $this->makeAtt($d)]);
        $this->checkRow($rows[2], '缺卡扣款(3×30+2×50=190)', $rows[2]['miss_d'], 190.0);
    }

    public function test_scenario3_absent_3x(): void
    {
        $this->seedRules();
        $this->seedStaff(3, '王五', 6000, 5000);
        $d = $this->fullDays(); $d[10] = '旷';
        $rows = $this->calc(['王五' => $this->makeAtt($d)]);
        $r = $rows[3];
        $this->checkRow($r, '应出勤', $r['req_att'], 22);
        $this->checkRow($r, '实际出勤', $r['act_att'], 21);
        $this->checkRow($r, '基本工资(5000×21/22)', $r['base_pay'], round(5000 * 21 / 22, 2));
        $this->checkRow($r, '旷工罚款(5000/22×2=454.55)', $r['other_d'], round(5000 / 22 * 2, 2));
        $this->checkRow($r, '应发(含3倍扣款)', $r['gross'],
            round(5000 * 21 / 22 + 1000 * 21 / 22 - 5000 / 22 * 2, 2));
    }

    public function test_scenario4_maternity_no_pay(): void
    {
        $this->seedRules();
        $this->seedStaff(4, '赵六', 6000, 5000);
        $d = $this->fullDays(); $d[5] = '产'; $d[6] = '产'; $d[12] = '产';
        $rows = $this->calc(['赵六' => $this->makeAtt($d)]);
        $r = $rows[4];
        $this->checkRow($r, '实际出勤(不含产假)', $r['act_att'], 19);
        $this->checkRow($r, '基本工资(5000×19/22)', $r['base_pay'], round(5000 * 19 / 22, 2));
        $this->checkRow($r, '无产假工资', $r['maternity_pay'] ?? 0, 0);
    }

    public function test_scenario5_welfare_taxed(): void
    {
        $this->seedRules();
        $this->seedStaff(5, '钱七', 6000, 5000, '2026-08-01', ['regular_date' => '2026-08-01']);
        $rows = $this->calc(['钱七' => $this->makeAtt($this->fullDays(), ['welfare' => 500.0])]);
        $r = $rows[5];
        $this->checkRow($r, '应发(含福利6500)', $r['gross'], 6500.0);
        $this->checkRow($r, '个税(福利计税45)', $r['actual_tax'], 45.0);
        $this->checkRow($r, '实发(扣除福利=5955)', $r['net'], 5955.0);
    }

    public function test_scenario6_paid_leave(): void
    {
        $this->seedRules();
        $this->seedStaff(6, '孙八', 6000, 5000);
        $d = $this->fullDays(); $d[10] = '假'; $d[11] = '假';
        $rows = $this->calc(['孙八' => $this->makeAtt($d)]);
        $r = $rows[6];
        $this->checkRow($r, '应出勤(含年假)', $r['req_att'], 22);
        $this->checkRow($r, '实际出勤(含年假)', $r['act_att'], 22);
        $this->checkRow($r, '基本工资(不扣)', $r['base_pay'], 5000.0);
        $this->checkRow($r, '绩效工资(不扣)', $r['perf_pay'], 1000.0);
    }

    public function test_scenario7_sick_pay_segmented(): void
    {
        $this->seedRules();
        $history = [
            ['effective_date' => '2026-07-01', 'fixed_monthly' => 5000, 'base_salary' => 4000, 'type' => '初始', 'note' => ''],
            ['effective_date' => '2026-08-16', 'fixed_monthly' => 6000, 'base_salary' => 5000, 'type' => '调薪', 'note' => ''],
        ];
        $this->seedStaff(7, '周九', 6000, 5000, '2026-07-01', ['salary_history' => $history]);
        $d = $this->fullDays(); $d[9] = '病'; $d[19] = '病';
        $rows = $this->calc(['周九' => $this->makeAtt($d)]);
        $r = $rows[7];
        $sick = round(4000 * 0.7 / 22 * 0.6 * 1 + 5000 * 0.7 / 22 * 0.6 * 1, 2);
        $this->checkRow($r, '病假工资(分段)', $r['sick_pay'], $sick);
    }

    public function test_scenario8_full_attendance(): void
    {
        $this->seedRules();
        $this->seedStaff(8, '吴十', 6000, 5000);
        $rows = $this->calc(['吴十' => $this->makeAtt($this->fullDays())]);
        $r = $rows[8];
        $this->checkRow($r, '基本工资', $r['base_pay'], 5000.0);
        $this->checkRow($r, '绩效工资', $r['perf_pay'], 1000.0);
        $this->checkRow($r, '应发合计', $r['gross'], 6000.0);
    }

    public function test_scenario9_combined(): void
    {
        $this->seedRules();
        $this->seedStaff(9, '郑十一', 6000, 5000);
        $d = $this->fullDays(); $d[3] = '缺'; $d[7] = '旷'; $d[14] = '事';
        $rows = $this->calc(['郑十一' => $this->makeAtt($d)]);
        $r = $rows[9];
        $this->checkRow($r, '实际出勤', $r['act_att'], 20);
        $this->checkRow($r, '缺卡扣款(30)', $r['miss_d'], 30.0);
        $this->checkRow($r, '旷工罚款(454.55)', $r['other_d'], round(5000 / 22 * 2, 2));
        $this->checkRow($r, '应发合计', $r['gross'],
            round(5000 * 20 / 22 + 1000 * 20 / 22 - 30 - 5000 / 22 * 2, 2));
    }
}
