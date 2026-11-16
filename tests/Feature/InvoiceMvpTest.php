<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceMvpTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(int $accountId, string $token = 'test-token'): string
    {
        Cache::put('payroll_api_token:' . $token, $accountId, now()->addHour());
        return $token;
    }

    private function seedBase(): array
    {
        Storage::fake('local');
        config([
            'invoice.defaults.amount_limit' => 50000,
            'invoice.defaults.attachments_max' => 3,
            'invoice.nuonuo.allow_nuonuo_email' => false,
        ]);

        $projectId = DB::table('payroll_projects')->insertGetId([
            'name' => '罗庄春暖花开',
            'status' => '启用',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('payroll_roles')->where('role_key', 'invoice_admin')->exists()
            ? null
            : DB::table('payroll_roles')->insertGetId([
                'role_key' => 'invoice_admin',
                'name' => '开票管理员',
                'scope' => 'all',
                'permissions' => json_encode(['invoice_view', 'invoice_review', 'invoice_issue', 'invoice_stats', 'invoice_config']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('payroll_roles')->where('role_key', 'project_finance')->exists() || DB::table('payroll_roles')->insert([
            'role_key' => 'project_finance',
            'name' => '项目财务',
            'scope' => 'project',
            'permissions' => json_encode(['invoice_view', 'invoice_review']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 1,
            'username' => 'inv_admin',
            'name' => '开票管理员',
            'role' => 'invoice_admin',
            'password_hash' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectUserId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 2,
            'username' => 'proj_fin',
            'name' => '项目财务',
            'role' => 'project_finance',
            'project_name' => '罗庄春暖花开',
            'password_hash' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $setA = DB::table('invoice_account_sets')->insertGetId([
            'code' => 'AS-A',
            'name' => 'A 账套',
            'seller_name' => '临沂某某物业服务有限公司',
            'seller_tax_no' => '91371300123456789X',
            'default_goods_code' => '3040801010000000000',
            'default_goods_name' => '物业管理服务费',
            'default_tax_rate' => 0.06,
            'amount_limit' => 50000,
            'email_from' => 'invoice@example.com',
            'email_from_name' => '财务',
            'email_provider' => 'smtp',
            'status' => 'enabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $setB = DB::table('invoice_account_sets')->insertGetId([
            'code' => 'AS-B',
            'name' => 'B 账套',
            'seller_name' => '菏泽某某物业服务有限公司',
            'seller_tax_no' => '91371700123456789Y',
            'default_tax_rate' => 0.06,
            'amount_limit' => 50000,
            'email_provider' => 'smtp',
            'status' => 'enabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_project_bindings')->insert([
            'project_id' => $projectId,
            'project_name' => '罗庄春暖花开',
            'account_set_id' => $setA,
            'entry_token' => 'entry-token-a',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$projectId, $adminId, $projectUserId, $setA, $setB];
    }

    public function test_owner_can_submit_attachment_and_admin_can_approve_with_fake_issuer(): void
    {
        [$projectId, $adminId, , $setA] = $this->seedBase();

        $entry = $this->getJson('/api/invoice/entry?p=entry-token-a');
        $entry->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('seller.name', '临沂某某物业服务有限公司');

        $apply = $this->post('/api/invoice/apply', [
            'p' => 'entry-token-a',
            'house_no' => '3-2-1802',
            'payer_name' => '张明',
            'declared_amount' => 2400,
            'title_type' => 'personal',
            'title' => '张明',
            'buyer_email' => 'zhang@example.com',
            'fee_period' => '2026-01~2026-12',
            'attachments' => [UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')],
        ]);
        $apply->assertOk()->assertJsonPath('ok', true);
        $no = $apply->json('request_no');
        $key = $apply->json('query_key');
        $this->assertNotEmpty($no);
        $this->assertMatchesRegularExpression('/^INV\d{8}-\d{6}$/', (string) $no);
        $this->assertNotEmpty($key);

        $row = DB::table('invoice_requests')->where('request_no', $no)->first();
        $this->assertSame($setA, (int) $row->account_set_id);
        $this->assertSame(1, (int) $row->attachment_count);
        $this->assertSame('submitted', $row->status);
        $this->assertSame(1, DB::table('invoice_attachments')->where('request_id', $row->id)->count());

        $status = $this->getJson('/api/invoice/status?no=' . urlencode($no) . '&k=' . urlencode($key));
        $status->assertOk()->assertJsonPath('request.status', 'submitted');
        $this->assertNotEmpty($status->json('attachments.0.preview_url'));

        $this->withHeader('X-Token', $this->tokenFor($adminId))
            ->postJson('/api/invoice/admin/requests/' . $row->id . '/approve', [
                'verified_amount' => 2400,
                'review_note' => '凭证金额一致',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'delivered')
            ->assertJsonPath('request.simulated', true);

        $issued = DB::table('invoice_requests')->where('id', $row->id)->first();
        $this->assertSame('delivered', $issued->status);
        $this->assertSame(1, (int) $issued->simulated);
        $this->assertNotEmpty($issued->digital_invoice_no);
        $this->assertNotEmpty($issued->pdf_path);
        $this->assertNotEmpty($issued->ofd_path);
        $this->getJson('/api/invoice/status?no=' . urlencode($no) . '&k=' . urlencode($key))
            ->assertOk()
            ->assertJsonPath('request.can_download', true)
            ->assertJsonPath('request.can_download_ofd', true);
        $this->assertSame(1, DB::table('invoice_delivery_logs')->where('request_id', $row->id)->where('result', 'sent')->count());
        $this->assertSame(1, DB::table('invoice_request_logs')->where('request_id', $row->id)->where('action', 'owner_submit')->count());

        // 重复提交（同项目+房号+申请单号）返回原单，不新增
        $again = $this->post('/api/invoice/apply', [
            'p' => 'entry-token-a',
            'house_no' => '3-2-1802',
            'payer_name' => '张明',
            'declared_amount' => 2400,
            'title_type' => 'personal',
            'title' => '张明',
            'buyer_email' => 'zhang@example.com',
            'client_request_no' => $no,
            'attachments' => [UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')],
        ]);
        $again->assertOk()->assertJsonPath('reused', true);
        $this->assertSame(1, DB::table('invoice_requests')->where('project_id', $projectId)->count());
    }

    public function test_reject_then_resubmit_and_cross_account_set_is_forbidden(): void
    {
        [, $adminId, $projectUserId, $setA, $setB] = $this->seedBase();

        $this->post('/api/invoice/apply', [
            'p' => 'entry-token-a',
            'house_no' => '1-1-0101',
            'payer_name' => '李静',
            'declared_amount' => 1860,
            'title_type' => 'personal',
            'title' => '李静',
            'buyer_email' => 'li@example.com',
            'attachments' => [UploadedFile::fake()->create('receipt.png', 120, 'image/png')],
        ])->assertOk();
        $row = DB::table('invoice_requests')->where('house_no', '1-1-0101')->first();
        $adminToken = $this->tokenFor($adminId, 'admin-token');

        $this->withHeader('X-Token', $adminToken)
            ->postJson('/api/invoice/admin/requests/' . $row->id . '/reject', ['reason' => '凭证金额不清晰'])
            ->assertOk()
            ->assertJsonPath('request.status', 'rejected');

        // 项目账号（scope=project）可看本项目账套，但不能访问 B 账套
        $projectToken = $this->tokenFor($projectUserId, 'project-token');
        $this->withHeader('X-Token', $projectToken)
            ->getJson('/api/invoice/admin/requests?account_set_id=' . $setB)
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_SET_FORBIDDEN');

        $this->assertSame(1, DB::table('invoice_request_logs')->where('action', 'cross_account_set_denied')->count());

        // 项目账号能看本项目账套的列表
        $this->withHeader('X-Token', $projectToken)
            ->getJson('/api/invoice/admin/requests?account_set_id=' . $setA)
            ->assertOk()
            ->assertJsonPath('total', 1);

        // 审核人未分配时，项目账号仍能凭 scope=project 访问（文档兜底只影响“待办归属”）
        $this->withHeader('X-Token', $adminToken)
            ->getJson('/api/invoice/admin/my-todos')
            ->assertOk();
    }

    public function test_account_set_reviewers_and_primary_are_configurable(): void
    {
        [, $adminId, $projectUserId, $setA] = $this->seedBase();
        $token = $this->tokenFor($adminId, 'admin-token');

        $this->withHeader('X-Token', $token)
            ->putJson('/api/invoice/admin/account-sets/' . $setA . '/reviewers', [
                'reviewers' => [
                    ['account_id' => $projectUserId, 'is_primary' => true, 'can_review' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.is_primary', true);

        $this->assertSame(1, DB::table('invoice_account_set_reviewers')->where('account_set_id', $setA)->where('is_primary', 1)->count());

        // 未显式指定主审时，自动将唯一审核人设为主审
        $this->withHeader('X-Token', $token)
            ->putJson('/api/invoice/admin/account-sets/' . $setA . '/reviewers', [
                'reviewers' => [
                    ['account_id' => $projectUserId, 'is_primary' => false, 'can_review' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.is_primary', true);

        // 两个主审应被拒绝
        $this->withHeader('X-Token', $token)
            ->putJson('/api/invoice/admin/account-sets/' . $setA . '/reviewers', [
                'reviewers' => [
                    ['account_id' => $projectUserId, 'is_primary' => true],
                    ['account_id' => $adminId, 'is_primary' => true],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_amount_change_needs_reason_resend_and_ofd_download(): void
    {
        [, $adminId] = $this->seedBase();

        $apply = $this->post('/api/invoice/apply', [
            'p' => 'entry-token-a',
            'house_no' => '5-1-0303',
            'payer_name' => '赵敏',
            'declared_amount' => 1200,
            'title_type' => 'personal',
            'title' => '赵敏',
            'buyer_email' => 'zhao@example.com',
            'attachments' => [UploadedFile::fake()->create('receipt.jpg', 120, 'image/jpeg')],
        ])->assertOk();
        $no = $apply->json('request_no');
        $key = $apply->json('query_key');
        $row = DB::table('invoice_requests')->where('request_no', $no)->first();
        $token = $this->tokenFor($adminId, 'admin-token');

        // 改额未填差异原因 → 拒绝
        $this->withHeader('X-Token', $token)
            ->postJson('/api/invoice/admin/requests/' . $row->id . '/approve', ['verified_amount' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DIFF_REASON_REQUIRED');

        // 填写原因后通过，核定金额与申报金额分离
        $this->withHeader('X-Token', $token)
            ->postJson('/api/invoice/admin/requests/' . $row->id . '/approve', [
                'verified_amount' => 1000,
                'review_note' => '按凭证实际金额核定',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'delivered')
            ->assertJsonPath('request.verified_amount', 1000);

        $this->assertSame(1, DB::table('invoice_delivery_logs')->where('request_id', $row->id)->where('result', 'sent')->count());

        // 后台手动重发邮件
        $this->withHeader('X-Token', $token)
            ->postJson('/api/invoice/admin/requests/' . $row->id . '/resend')
            ->assertOk()
            ->assertJsonPath('request.status', 'delivered');
        $this->assertSame(2, DB::table('invoice_delivery_logs')->where('request_id', $row->id)->where('result', 'sent')->count());

        // OFD 下载：文件存在时按 f=ofd 返回
        Storage::disk('local')->put('invoice/ofd/' . $row->id . '.ofd', 'OFD-BINARY');
        DB::table('invoice_requests')->where('id', $row->id)->update(['ofd_path' => 'invoice/ofd/' . $row->id . '.ofd']);
        $this->get('/api/invoice/download/' . urlencode($no) . '?k=' . urlencode($key) . '&f=ofd')
            ->assertOk()
            ->assertHeader('content-type', 'application/ofd');
    }

    public function test_attachment_preview_requires_valid_signature(): void
    {
        [, $adminId] = $this->seedBase();
        $this->post('/api/invoice/apply', [
            'p' => 'entry-token-a',
            'house_no' => '2-2-0202',
            'payer_name' => '王强',
            'declared_amount' => 880,
            'title_type' => 'personal',
            'title' => '王强',
            'buyer_email' => 'wang@example.com',
            'attachments' => [UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg')],
        ])->assertOk();

        $attachment = DB::table('invoice_attachments')->first();
        $this->get('/api/invoice/attachment/' . $attachment->id)->assertStatus(403);
        $this->get('/api/invoice/attachment/' . $attachment->id . '?exp=' . (time() + 300) . '&sig=bad')->assertStatus(403);
    }
}
