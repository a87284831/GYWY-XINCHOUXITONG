<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 维保孤儿记录/附件（合同已不存在）不得绕过项目归属校验。
 */
class MaintenanceOrphanGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $projToken;

    protected function setUp(): void
    {
        parent::setUp();
        $projId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9701, 'username' => 'mt_proj', 'name' => '甲项目维保',
            'role' => 'project', 'project_name' => '甲项目', 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->projToken = str_repeat('m', 64);
        Cache::put('payroll_api_token:' . $this->projToken, $projId, now()->addHours(8));
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('maintenance_records')->insert([
            'legacy_id' => 1, 'contract_id' => 999, 'record_date' => '2026-10-01',
            'kind' => 'maintain', 'content' => '孤儿记录', 'result' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('maintenance_attachments')->insert([
            'legacy_id' => 1, 'contract_id' => 999, 'record_id' => null,
            'kind' => 'other', 'name' => '孤儿附件.pdf', 'file_name' => 'orphan.pdf',
            'size' => 0, 'uploaded_by' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_orphan_record_cannot_be_updated_or_deleted(): void
    {
        $this->putJson('/api/maintenance/records/1', ['content' => '篡改'], ['X-Token' => $this->projToken])
            ->assertStatus(404);
        $this->deleteJson('/api/maintenance/records/1', [], ['X-Token' => $this->projToken])
            ->assertStatus(404);
        $this->assertSame('孤儿记录', DB::table('maintenance_records')->where('legacy_id', 1)->value('content'));
    }

    public function test_orphan_attachment_cannot_be_deleted(): void
    {
        $this->deleteJson('/api/maintenance/attachments/1', [], ['X-Token' => $this->projToken])
            ->assertStatus(404);
        $this->assertSame(1, DB::table('maintenance_attachments')->where('legacy_id', 1)->count());
    }
}
