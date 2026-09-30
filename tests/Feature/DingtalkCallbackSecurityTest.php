<?php

namespace Tests\Feature;

use App\Services\DingtalkCrypto;
use App\Services\DingtalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 钉钉回调 P0 安全修复（2026-09-30）：
 * - 未配置回调密钥时 503 拒绝
 * - 缺参数 400、验签失败 403（伪造请求不得触碰数据）
 * - 合法请求（AES 加密报文 + SHA1 签名）才分发事件，响应加密 success
 * - 回调部门树缓存：多次回调仅拉取一次（P1-4），部门事件使缓存失效
 * - runFullSync 事务包裹：中途失败全量回滚（P1-2）
 */
class DingtalkCallbackSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'testtoken123';
    private const AES_KEY = '1234567890123456789012345678901234567890123';
    private const APP_KEY = 'ding0000000000000001';

    private $dt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dt = $this->getMockBuilder(DingtalkService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig', 'getUserDetail', 'getAllDepartments', 'getAllUsers',
                           'getDismissedUsers', 'getDismissedUserInfos', 'getRosterData', 'getDeptPath'])
            ->getMock();
        $this->app->instance(DingtalkService::class, $this->dt);
        Cache::forget('dingtalk_depts_tree');
    }

    private function mockConfig(array $overrides = []): void
    {
        $cfg = array_merge([
            'app_key' => self::APP_KEY,
            'app_secret' => 'secret',
            'agent_id' => '1',
            'callback_token' => self::TOKEN,
            'callback_aes_key' => self::AES_KEY,
        ], $overrides);
        $this->dt->method('getConfig')->willReturn($cfg);
    }

    private function crypto(): DingtalkCrypto
    {
        return new DingtalkCrypto(self::TOKEN, self::AES_KEY, self::APP_KEY);
    }

    private function postEncrypted(string $plain, array $override = []): \Illuminate\Testing\TestResponse
    {
        $resp = json_decode($this->crypto()->encryptMsg($plain), true);
        $resp = array_merge($resp, $override);
        // 钉钉推送为 application/json，必须 postJson 保证 getContent() 可解析
        return $this->postJson("/api/dingtalk/callback?msg_signature={$resp['msg_signature']}&timestamp={$resp['timeStamp']}&nonce={$resp['nonce']}",
            ['encrypt' => $resp['encrypt']]);
    }

    private function deptTree(): array
    {
        return [
            1 => ['name' => '万城服务', 'parent_id' => 0, 'level' => 1],
            2 => ['name' => '测试项目A', 'parent_id' => 1, 'level' => 2],
        ];
    }

    // ─── 安全拒绝 ────────────────────────────────────────────

    public function test_unconfigured_callback_rejected_503(): void
    {
        $this->mockConfig(['callback_token' => '', 'callback_aes_key' => '']);
        $resp = json_decode($this->crypto()->encryptMsg('success'), true);
        $this->post("/api/dingtalk/callback?msg_signature={$resp['msg_signature']}&timestamp={$resp['timeStamp']}&nonce={$resp['nonce']}",
            ['encrypt' => $resp['encrypt']])
            ->assertStatus(503);
        $this->assertSame(0, DB::table('payroll_staff')->count());
    }

    public function test_missing_params_rejected_400(): void
    {
        $this->mockConfig();
        $this->post('/api/dingtalk/callback', ['encrypt' => 'x'])->assertStatus(400);
    }

    public function test_bad_signature_rejected_403(): void
    {
        $this->mockConfig();
        $resp = json_decode($this->crypto()->encryptMsg('success'), true);
        $this->postJson("/api/dingtalk/callback?msg_signature=deadbeef&timestamp={$resp['timeStamp']}&nonce={$resp['nonce']}",
            ['encrypt' => $resp['encrypt']])
            ->assertStatus(403);
        $this->assertSame(0, DB::table('payroll_staff')->count());
    }

    // ─── 合法回调 ────────────────────────────────────────────

    public function test_check_url_returns_encrypted_success(): void
    {
        $this->mockConfig();
        $resp = $this->postEncrypted('{"EventType":"check_url"}');
        $resp->assertOk();
        $body = $resp->json();
        foreach (['msg_signature', 'encrypt', 'timeStamp', 'nonce'] as $k) {
            $this->assertArrayHasKey($k, $body);
        }
        // 响应必须能被同配置解密出 success（钉钉校验规则）
        $plain = $this->crypto()->decryptMsg($body['msg_signature'], (string) $body['timeStamp'], $body['nonce'], $body['encrypt']);
        $this->assertSame('success', $plain);
    }

    public function test_user_add_org_creates_staff(): void
    {
        $this->mockConfig();
        $this->dt->method('getAllDepartments')->willReturn($this->deptTree());
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getUserDetail')->willReturnCallback(
            fn(string $uid) => $uid === 'u1' ? [
                'dept_id_list' => [2], 'hired_date' => 0, 'actual_confirm_date' => '',
                'name' => '张三', 'title' => '保安', 'mobile' => '13800000000',
            ] : null
        );

        // 官方协议：UserId 为数组（单事件多人）
        $this->postEncrypted('{"EventType":"user_add_org","UserId":["u1"]}')->assertOk();

        $row = DB::table('payroll_staff')->where('dingtalk_userid', 'u1')->first();
        $this->assertNotNull($row);
        $this->assertSame('张三', $row->name);
        $this->assertSame('测试项目A', $row->project_name);
    }

    public function test_user_leave_org_marks_resigned(): void
    {
        $this->mockConfig();
        DB::table('payroll_staff')->insert([
            'legacy_id' => 1, 'dingtalk_userid' => 'u9', 'name' => '李四',
            'project_name' => 'P', 'status' => '正式', 'fixed_monthly' => 0, 'base_salary' => 0,
            'deleted' => false, 'is_manager' => false, 'is_case_field' => false, 'person_type' => 'staff',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postEncrypted('{"EventType":"user_leave_org","UserId":["u9"]}')->assertOk();

        $row = DB::table('payroll_staff')->where('dingtalk_userid', 'u9')->first();
        $this->assertSame('离职', $row->status);
        $this->assertSame(date('Y-m-d'), substr((string) $row->resign_date, 0, 10));
    }

    public function test_dept_tree_cached_across_callbacks(): void
    {
        $this->mockConfig();
        $calls = 0;
        $this->dt->method('getAllDepartments')->willReturnCallback(function () use (&$calls) {
            $calls++;
            return $this->deptTree();
        });
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getUserDetail')->willReturnCallback(
            fn(string $uid) => [
                'dept_id_list' => [2], 'hired_date' => 0, 'actual_confirm_date' => '',
                'name' => '员工' . $uid, 'title' => '', 'mobile' => '',
            ]
        );

        $this->postEncrypted('{"EventType":"user_add_org","UserId":["u1"]}')->assertOk();
        $this->postEncrypted('{"EventType":"user_add_org","UserId":["u2"]}')->assertOk();

        // 两次回调仅拉取一次部门树（P1-4 缓存），且两人均已入库
        $this->assertSame(1, $calls);
        $this->assertSame(2, DB::table('payroll_staff')->count());
    }

    public function test_dept_event_invalidates_cache(): void
    {
        $this->mockConfig();
        Cache::put('dingtalk_depts_tree', $this->deptTree(), 1800);
        $this->postEncrypted('{"EventType":"org_dept_create"}')->assertOk();
        $this->assertFalse(Cache::has('dingtalk_depts_tree'));
    }

    // ─── 全量同步事务（P1-2） ─────────────────────────────────

    private function mockFullSyncHappyPath(): void
    {
        $this->dt->method('getAllDepartments')->willReturn($this->deptTree());
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getAllUsers')->willReturn([
            // getAllUsers 会为每个用户派生 _dept_ids（与真实返回一致）
            'u1' => ['name' => '张三', 'title' => '保安', '_dept_ids' => [2], 'hired_date' => 0, 'mobile' => '13800000000'],
        ]);
        $this->dt->method('getDismissedUsers')->willReturn([]);
        $this->dt->method('getRosterData')->willReturn(['u1' => ['性别' => '男']]);
    }

    public function test_runFullSync_rolls_back_on_failure(): void
    {
        $this->mockFullSyncHappyPath();
        $this->dt->method('getDismissedUsers')->willThrowException(new \RuntimeException('api down'));

        $this->expectException(\RuntimeException::class);
        app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();
    }

    public function test_runFullSync_rolls_back_leaves_no_partial_data(): void
    {
        $this->mockFullSyncHappyPath();
        $this->dt->method('getDismissedUsers')->willThrowException(new \RuntimeException('api down'));

        try {
            app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();
        } catch (\RuntimeException) {
        }

        // syncOneUser 与组织树写入必须已回滚
        $this->assertSame(0, DB::table('payroll_staff')->count());
        $this->assertSame(0, DB::table('org_nodes')->whereNotNull('dingtalk_dept_id')->count());
    }

    public function test_runFullSync_success_persists_data(): void
    {
        $this->mockFullSyncHappyPath();

        $report = app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();

        $this->assertSame(1, $report['new']);
        $this->assertSame(1, $report['roster']);
        $row = DB::table('payroll_staff')->where('dingtalk_userid', 'u1')->first();
        $this->assertNotNull($row);
        // 花名册字段已应用
        $data = json_decode((string) $row->data, true);
        $this->assertSame('男', $data['gender'] ?? null);
    }

    public function test_runFullSync_repairs_pending_placeholder_rows(): void
    {
        // 历史「待同步」占位行：详情接口报错期间落库的离职人员
        DB::table('payroll_staff')->insert([
            'legacy_id' => 1, 'dingtalk_userid' => 'u9', 'name' => '待同步',
            'project_name' => '未分配项目', 'status' => '离职', 'resign_date' => null,
            'fixed_monthly' => 0, 'base_salary' => 0,
            'deleted' => false, 'is_manager' => false, 'is_case_field' => false, 'person_type' => 'staff',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->dt->method('getAllDepartments')->willReturn($this->deptTree());
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getAllUsers')->willReturn([]);
        $this->dt->method('getDismissedUsers')->willReturn(['u9' => true]);
        // 详情接口修复后能返回真实姓名与最后工作日
        $this->dt->method('getDismissedUserInfos')->willReturn([
            'u9' => ['name' => '王五', 'last_work_date' => '2026-09-15', 'main_dept_id' => 2],
        ]);
        $this->dt->method('getRosterData')->willReturn([]);

        $report = app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();

        $this->assertSame(1, $report['repair']);
        $row = DB::table('payroll_staff')->where('dingtalk_userid', 'u9')->first();
        $this->assertSame('王五', $row->name);
        $this->assertSame('2026-09-15', substr((string) $row->resign_date, 0, 10));
        $this->assertSame('测试项目A', $row->project_name);
    }

    public function test_runFullSync_offboard_new_row_keeps_person_type_null(): void
    {
        // 离职列表新建行：从未经历在职花名册同步，岗位职级未知，不得伪造为 'staff'
        $this->dt->method('getAllDepartments')->willReturn($this->deptTree());
        $this->dt->method('getDeptPath')->willReturn(['万城服务', '测试项目A']);
        $this->dt->method('getAllUsers')->willReturn([]);
        $this->dt->method('getDismissedUsers')->willReturn(['u9' => true]);
        $this->dt->method('getDismissedUserInfos')->willReturn([
            'u9' => ['name' => '张传彩', 'last_work_date' => '2026-09-21', 'main_dept_id' => 2],
        ]);
        $this->dt->method('getRosterData')->willReturn([]);

        $report = app(\App\Http\Controllers\Api\DingtalkCallbackController::class)->runFullSync();

        $this->assertSame(1, $report['offboard_new']);
        $row = DB::table('payroll_staff')->where('dingtalk_userid', 'u9')->first();
        $this->assertNotNull($row);
        $this->assertSame('离职', $row->status);
        $this->assertNull($row->person_type);
    }


}
