<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 考勤统计与缺卡扣款预览修复：
 *  1. attendanceStats 的 stats['required'] 按 value 算（让"半"符号算 0.5 天应出勤，与实出勤口径一致）
 *  2. attView 接口在 stats 里返回 miss_deduct（按阶梯规则算缺卡扣款金额，让用户上传后立即可见）
 */
class AttendanceStatsFixTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private const YM = '2026-09';
    private const PROJ = '罗庄春暖花开';

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('a', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            'name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'value' => 1.0, 'category' => '正常', 'in_required' => true, 'in_actual' => true],
                ['symbol' => '休', 'name' => '休息日', 'value' => 0.0, 'category' => '公休', 'in_required' => false, 'in_actual' => false],
                ['symbol' => '半', 'name' => '半天出勤', 'value' => 0.5, 'category' => '正常', 'in_required' => true, 'in_actual' => true],
                ['symbol' => '缺', 'name' => '缺卡', 'value' => 1.0, 'category' => '缺卡', 'in_required' => true, 'in_actual' => true],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'calc_rules.json',
            'payload' => json_encode(['rules' => [
                'deduction_rules' => [
                    'miss_punch' => ['enabled' => true, 'first_3' => 30, 'after_3' => 50],
                ],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_half_day_symbol_counts_as_half_required_value(): void
    {
        // 1 个 √ + 1 个 半 + 1 个 休 → attendanceStats.required 仍按 in_required 计 1 天（核算口径不变），
        // 但 attView 的 required_value 按 value 加权算 0.5 天（与人力手填口径对齐）
        $resp = $this->setupAndGetAttView(['√', '半', '休']);
        $st = $resp->json('stats.测试员');
        $this->assertEqualsWithDelta(2, $st['required'], 0.001, 'required 仍按 in_required 计 1 天');
        $this->assertEqualsWithDelta(1.5, $st['required_value'], 0.001, 'required_value 按 value 加权，半算 0.5');
        $this->assertEqualsWithDelta(1.5, $st['attend'], 0.001, '实出勤口径不变');
    }

    public function test_attview_returns_miss_deduct_by_stair_rule(): void
    {
        // 1 人 1 次缺卡 → 30 元；1 人 5 次缺卡 → 3×30 + 2×50 = 190 元
        $days1 = array_fill(0, 31, '√'); $days1[5] = '缺';
        $days5 = array_fill(0, 31, '√');
        foreach ([1,2,3,4,5] as $i) $days5[$i] = '缺';
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ,
            'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode([
                '甲一人' => array_merge($this->baseAtt(), ['name' => '甲一人', 'days' => $days1]),
                '乙五次' => array_merge($this->baseAtt(), ['name' => '乙五次', 'days' => $days5]),
            ], JSON_UNESCAPED_UNICODE),
            'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->getJson('/api/attendance?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $stats = $resp->json('stats');
        $this->assertSame(1, $stats['甲一人']['miss'] ?? null);
        $this->assertEqualsWithDelta(30.0, $stats['甲一人']['miss_deduct'] ?? null, 0.01);
        $this->assertSame(5, $stats['乙五次']['miss'] ?? null);
        $this->assertEqualsWithDelta(190.0, $stats['乙五次']['miss_deduct'] ?? null, 0.01);
    }

    public function test_attview_miss_deduct_zero_when_no_miss(): void
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ,
            'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode([
                '无缺卡' => array_merge($this->baseAtt(), ['name' => '无缺卡', 'days' => array_fill(0, 31, '√')]),
            ], JSON_UNESCAPED_UNICODE),
            'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->getJson('/api/attendance?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $stats = $resp->json('stats');
        $this->assertSame(0, $stats['无缺卡']['miss'] ?? -1);
        $this->assertEqualsWithDelta(0.0, $stats['无缺卡']['miss_deduct'] ?? -1, 0.01);
    }

    private function setupAndGetAttView(array $days): \Illuminate\Testing\TestResponse
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ,
            'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode([
                '测试员' => array_merge($this->baseAtt(), ['name' => '测试员', 'days' => $days]),
            ], JSON_UNESCAPED_UNICODE),
            'locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->getJson('/api/attendance?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->adminToken]);
    }

    private function baseAtt(): array
    {
        return [
            'position' => '测试岗', 'status' => '正式', 'req_attend' => 22, 'act_attend' => 22,
            'reward' => 0, 'punish' => 0, 'meal_sub' => 0, 'night_sub' => 0, 'title_sub' => 0,
            'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
            'miss_deduct' => 0, 'late_deduct' => 0, 'other_deduct' => 0, 'uniform_deduct' => 0,
            'coef' => null, 'remark' => '',
        ];
    }
}
