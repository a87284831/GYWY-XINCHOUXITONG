<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 系统更新日志 /api/changelog：
 *  - 未登录 401
 *  - 登录后返回条目列表（version/date/commit/items[]，items 含 category/text）
 *  - 条目按版本倒序（最新在前），version 字段=最新版本号
 *  - changelog.json 缺失/损坏时必须 500 报错，禁止静默返回空（用户规则：数据为空显式报错）
 */
class ChangelogApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->token, $adminId, now()->addHours(8));
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/changelog')->assertStatus(401);
    }

    public function test_returns_entries_with_expected_structure(): void
    {
        $resp = $this->getJson('/api/changelog', ['X-Token' => $this->token]);
        $resp->assertOk()->assertJson(['ok' => true]);

        $version = $resp->json('version');
        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^v\d{4}\.\d{1,2}\.\d{1,2}\.\d+$/', $version, '版本号格式 vYYYY.M.D.N');

        $items = $resp->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items, 'changelog.json 至少应有一条记录');
        $this->assertSame($version, $items[0]['version'], '首条即最新版本');

        foreach ($items as $entry) {
            $this->assertArrayHasKey('version', $entry);
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('commit', $entry);
            $this->assertArrayHasKey('items', $entry);
            $this->assertIsArray($entry['items']);
            foreach ($entry['items'] as $it) {
                $this->assertContains($it['category'], ['新增', '修复', '优化'], '分类限定 新增/修复/优化');
                $this->assertNotEmpty($it['text'], '条目正文不能为空');
            }
        }
    }

    public function test_limit_param_works(): void
    {
        $resp = $this->getJson('/api/changelog?limit=1', ['X-Token' => $this->token]);
        $resp->assertOk();
        $this->assertCount(1, $resp->json('items'));
    }

    public function test_missing_changelog_file_returns_500(): void
    {
        $path = base_path('changelog.json');
        $bak = $path . '.bak';
        rename($path, $bak);
        try {
            $resp = $this->getJson('/api/changelog', ['X-Token' => $this->token]);
            $resp->assertStatus(500)->assertJson(['ok' => false]);
        } finally {
            rename($bak, $path);
        }
    }
}
