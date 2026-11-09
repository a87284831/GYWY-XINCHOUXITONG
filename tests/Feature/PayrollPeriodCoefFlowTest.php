<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 季度系数录入链路（工资页 → 生成工资表 → 录入系数 → 核算）真实 HTTP 链路：
 *   GET  /api/payroll/period-coef/pending  页面「已录/未录」提示与弹窗名单
 *   POST /api/payroll/period-coef/save     录入（写 payroll_period_coefs + 双写季末月未归档行）
 *   POST /api/payroll/calc-managers        核算（新口径读同一份 payroll_period_coefs）
 *
 * 两条操作路径必须结果一致：
 *   A. 先录 2026-Q3 系数 0.9 → 生成 2026-10 工资表
 *   B. 先生成（此时 missing_coef、绩效 0）→ 补录 0.9 → 重算
 * 数字：fixed 6000 / base 5000 → 季度满勤基数 3×1000 = 3000；
 *      7/8/9 月各 22 天 √ → Σperf_att/Σperf_req = 66/66 = 1.0；
 *      季度绩效 = 3000 × 1.0 × 0.9 = 2700.00。
 */
class PayrollPeriodCoefFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private const YM = '2026-10';
    private const QUARTER = '2026-Q3';
    private const STAFF_ID = 1;
    private const NAME = '张三';

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9101, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('c', 64);
        Cache::put('payroll_api_token:' . $this->token, $adminId, now()->addHours(8));
    }

    private function seedRules(): void
    {
        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true, 'quarter_sum' => true],
            'pay_rules' => [
                'manager' => ['cycle' => 'quarter_grade', 'levels' => [
                    '经理级' => ['mode' => 'quarter'],
                    '主管级' => ['mode' => 'monthly'],
                    '专员级' => ['mode' => 'monthly'],
                ]],
                'hq' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'staff' => ['cycle' => 'monthly', 'ratio' => 1.0],
                'case' => ['cycle' => 'monthly', 'ratio' => 1.0],
            ],
        ];
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'calc_rules.json'],
            ['payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE)]
        );
        DB::table('legacy_json_snapshots')->updateOrInsert(
            ['file_name' => 'symbols.json'],
            ['payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true,
                    'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false,
                    'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
            ]], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function seedStaff(): void
    {
        $data = [
            'salary_history' => [[
                'effective_date' => '2025-01-01', 'fixed_monthly' => 6000, 'base_salary' => 5000,
                'type' => '初始', 'note' => '',
            ]],
            'special_deductions' => [], 'bank_card' => '',
            'regular_date' => '2025-02-01', 'resign_date' => '',
            'pay_grade' => '经理级',
        ];
        DB::table('payroll_staff')->insert([
            'legacy_id' => self::STAFF_ID, 'name' => self::NAME, 'project_name' => '测试项目',
            'position' => '经理', 'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2025-01-01', 'regular_date' => '2025-02-01', 'resign_date' => null,
            'deleted' => false, 'person_type' => 'manager',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 某月考勤：前 22 天 √，其余 休 */
    private function seedAttendance(string $ym): void
    {
        $n = (int) date('t', strtotime($ym . '-01'));
        $days = array_fill(0, $n, '休');
        for ($i = 1; $i <= 22; $i++) $days[$i - 1] = '√';
        DB::table('payroll_attendance')->insert([
            'record_key' => $ym . '|测试项目', 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode([self::NAME => [
                'days' => $days, 'req_attend' => null, 'act_attend' => null,
                'reward' => 0.0, 'punish' => 0.0, 'meal_sub' => 0.0, 'night_sub' => 0.0, 'title_sub' => 0.0,
                'pen' => 0.0, 'med' => 0.0, 'une' => 0.0, 'house' => 0.0, 'big' => 0.0,
                'miss_deduct' => 0.0, 'late_deduct' => 0.0, 'other_deduct' => 0.0, 'uniform_deduct' => 0.0,
                'welfare' => 0.0, 'coef' => null, 'remark' => '',
            ]], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 工资页点击「核算」→ POST /api/payroll/calc-managers */
    private function calc(string $ym): array
    {
        $resp = $this->postJson('/api/payroll/calc-managers', ['ym' => $ym], ['X-Token' => $this->token]);
        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('ok'), "{$ym} 核算失败：" . $resp->json('error'));
        return $resp->json();
    }

    /** 工资页「录入 xx 季度系数」按钮 → pending 弹窗名单 */
    private function pending(): array
    {
        $resp = $this->get('/api/payroll/period-coef/pending?ym=' . self::YM . '&category=manager', ['X-Token' => $this->token]);
        $resp->assertOk();
        return $resp->json();
    }

    /** 弹窗保存 → POST /api/payroll/period-coef/save */
    private function saveCoef(float $coef): void
    {
        $resp = $this->postJson('/api/payroll/period-coef/save', [
            'period_type' => 'quarterly',
            'period_key' => self::QUARTER,
            'items' => [['staff_legacy_id' => self::STAFF_ID, 'coef' => $coef]],
        ], ['X-Token' => $this->token]);
        $resp->assertOk();
        $this->assertSame(1, $resp->json('saved'));
    }

    private function row(string $ym, ?int $staffId = null): array
    {
        $r = DB::table('payroll_results')->where('year_month', $ym)
            ->where('staff_legacy_id', $staffId ?? self::STAFF_ID)
            ->where('is_manager_row', true)->first();
        return $r ? (json_decode((string) $r->row_data, true) ?: []) : [];
    }

    /** 7/8/9 月先按月核算，为季末 10 月积累 perf_att/perf_req/perf_base_month */
    private function seedQuarterHistory(): void
    {
        foreach (['2026-07', '2026-08', '2026-09'] as $m) {
            $this->seedAttendance($m);
            $this->calc($m);
        }
    }

    /** 公共断言：季度绩效 = 3000 × 66/66 × 0.9 = 2700，无 missing_coef */
    private function assertQuarterResult(array $row, float $coef = 0.9): void
    {
        $this->assertArrayNotHasKey('error', $row['perf_quarter'] ?? ['error' => 'missing']);
        $this->assertEquals(2700.0, (float) $row['perf_pay']);
        $this->assertEquals(3000.0, (float) $row['perf_quarter']['base']);
        $this->assertEquals(66.0, (float) $row['perf_quarter']['att']);
        $this->assertEquals(66.0, (float) $row['perf_quarter']['req']);
        $this->assertEquals(1.0, (float) $row['perf_quarter']['ratio']);
        $this->assertEquals($coef, (float) $row['perf_quarter']['coef']);
        $this->assertEquals(2700.0, (float) $row['perf_quarter']['amount']);
        // 双写校验：周期系数表 + 季末月行 row_data.coef 都应为 0.9
        $this->assertEquals($coef, (float) DB::table('payroll_period_coefs')
            ->where('staff_legacy_id', self::STAFF_ID)
            ->where('period_type', 'quarterly')->where('period_key', self::QUARTER)->value('coef'));
        $this->assertEquals($coef, (float) $row['coef']);
    }

    private function assertWarningsContainMissingCoef(array $warnings): void
    {
        $hit = collect($warnings)->first(fn ($w) => ($w['level'] ?? '') === 'danger'
            && str_contains((string) ($w['reason'] ?? ''), '季度/半年度绩效系数未录入'));
        $this->assertNotNull($hit, '缺系数时应在核算 warnings 中给出 danger 提示');
    }

    private function assertNoMissingCoefWarning(array $warnings): void
    {
        $hit = collect($warnings)->first(fn ($w) => str_contains((string) ($w['reason'] ?? ''), '绩效系数未录入'));
        $this->assertNull($hit, '录入系数后不应再报 missing_coef');
    }

    /** 路径 A：先录系数 → 生成 2026-10 工资表 */
    public function test_path_a_save_coef_then_calc(): void
    {
        $this->seedRules();
        $this->seedStaff();
        $this->seedQuarterHistory();

        // 页面提示：Q3 未录入（0/1）
        $p1 = $this->pending();
        $this->assertSame(self::QUARTER, $p1['period']);
        $this->assertCount(1, $p1['items']);
        $this->assertNull($p1['items'][0]['coef']);

        $this->saveCoef(0.9);

        // 页面提示：Q3 已录（1/1）
        $p2 = $this->pending();
        $this->assertEquals(0.9, (float) $p2['items'][0]['coef']);

        $this->seedAttendance(self::YM);
        $resp = $this->calc(self::YM);
        $this->assertNoMissingCoefWarning($resp['warnings'] ?? []);
        $this->assertQuarterResult($this->row(self::YM));
    }

    /** 路径 B：先生成（无系数、missing_coef）→ 补录 0.9 → 重算，结果与路径 A 一致 */
    public function test_path_b_calc_then_save_coef_then_recalc(): void
    {
        $this->seedRules();
        $this->seedStaff();
        $this->seedQuarterHistory();

        $this->seedAttendance(self::YM);
        // 第一次生成：无系数 → 季度绩效 0 + missing_coef danger 提示
        $first = $this->calc(self::YM);
        $row0 = $this->row(self::YM);
        $this->assertEquals(0.0, (float) $row0['perf_pay']);
        $this->assertSame('missing_coef', $row0['perf_quarter']['error'] ?? null);
        $this->assertWarningsContainMissingCoef($first['warnings'] ?? []);
        // 未录时页面提示
        $this->assertNull($this->pending()['items'][0]['coef']);

        // 补录 → 双写到已存在的 2026-10 未归档行
        $this->saveCoef(0.9);
        $rowWritten = $this->row(self::YM);
        $this->assertEquals(0.9, (float) $rowWritten['coef']);

        // 重算：读取同一份 payroll_period_coefs → 与路径 A 相同
        $second = $this->calc(self::YM);
        $this->assertNoMissingCoefWarning($second['warnings'] ?? []);
        $this->assertQuarterResult($this->row(self::YM));
    }
}
