<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 钉钉配置 API（2026-09-30 前端配置卡片配套）：
 * - admin 可保存 app_key/app_secret/agent_id/callback_token/callback_aes_key
 * - getConfig 返回掩码（secret/aes_key 不出明文）
 * - 非 admin 403；空载荷 400
 */
class DingtalkConfigApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9101, 'username' => 'cfg_admin', 'name' => '配置管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('c', 64);
        Cache::put('payroll_api_token:' . $this->token, $id, now()->addHours(8));
    }

    public function test_admin_saves_and_gets_masked_config(): void
    {
        $this->post('/api/dingtalk/config', [
            'app_key' => 'dingtest',
            'app_secret' => 'sec1234567890abcd',
            'agent_id' => '999',
            'callback_token' => 'cbtoken',
            'callback_aes_key' => str_repeat('k', 43),
        ], ['X-Token' => $this->token])->assertOk()->assertJsonPath('ok', true);

        $cfg = $this->get('/api/dingtalk/config', ['X-Token' => $this->token])->assertOk()->json('config');
        $this->assertSame('dingtest', $cfg['app_key']);
        $this->assertSame('999', $cfg['agent_id']);
        $this->assertSame('cbtoken', $cfg['callback_token']);
        // 明文密钥不出接口，仅掩码
        $this->assertArrayNotHasKey('app_secret', $cfg);
        $this->assertArrayNotHasKey('callback_aes_key', $cfg);
        $this->assertStringContainsString('****', $cfg['app_secret_masked']);
        $this->assertStringContainsString('****', $cfg['callback_aes_key_masked']);

        $saved = DB::table('sys_dingtalk_config')->pluck('config_value', 'config_key')->all();
        $this->assertSame(str_repeat('k', 43), $saved['callback_aes_key']);
    }

    public function test_empty_payload_rejected(): void
    {
        $this->post('/api/dingtalk/config', [], ['X-Token' => $this->token])->assertStatus(400);
    }

    public function test_non_admin_rejected(): void
    {
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9102, 'username' => 'cfg_user', 'name' => '普通用户',
            'role' => 'project', 'project_name' => 'P', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tok = str_repeat('u', 64);
        Cache::put('payroll_api_token:' . $tok, $id, now()->addHours(8));

        $this->post('/api/dingtalk/config', ['app_key' => 'x'], ['X-Token' => $tok])->assertStatus(403);
    }
}
