<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 停车场登记司机端公开接口：entry / register（v2026.11.24.1）。
 */
class ParkingPublicTest extends TestCase
{
    use RefreshDatabase;

    private int $projectId;
    private string $gateToken = 'gate-token-aaa';
    private string $disabledToken = 'gate-token-bbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectId = DB::table('payroll_projects')->insertGetId([
            'name' => '罗庄春暖花开', 'status' => '启用',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('parking_gates')->insert([
            ['project_id' => $this->projectId, 'name' => '东门', 'entry_token' => $this->gateToken, 'enabled' => 1,
                'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $this->projectId, 'name' => '西门', 'entry_token' => $this->disabledToken, 'enabled' => 0,
                'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'parking_config.json',
            'payload' => json_encode([
                'reasons' => ['拜访', '送货', '施工', '快递', '其他'],
                'fields' => [
                    ['key' => 'ab12cd34', 'label' => '随车人数', 'type' => 'number', 'required' => false, 'enabled' => true, 'options' => []],
                    ['key' => 'ef56gh78', 'label' => '来访目的', 'type' => 'radio', 'required' => true, 'enabled' => true, 'options' => ['家庭访问', '装修施工']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'p' => $this->gateToken,
            'plate' => '鲁Q12345',
            'name' => '张三',
            'phone' => '13800001111',
            'visit_target' => '3-2-101 王先生',
            'reason' => '拜访',
            'extra' => ['ab12cd34' => '2', 'ef56gh78' => '家庭访问'],
        ], $over);
    }

    public function test_entry_returns_project_gate_and_config(): void
    {
        $res = $this->getJson('/api/parking/entry?p=' . $this->gateToken);
        $res->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('project_name', '罗庄春暖花开')
            ->assertJsonPath('gate_name', '东门');
        $cfg = $res->json('config');
        $this->assertSame(['拜访', '送货', '施工', '快递', '其他'], $cfg['reasons']);
        $this->assertCount(2, $cfg['fields']);
        $this->assertTrue($cfg['fields'][1]['required']);
    }

    public function test_entry_requires_token(): void
    {
        $this->getJson('/api/parking/entry')
            ->assertStatus(400)->assertJsonPath('code', 'ENTRY_REQUIRED');
    }

    public function test_entry_unknown_or_disabled_gate_is_404(): void
    {
        $this->getJson('/api/parking/entry?p=no-such-token')
            ->assertStatus(404)->assertJsonPath('code', 'GATE_DISABLED');
        $this->getJson('/api/parking/entry?p=' . $this->disabledToken)
            ->assertStatus(404)->assertJsonPath('code', 'GATE_DISABLED');
    }

    public function test_register_success_persists_row(): void
    {
        $this->postJson('/api/parking/register', $this->payload())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('plate', '鲁Q12345')
            ->assertJsonPath('project_name', '罗庄春暖花开')
            ->assertJsonPath('gate_name', '东门');
        $row = DB::table('parking_registrations')->where('plate', '鲁Q12345')->first();
        $this->assertNotNull($row);
        $this->assertSame('东门', $row->gate_name);
        $extra = json_decode($row->extra, true);
        $this->assertSame('2', $extra['ab12cd34']);
        $this->assertSame('家庭访问', $extra['ef56gh78']);
    }

    public function test_register_plate_normalized_and_stripped(): void
    {
        $this->postJson('/api/parking/register', $this->payload(['plate' => '鲁q·12345']))
            ->assertOk();
        $this->assertSame(1, DB::table('parking_registrations')->where('plate', '鲁Q12345')->count());
    }

    public function test_register_rejects_invalid_plate_phone_reason(): void
    {
        $this->postJson('/api/parking/register', $this->payload(['plate' => '鲁Q12']))
            ->assertStatus(422);
        $this->postJson('/api/parking/register', $this->payload(['phone' => '12345']))
            ->assertStatus(422);
        $this->postJson('/api/parking/register', $this->payload(['reason' => '不明']))
            ->assertStatus(422);
        $this->postJson('/api/parking/register', $this->payload(['name' => '']))
            ->assertStatus(422);
        $this->assertSame(0, DB::table('parking_registrations')->count());
    }

    public function test_register_validates_custom_field_types(): void
    {
        // 必填单选缺失
        $p = $this->payload();
        unset($p['extra']['ef56gh78']);
        $this->postJson('/api/parking/register', $p)->assertStatus(422);
        // 单选值非法
        $this->postJson('/api/parking/register', $this->payload(['extra' => [
            'ab12cd34' => '2', 'ef56gh78' => '看风景',
        ]]))->assertStatus(422);
        // 数字字段非数字
        $this->postJson('/api/parking/register', $this->payload(['extra' => [
            'ab12cd34' => '两', 'ef56gh78' => '家庭访问',
        ]]))->assertStatus(422);
        $this->assertSame(0, DB::table('parking_registrations')->count());
    }

    public function test_register_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/parking/register', $this->payload())->assertOk();
        }
        $this->postJson('/api/parking/register', $this->payload())
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_register_on_disabled_gate_is_404(): void
    {
        $this->postJson('/api/parking/register', $this->payload(['p' => $this->disabledToken]))
            ->assertStatus(404)->assertJsonPath('code', 'GATE_DISABLED');
        Cache::flush();
    }
}
