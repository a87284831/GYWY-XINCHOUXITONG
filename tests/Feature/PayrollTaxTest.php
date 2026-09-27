<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 个税累计预扣与核算健壮性测试：
 *   - 跨月跳档（3%→10%）、年中入职起算、6万扣除模式（tax_mode=1）
 *   - 外部年初至今累计（data.year_cum_income / year_cum_social / year_cum_spec / year_cum_tax）
 *   - 微调后按年度累计重算个税（recomputeDerived）
 *   - 考勤缺人 missing 名单、按 staff_id 标记匹配考勤行
 *   - 归档行重算保全（未重插者原样回插，归档标记不丢失）
 *   - 公式引用未定义变量时中止核算（不静默落库）
 */
class PayrollTaxTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-06';

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
    }

    private function symbols(): array
    {
        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
            '病' => ['病假', true, false, 0.0, '病假'],
            '缺' => ['缺卡', true, true, 1.0, '缺卡'],
        ];
        $out = [];
        foreach ($def as $sym => [$name, $req, $act, $val, $cat]) {
            $out[] = ['symbol' => $sym, 'name' => $name, 'desc' => '', 'in_required' => $req,
                'in_actual' => $act, 'value' => $val, 'category' => $cat];
        }
        return $out;
    }

    private function seedStaff(int $id, string $name, float $fixed, float $base, string $hire = '2026-01-01', array $over = []): void
    {
        $history = [[
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
        foreach (['tax_mode', 'year_cum_income', 'year_cum_tax', 'year_cum_social', 'year_cum_spec'] as $k) {
            if (array_key_exists($k, $over)) $data[$k] = $over[$k];
        }
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '测试岗',
            'status' => $over['status'] ?? '正式', 'fixed_monthly' => $fixed, 'base_salary' => $base,
            'hire_date' => $hire, 'regular_date' => $over['regular_date'] ?? null,
            'resign_date' => $over['resign_date'] ?? null, 'deleted' => false,
            'person_type' => $over['person_type'] ?? 'staff',
            'dingtalk_userid' => $over['dingtalk_userid'] ?? null,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 直接种一条历史核算结果（模拟此前月份已按该收入/税额核算过） */
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

    private function calc(array $rows, string $ym = self::YM): array
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = (new PayrollCalculator())->calculate($ym, ['测试项目']);
        $out = [];
        foreach (DB::table('payroll_results')->where('year_month', $ym)->get() as $r) {
            $out[$r->staff_legacy_id] = json_decode($r->row_data, true);
        }
        return ['rows' => $out, 'result' => $result];
    }

    private function checkRow(array $r, string $name, $got, $expect, float $tol = 0.02): void
    {
        $this->assertTrue(
            is_numeric($got) && is_numeric($expect) ? abs((float)$got - (float)$expect) <= $tol : $got == $expect,
            "[{$name}] got=" . var_export($got, true) . ', expect=' . var_export($expect, true)
        );
    }

    public function test_tax_bracket_jump_across_months(): void
    {
        $this->seedRules();
        $this->seedStaff(1, '张三', 12000, 10000, '2026-01-01');
        // 1-5 月各 12000，累计应纳税 7000/月 → 每月 210，5 个月已预扣 1050
        for ($m = 1; $m <= 5; $m++) $this->seedHistory(1, sprintf('2026-%02d', $m), 12000.0, 210.0);
        $r = $this->calc(['张三' => $this->makeAtt($this->fullDays())]);
        $row = $r['rows'][1];
        // 6 月：累计收入 72000，减除 30000 → 应纳税 42000 → 跳入 10% 档：42000*0.1-2520=1680 → 当月 630
        $this->checkRow($row, '已预扣合计', $row['paid_before'], 1050.0);
        $this->checkRow($row, '累计应纳税所得额', $row['cum_taxable'], 42000.0);
        $this->checkRow($row, '跳档后当月个税', $row['actual_tax'], 630.0);
    }

    public function test_mid_year_hire_starts_from_hire_month(): void
    {
        $this->seedRules();
        $this->seedStaff(2, '李四', 6000, 5000, '2026-04-01');
        // 入职前 1-3 月的历史行（旧单位残留记录）不得计入
        for ($m = 1; $m <= 3; $m++) $this->seedHistory(2, sprintf('2026-%02d', $m), 12000.0, 210.0);
        $r = $this->calc(['李四' => $this->makeAtt($this->fullDays())], '2026-04');
        $row = $r['rows'][2];
        $this->checkRow($row, '已预扣为0', $row['paid_before'], 0.0);
        $this->checkRow($row, '减除费用(入职月起算1个月)', $row['cum_deduction'], 5000.0);
        // 累计应纳税 = 6000-5000 = 1000 → 3% = 30
        $this->checkRow($row, '当月个税', $row['actual_tax'], 30.0);
    }

    public function test_tax_mode_60000_deduction(): void
    {
        $this->seedRules();
        $this->seedStaff(3, '王五', 6000, 5000, '2026-01-01', ['tax_mode' => 1]);
        $r = $this->calc(['王五' => $this->makeAtt($this->fullDays())]);
        $row = $r['rows'][3];
        $this->checkRow($row, '6万扣除模式减除额', $row['cum_deduction'], 60000.0);
        $this->checkRow($row, '累计应纳税所得额为0', $row['cum_taxable'], 0.0);
        $this->checkRow($row, '当月个税为0', $row['actual_tax'], 0.0);
    }

    public function test_external_year_cumulative_counts_in_tax(): void
    {
        $this->seedRules();
        // 年中入职：本系统首月核算，但此前在原单位已有收入 20000、五险一金 2000、专项附加 1000、已预扣 300
        $this->seedStaff(4, '赵六', 6000, 5000, '2026-03-01', [
            'year_cum_income' => 20000.0, 'year_cum_tax' => 300.0,
            'year_cum_social' => 2000.0, 'year_cum_spec' => 1000.0,
        ]);
        $r = $this->calc(['赵六' => $this->makeAtt($this->fullDays())], '2026-03');
        $row = $r['rows'][4];
        // 累计应纳税 = (20000+6000) - 5000 - 2000 - 1000 = 18000 → 3% = 540 → 当月 = 540-300 = 240
        $this->checkRow($row, '累计应纳税所得额(含外部)', $row['cum_taxable'], 18000.0);
        $this->checkRow($row, '已预扣(含外部)', $row['paid_before'], 300.0);
        $this->checkRow($row, '当月个税', $row['actual_tax'], 240.0);
    }

    public function test_adjust_recompute_uses_year_cumulative_tax(): void
    {
        $this->seedRules();
        $this->seedStaff(5, '钱七', 6000, 5000, '2026-01-01');
        $this->seedHistory(5, '2026-01', 10000.0, 150.0);
        $row = (new PayrollCalculator())->recomputeDerived([
            'base_pay' => 10000.0, 'perf_pay' => 0.0, 'sick_pay' => 0.0,
            'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
            'spec_total' => 0.0,
        ], 5, '2026-02');
        // 2月微调重算：累计收入 20000，减除 10000 → 应纳税 10000 → 300 → 当月 = 300-150 = 150
        $this->checkRow($row, '微调重算个税', $row['actual_tax'], 150.0);
        $this->checkRow($row, '累计应纳税所得额', $row['cum_taxable'], 10000.0);
        $this->checkRow($row, '已预扣', $row['paid_before'], 150.0);
    }

    public function test_missing_staff_listed_and_stamped_row_matched_by_id(): void
    {
        $this->seedRules();
        $this->seedStaff(6, '张三', 6000, 5000, '2026-01-01');
        $this->seedStaff(7, '李四', 6000, 5000, '2026-01-01');
        $this->seedStaff(8, '王五', 6000, 5000, '2026-01-01');
        // 王五的考勤行键名与姓名不一致，但带 staff_id 标记（模拟上传时打标）→ 应按 ID 匹配
        $attRows = [
            '张三' => $this->makeAtt($this->fullDays()),
            '考勤表别名' => ['staff_id' => 8, 'dingtalk_userid' => ''] + $this->makeAtt($this->fullDays()),
        ];
        $r = $this->calc($attRows);
        $this->assertSame(2, $r['result']['count']);
        $this->assertCount(1, $r['result']['missing']);
        $this->assertSame('李四', $r['result']['missing'][0]['name']);
        $this->assertArrayHasKey(8, $r['rows'], '带 staff_id 标记的考勤行应能匹配到人员');
    }

    public function test_recalc_preserves_archived_rows_not_recalculated(): void
    {
        $this->seedRules();
        $this->seedStaff(9, '张三', 6000, 5000, '2026-01-01');
        $this->seedStaff(10, '李四', 6000, 5000, '2026-01-01');
        $r = $this->calc([
            '张三' => $this->makeAtt($this->fullDays()),
            '李四' => $this->makeAtt($this->fullDays()),
        ]);
        $this->assertSame(2, $r['result']['count']);
        // 归档两人的结果行后，考勤只剩张三，重算
        DB::table('payroll_results')->where('year_month', self::YM)->update(['archived' => true]);
        DB::table('payroll_attendance')->where('record_key', self::YM . '|测试项目')->delete();
        $r2 = $this->calc(['张三' => $this->makeAtt($this->fullDays())]);
        $this->assertSame(1, $r2['result']['count']);
        // preserved = 张三(重插后恢复归档标记) + 李四(未重插原样回插)
        $this->assertSame(2, $r2['result']['preserved_archived']);
        $rows = DB::table('payroll_results')->where('year_month', self::YM)->get();
        $this->assertCount(2, $rows, '李四的归档行应原样保留，不得静默丢失');
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->archived, "重算不应丢失归档标记: staff={$row->staff_legacy_id}");
        }
        $lisi = json_decode($rows->firstWhere('staff_legacy_id', 10)->row_data, true);
        $this->checkRow($lisi, '李四归档数据原样保留', $lisi['gross'], 6000.0);
    }

    public function test_manager_mid_year_with_full_external_cumulative(): void
    {
        $this->seedRules();
        // 月薪1.5万管理人员 7 月入职：原单位 1-6 月累计收入 90000、五险一金 12000、专项附加 6000、已预扣 3480
        $this->seedStaff(12, '高管', 15000, 10000, '2026-07-01', [
            'year_cum_income' => 90000.0, 'year_cum_social' => 12000.0,
            'year_cum_spec' => 6000.0, 'year_cum_tax' => 3480.0,
        ]);
        $r = $this->calc(['高管' => $this->makeAtt($this->fullDays())], '2026-07');
        $row = $r['rows'][12];
        // 累计应纳税 = (90000+15000) - 5000(本单位任职1个月) - 12000 - 6000 = 82000 → 10%档: 8200-2520=5680
        // 当月 = 5680 - 已预扣 3480 = 2200
        $this->checkRow($row, '累计应纳税所得额(含外部)', $row['cum_taxable'], 82000.0);
        $this->checkRow($row, '已预扣(含外部)', $row['paid_before'], 3480.0);
        $this->checkRow($row, '当月个税', $row['actual_tax'], 2200.0);
    }

    public function test_undefined_variable_in_formula_aborts_calc(): void
    {
        $this->seedRules();
        $this->seedStaff(11, '张三', 6000, 5000, '2026-01-01');
        // 把应发公式改坏（引用不存在的变量），核算必须报错而不是静默按 0 落库
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $rules = json_decode((string) $snap->payload, true);
        $rules['rules']['formula'] = ['gross' => 'base_pay + perf_pay + typo_var', 'net' => 'gross - soc_total - actual_tax - welfare'];
        DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')
            ->update(['payload' => json_encode($rules, JSON_UNESCAPED_UNICODE)]);
        try {
            $this->calc(['张三' => $this->makeAtt($this->fullDays())]);
            $this->fail('公式引用未定义变量应中止核算');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('应发公式计算失败', $e->getMessage());
        }
        $this->assertSame(0, DB::table('payroll_results')->where('year_month', self::YM)->count(), '失败核算不得落库');
    }
}
