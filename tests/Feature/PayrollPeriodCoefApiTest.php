<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 季度/半年度系数 API：save（批量 upsert）、list（按周期查询）、
 * pending（按核算月列出管理/总部人员的系数录入状态）。
 */
class PayrollPeriodCoefApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->token, $id, now()->addHours(8));
    }

    private function seedStaff(int $id, string $name, string $type, string $level = '经理级'): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => '测试项目', 'position' => '经理',
            'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2025-01-01', 'regular_date' => '2025-02-01',
            'resign_date' => null, 'deleted' => false, 'person_type' => $type,
            'data' => json_encode(['position_level' => $level], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_save_period_coef_upserts(): void
    {
        $this->seedStaff(1, '张三', 'manager');
        $resp = $this->postJson('/api/payroll/period-coef/save', [
            'period_type' => 'quarterly', 'period_key' => '2026-Q1',
            'items' => [['staff_legacy_id' => 1, 'coef' => 1.2]],
        ], ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true, 'saved' => 1]);
        $this->assertDatabaseHas('payroll_period_coefs', [
            'staff_legacy_id' => 1, 'period_type' => 'quarterly', 'period_key' => '2026-Q1', 'coef' => 1.2,
        ]);
        // 再次保存同键 → 更新而非重复
        $this->postJson('/api/payroll/period-coef/save', [
            'period_type' => 'quarterly', 'period_key' => '2026-Q1',
            'items' => [['staff_legacy_id' => 1, 'coef' => 0.8]],
        ], ['X-Token' => $this->token]);
        $this->assertEquals(1, DB::table('payroll_period_coefs')->count());
        $this->assertEquals(0.8, (float) DB::table('payroll_period_coefs')->first()->coef);
    }

    public function test_list_period_coef_returns_saved_with_staff_info(): void
    {
        $this->seedStaff(1, '张三', 'manager');
        $this->seedStaff(2, '李四', 'hq');
        DB::table('payroll_period_coefs')->insert([
            ['staff_legacy_id' => 1, 'period_type' => 'quarterly', 'period_key' => '2026-Q1', 'coef' => 1.0, 'created_at' => now(), 'updated_at' => now()],
            ['staff_legacy_id' => 2, 'period_type' => 'quarterly', 'period_key' => '2026-Q1', 'coef' => 0.9, 'created_at' => now(), 'updated_at' => now()],
            ['staff_legacy_id' => 2, 'period_type' => 'half_year', 'period_key' => '2026-H1', 'coef' => 1.1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $resp = $this->getJson('/api/payroll/period-coef/list?period_type=quarterly&period_key=2026-Q1', ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $items = $resp->json('items');
        $this->assertCount(2, $items, '应按周期过滤，不含 half_year 记录');
        $byName = collect($items)->keyBy('name');
        $this->assertEquals(1.0, (float) $byName['张三']['coef']);
        $this->assertEquals(0.9, (float) $byName['李四']['coef']);
        $this->assertEquals('经理级', $byName['张三']['position_level']);
    }

    public function test_pending_lists_mgr_hq_staff_with_coef_status(): void
    {
        $this->seedStaff(1, '张三', 'manager');
        $this->seedStaff(2, '李四', 'hq', '主管级');
        $this->seedStaff(3, '王五', 'staff'); // 不应出现
        DB::table('payroll_period_coefs')->insert([
            'staff_legacy_id' => 1, 'period_type' => 'quarterly', 'period_key' => '2026-Q1',
            'coef' => 1.0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 2026-04 是季度末月 → 周期 2026-Q1
        $resp = $this->getJson('/api/payroll/period-coef/pending?ym=2026-04', ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true, 'period' => '2026-Q1']);
        $items = collect($resp->json('items'))->keyBy('name');
        $this->assertCount(2, $items, '只含管理/总部人员');
        $this->assertEquals(1.0, (float) $items['张三']['coef']);
        $this->assertNull($items['李四']['coef'], '未录入应为 null');
        $this->assertEquals('主管级', $items['李四']['position_level']);
        // 非季度末月 → 报错提示
        $resp2 = $this->getJson('/api/payroll/period-coef/pending?ym=2026-05', ['X-Token' => $this->token]);
        $resp2->assertJson(['ok' => false]);
    }
}
