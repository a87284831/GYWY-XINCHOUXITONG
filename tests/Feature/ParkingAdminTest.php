<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 停车场登记管理端：门管理（仅 admin）、字段配置、报表范围强制、导出（v2026.11.24.1）。
 */
class ParkingAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $projA;
    private int $projB;
    private string $adminToken = 'tok-admin';
    private string $projToken = 'tok-proj';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projA = DB::table('payroll_projects')->insertGetId([
            'name' => 'A 小区', 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->projB = DB::table('payroll_projects')->insertGetId([
            'name' => 'B 小区', 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 1, 'username' => 'admin', 'name' => '总部管理员', 'role' => 'admin',
            'project_name' => null, 'password_hash' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 2, 'username' => 'song', 'name' => 'A 小区账号', 'role' => 'staff',
            'project_name' => 'A 小区', 'password_hash' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHour());
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHour());
    }

    private function gate(array $over = []): int
    {
        return DB::table('parking_gates')->insertGetId(array_merge([
            'project_id' => $this->projA, 'name' => '东门', 'entry_token' => 'tk_' . uniqid(),
            'enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function reg(int $projectId, string $projectName, array $over = []): void
    {
        DB::table('parking_registrations')->insert(array_merge([
            'gate_id' => 1, 'project_id' => $projectId, 'gate_name' => '东门',
            'project_name' => $projectName, 'plate' => '鲁Q1000' . random_int(0, 9),
            'name' => '司机', 'phone' => '13800000000', 'visit_target' => '1-101',
            'reason' => '拜访', 'extra' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    public function test_admin_endpoints_require_token(): void
    {
        $this->getJson('/api/parking/gates')->assertStatus(401);
        $this->getJson('/api/parking/stats')->assertStatus(401);
        $this->getJson('/api/parking/list')->assertStatus(401);
    }

    public function test_gate_and_config_management_forbidden_for_project_account(): void
    {
        foreach (['get:/api/parking/gates', 'get:/api/parking/config', 'post:/api/parking/config'] as $pair) {
            [$m, $u] = explode(':', $pair);
            $this->json($m, $u, [], ['X-Token' => $this->projToken])->assertStatus(403);
        }
        $this->postJson('/api/parking/gates', ['project_id' => $this->projA, 'name' => '南门'], ['X-Token' => $this->projToken])
            ->assertStatus(403);
    }

    public function test_gate_create_list_and_duplicate_guard(): void
    {
        $this->postJson('/api/parking/gates', ['project_id' => $this->projA, 'name' => '东门'], ['X-Token' => $this->adminToken])
            ->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/api/parking/gates', ['X-Token' => $this->adminToken])
            ->assertOk()->assertJsonCount(1, 'gates');
        $gate = $this->getJson('/api/parking/gates', ['X-Token' => $this->adminToken])->json('gates.0');
        $this->assertSame('A 小区 · 东门', $gate['label']);
        $this->assertStringContainsString('/parking.html?p=', $gate['entry_url']);
        // 同小区同名拒绝
        $this->postJson('/api/parking/gates', ['project_id' => $this->projA, 'name' => '东门'], ['X-Token' => $this->adminToken])
            ->assertStatus(422);
        // 其他小区可同名
        $this->postJson('/api/parking/gates', ['project_id' => $this->projB, 'name' => '东门'], ['X-Token' => $this->adminToken])
            ->assertOk();
    }

    public function test_gate_reset_token_invalidates_old_url(): void
    {
        $id = $this->gate(['entry_token' => 'old-token-123']);
        $res = $this->postJson('/api/parking/gates', ['id' => $id, 'project_id' => $this->projA, 'name' => '东门',
            'enabled' => true, 'reset_token' => true], ['X-Token' => $this->adminToken])->assertOk();
        $newUrl = $res->json('gate.entry_url');
        $newToken = substr($newUrl, strrpos($newUrl, '=') + 1);
        $this->assertNotSame('old-token-123', $newToken);
        $this->getJson('/api/parking/entry?p=old-token-123')->assertStatus(404);
        $this->getJson('/api/parking/entry?p=' . $newToken)->assertOk();
    }

    public function test_gate_delete_disables_entry_but_keeps_records(): void
    {
        $id = $this->gate(['entry_token' => 'del-token-1']);
        $this->reg($this->projA, 'A 小区');
        $this->postJson('/api/parking/gates/delete', ['id' => $id], ['X-Token' => $this->adminToken])->assertOk();
        $this->getJson('/api/parking/entry?p=del-token-1')->assertStatus(404);
        $this->assertSame(1, DB::table('parking_registrations')->count(), '删门不删历史登记');
    }

    public function test_config_save_and_get(): void
    {
        $this->postJson('/api/parking/config', [
            'reasons' => ['拜访', '施工'],
            'fields' => [
                ['label' => '随车人数', 'type' => 'number', 'required' => false, 'enabled' => true],
                ['label' => '来访目的', 'type' => 'radio', 'required' => true, 'enabled' => true, 'options' => ['家庭访问', '装修施工', '']],
            ],
        ], ['X-Token' => $this->adminToken])->assertOk()->assertJsonPath('ok', true);
        $cfg = $this->getJson('/api/parking/config', ['X-Token' => $this->adminToken])->json('config');
        $this->assertSame(['拜访', '施工'], $cfg['reasons']);
        $this->assertCount(2, $cfg['fields']);
        $this->assertSame(['家庭访问', '装修施工'], $cfg['fields'][1]['options'], '空选项被过滤');
        $this->assertSame(8, strlen($cfg['fields'][0]['key']));
        // 司机端 entry 同步生效
        $id = $this->gate(['entry_token' => 'cfg-token-1']);
        $entry = $this->getJson('/api/parking/entry?p=cfg-token-1')->assertOk();
        $this->assertSame(['拜访', '施工'], $entry->json('config.reasons'));
    }

    public function test_stats_scope_forced_for_project_account(): void
    {
        $this->reg($this->projA, 'A 小区');
        $this->reg($this->projA, 'A 小区');
        $this->reg($this->projB, 'B 小区');
        $admin = $this->getJson('/api/parking/stats', ['X-Token' => $this->adminToken])->json('kpi');
        $this->assertSame(3, $admin['today']);
        $proj = $this->getJson('/api/parking/stats', ['X-Token' => $this->projToken])->json('kpi');
        $this->assertSame(2, $proj['today'], '项目账号只能看到自己小区');
        $this->assertSame(2, $proj['total']);
    }

    public function test_list_scope_and_filters(): void
    {
        $this->reg($this->projA, 'A 小区');
        $this->reg($this->projB, 'B 小区');
        // 项目账号传 project_id=B 也被强制为 A
        $rows = $this->getJson('/api/parking/list?project_id=' . $this->projB, ['X-Token' => $this->projToken])
            ->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('A 小区', $rows[0]['project_name']);
        // admin 日期过滤
        $this->getJson('/api/parking/list?from=2030-01-01&to=2030-01-02', ['X-Token' => $this->adminToken])
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/parking/list', ['X-Token' => $this->adminToken])->assertOk()->assertJsonPath('total', 2);
    }

    public function test_export_streams_xlsx_with_config_columns(): void
    {
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'parking_config.json',
            'payload' => json_encode(['reasons' => ['拜访'],
                'fields' => [['key' => 'ab12cd34', 'label' => '随车人数', 'type' => 'number', 'required' => false, 'enabled' => true, 'options' => []]]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reg($this->projA, 'A 小区', ['plate' => '鲁Q88888', 'extra' => json_encode(['ab12cd34' => '3'])]);
        $res = $this->get('/api/parking/export', ['X-Token' => $this->adminToken]);
        $res->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('content-disposition'));
        $content = $res->streamedContent();
        $this->assertSame('PK', substr($content, 0, 2));
        // 用 PhpSpreadsheet 回读，校验列结构与自定义字段取值
        $file = sys_get_temp_dir() . '/parking_export_test.xlsx';
        file_put_contents($file, $content);
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
            $this->assertSame('登记时间', $sheet->getCell('A1')->getValue());
            $this->assertSame('车牌号', $sheet->getCell('D1')->getValue());
            $this->assertSame('随车人数', $sheet->getCell('I1')->getValue());
            $this->assertSame('A 小区', $sheet->getCell('B2')->getValue());
            $this->assertSame('鲁Q88888', $sheet->getCell('D2')->getValue());
            $this->assertSame('3', (string) $sheet->getCell('I2')->getValue());
        } finally {
            @unlink($file);
        }
    }
}
