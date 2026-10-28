<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 维保模块升级：保存校验、变更留痕、多附件、履约记录、设备台账、
 * 报表分级状态/覆盖率/单位成本/同比、预警扫描防重、xlsx 导出。
 */
class MaintenanceUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_roles')->insert(['role_key' => 'pm', 'name' => '项目经理', 'scope' => 'project',
            'permissions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'pm_a', 'name' => '项目A经理',
            'role' => 'pm', 'project_name' => '项目A', 'password_hash' => 'x',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('a', 64);
        $this->projToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));
    }

    private function hA(): array { return ['X-Token' => $this->adminToken]; }
    private function hP(): array { return ['X-Token' => $this->projToken]; }

    private function validPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'fire', 'project_name' => '项目A', 'party' => '某消防公司',
            'amount' => 120000, 'building_area_sqm' => 10000,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ], $over);
    }

    private function createContract(array $over = [], ?string $token = null): int
    {
        $resp = $this->postJson('/api/maintenance/contracts', $this->validPayload($over), ['X-Token' => $token ?? $this->adminToken]);
        $resp->assertOk()->assertJson(['ok' => true]);
        return (int) $resp->json('contract.id');
    }

    public function test_save_contract_validation(): void
    {
        $this->postJson('/api/maintenance/contracts', $this->validPayload(['amount' => 0]), $this->hA())
            ->assertStatus(400)->assertJson(['ok' => false]);
        $this->postJson('/api/maintenance/contracts', $this->validPayload(['building_area_sqm' => 0]), $this->hA())
            ->assertStatus(400);
        $this->postJson('/api/maintenance/contracts', $this->validPayload(['type' => 'elevator', 'elevator_count' => 0]), $this->hA())
            ->assertStatus(400);
        $this->postJson('/api/maintenance/contracts', $this->validPayload(['end_date' => '2025-12-31']), $this->hA())
            ->assertStatus(400);
        // 合法可保存，含新字段
        $resp = $this->postJson('/api/maintenance/contracts', $this->validPayload([
            'contract_no' => 'HT-2026-001', 'party_a' => '某物业公司', 'party_contact' => '张工',
            'party_phone' => '13800000000', 'service_scope' => '消电检+维保', 'includes_supplies' => true,
        ]), $this->hA())->assertOk()->assertJson(['ok' => true]);
        $c = $resp->json('contract');
        $this->assertSame('HT-2026-001', $c['contract_no']);
        $this->assertSame(1, (int) $c['includes_supplies']);
    }

    public function test_contract_change_logs(): void
    {
        $id = $this->createContract();
        $this->assertDatabaseHas('maintenance_contract_logs', ['contract_id' => $id, 'action' => 'create']);
        // 修改金额 → 留痕
        $this->putJson("/api/maintenance/contracts/{$id}", $this->validPayload(['amount' => 150000]), $this->hA())
            ->assertOk()->assertJson(['ok' => true]);
        $logs = $this->getJson("/api/maintenance/contracts/{$id}/logs", $this->hA())->json('logs');
        $this->assertCount(2, $logs);
        $upd = collect($logs)->firstWhere('action', 'update');
        $this->assertArrayHasKey('签约金额', $upd['changes']);
        $this->assertSame('150000', $upd['changes']['签约金额']['new']);
        // 无变化不再写日志
        $this->putJson("/api/maintenance/contracts/{$id}", $this->validPayload(['amount' => 150000]), $this->hA())->assertOk();
        $this->assertCount(2, $this->getJson("/api/maintenance/contracts/{$id}/logs", $this->hA())->json('logs'));
    }

    public function test_attachments_flow(): void
    {
        Storage::fake('local');
        $id = $this->createContract();
        $resp = $this->post("/api/maintenance/contracts/{$id}/attachments", [
            'kind' => 'contract', 'file' => UploadedFile::fake()->create('合同正文.pdf', 100, 'application/pdf'),
        ], $this->hA())->assertOk()->assertJson(['ok' => true]);
        $attId = (int) $resp->json('attachment.id');
        $this->assertSame('合同正文', $resp->json('attachment.kind_label'));

        $list = $this->getJson("/api/maintenance/contracts/{$id}/attachments", $this->hA())->json('attachments');
        $this->assertCount(1, $list);
        $this->assertSame('合同正文.pdf', $list[0]['name']);

        $this->get("/api/maintenance/attachments/{$attId}/download", $this->hA())->assertOk();
        $this->deleteJson("/api/maintenance/attachments/{$attId}", [], $this->hA())->assertOk();
        $this->assertCount(0, $this->getJson("/api/maintenance/contracts/{$id}/attachments", $this->hA())->json('attachments'));
    }

    public function test_devices_crud_and_scope(): void
    {
        $resp = $this->postJson('/api/maintenance/devices', [
            'type' => 'elevator', 'project_name' => '项目A', 'device_no' => 'DT-01',
            'load_kg' => 800, 'floors' => '12层12站', 'inspect_date' => now()->addDays(10)->toDateString(),
        ], $this->hA())->assertOk()->assertJson(['ok' => true]);
        $devId = (int) $resp->json('id');

        $list = $this->getJson('/api/maintenance/devices?type=elevator', $this->hP())->json('devices');
        $this->assertCount(1, $list);
        $this->assertSame('soon', $list[0]['inspect_status']);

        // 项目账号不能操作其他项目设备
        $this->postJson('/api/maintenance/devices', [
            'type' => 'elevator', 'project_name' => '项目B', 'device_no' => 'DT-99',
        ], $this->hP())->assertStatus(403);
        // 编号必填
        $this->postJson('/api/maintenance/devices', ['type' => 'fire', 'project_name' => '项目A'], $this->hA())
            ->assertStatus(400);

        $this->deleteJson("/api/maintenance/devices/{$devId}", [], $this->hA())->assertOk();
        $this->assertCount(0, $this->getJson('/api/maintenance/devices', $this->hA())->json('devices'));
    }

    public function test_records_flow(): void
    {
        Storage::fake('local');
        $id = $this->createContract();
        $this->postJson("/api/maintenance/contracts/{$id}/records", ['kind' => 'maintain', 'content' => ''], $this->hA())
            ->assertStatus(400);
        $resp = $this->postJson("/api/maintenance/contracts/{$id}/records", [
            'kind' => 'inspection', 'record_date' => '2026-06-01', 'content' => '年度检测', 'result' => '合格',
        ], $this->hA())->assertOk()->assertJson(['ok' => true]);
        $rid = (int) $resp->json('id');

        $this->post("/api/maintenance/records/{$rid}/attachment", [
            'file' => UploadedFile::fake()->create('年检报告.pdf', 100, 'application/pdf'),
        ], $this->hA())->assertOk();

        $records = $this->getJson("/api/maintenance/contracts/{$id}/records", $this->hA())->json('records');
        $this->assertCount(1, $records);
        $this->assertSame('年检', $records[0]['kind_label']);
        $this->assertCount(1, $records[0]['attachments']);

        $this->deleteJson("/api/maintenance/records/{$rid}", [], $this->hA())->assertOk();
        $this->assertCount(0, $this->getJson("/api/maintenance/contracts/{$id}/records", $this->hA())->json('records'));
        $this->assertSame(0, DB::table('maintenance_attachments')->where('record_id', $rid)->count());
    }

    public function test_report_graded_coverage_unit_cost(): void
    {
        $this->createContract(['party' => '甲公司', 'project_name' => '项目A', 'end_date' => now()->addDays(10)->toDateString()]);   // d30
        $this->createContract(['party' => '乙公司', 'project_name' => '项目C', 'end_date' => now()->subDays(5)->toDateString(),
            'start_date' => now()->subYear()->toDateString()]);                                              // expired
        $this->createContract(['party' => '丙公司', 'project_name' => '项目D', 'end_date' => null]);         // missing
        $this->createContract(['party' => '丁公司', 'project_name' => '项目E', 'end_date' => now()->addDays(200)->toDateString()]); // normal
        // 项目B 有消防设备但无有效合同 → 覆盖率异常
        DB::table('maintenance_devices')->insert([
            'legacy_id' => 1, 'type' => 'fire', 'project_name' => '项目B', 'device_no' => 'XF-01',
            'system_type' => '自动报警', 'cover_area_sqm' => 5000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $d = $this->getJson('/api/maintenance/fire_report?year=' . now()->year, $this->hA())->assertOk()->json();
        $dist = collect($d['state_dist'])->keyBy('label');
        $this->assertSame(1, $dist['30天内到期']['value']);
        $this->assertSame(1, $dist['已过期']['value']);
        $this->assertSame(1, $dist['无到期日']['value']);
        $this->assertSame(1, $dist['正常在保']['value']);
        // 异常清单仅含已过期+无到期日
        $this->assertCount(2, $d['abnormal_list']);
        // 单位成本：4 个项目行 + 平均值
        $this->assertCount(4, $d['unit_costs']);
        $this->assertNotNull($d['avg_unit_cost']);
        // 同比 / 合并总览 / 覆盖率
        $this->assertArrayHasKey('prev_amount', $d['yoy']);
        $this->assertSame(4, $d['combined']['fire_count']);
        $this->assertSame(1, $d['coverage']['uncovered_count']);
        $this->assertSame('项目B', $d['coverage']['uncovered'][0]['project']);
    }

    public function test_alert_command_dedup(): void
    {
        $this->createContract(['end_date' => now()->addDays(10)->toDateString()]);
        DB::table('maintenance_partners')->insert([
            'legacy_id' => 1, 'name' => '某消防公司', 'type' => 'fire',
            'qualification_expire' => now()->addDays(5)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('maintenance:scan-alerts');
        $adminId = DB::table('payroll_accounts')->where('username', 'hq_admin')->value('id');
        $projId = DB::table('payroll_accounts')->where('username', 'pm_a')->value('id');
        // admin：合同 + 资质 2 条；项目账号：仅合同 1 条
        $this->assertSame(2, DB::table('app_messages')->where('account_id', $adminId)->count());
        $this->assertSame(1, DB::table('app_messages')->where('account_id', $projId)->count());
        // 再跑一次不重复
        Artisan::call('maintenance:scan-alerts');
        $this->assertSame(2, DB::table('app_messages')->where('account_id', $adminId)->count());
        $this->assertSame(1, DB::table('app_messages')->where('account_id', $projId)->count());
    }

    public function test_export_xlsx(): void
    {
        $this->createContract(['contract_no' => 'HT-2026-001']);
        $resp = $this->get('/api/maintenance/contracts/export?type=fire', $this->hA());
        $resp->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers->get('Content-Type'));
    }
}
