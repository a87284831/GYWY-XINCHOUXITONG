<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Feature\Concerns\SeedsPerformancePlan;

/**
 * 管理员撤销归档（done→approve，审批链重置、自评/核查分/附件保留）；
 * 考核单详情数据级查看权限。
 */
class PerformanceReopenAndViewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPerformancePlan;

    private string $adminToken;
    private string $selfToken;
    private string $leaderToken;
    private string $reporterToken;
    private string $founderToken;
    private string $outsiderToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStaff(101, '张三');
        $this->seedStaff(201, '李经理');
        $this->seedStaff(301, '王核查');
        $this->seedStaff(404, '赵无关');
        $this->adminToken = $this->seedAccount(9001, null, 'admin');
        $this->selfToken = $this->seedAccount(1001, 101, 'staff');
        $this->leaderToken = $this->seedAccount(2001, 201, 'staff');
        $this->reporterToken = $this->seedAccount(3001, 301, 'staff');
        $this->founderToken = $this->seedAccount(5005, null, 'staff');
        $this->outsiderToken = $this->seedAccount(4004, 404, 'staff');
    }

    private function headers(string $token): array { return ['X-Token' => $token]; }

    private function donePlan(): int
    {
        $check = $this->item(['id' => 'c1', 'content' => '流程及时', 'weight' => 20,
            'calcType' => 'check', 'reporterId' => 301, 'reporterName' => '王核查',
            'checkScore' => 16, 'reportBy' => 'user_3001']);
        $manual = $this->item(['id' => 'm1', 'content' => '团队管理', 'weight' => 80, 'calcType' => 'manual',
            'selfScore' => 70, 'approverScore' => 80, 'finalScore' => 75, 'finalManual' => true,
            'attachments' => [['name' => '依据.png', 'file' => 'm1_x.png', 'size' => 1234]]]);
        return $this->putPlan([
            'status' => 'done', 'founderId' => 5005, 'finishedAt' => now()->toDateTimeString(),
            'approverNames' => [0 => '李经理'], 'approverTimes' => [0 => now()->toDateTimeString()],
            'approverOpinions' => [0 => '同意'],
            'categories' => [$this->category('考核', [$check, $manual])],
        ]);
    }

    public function test_admin_reopen_resets_approval_keeps_scores_and_attachments(): void
    {
        $id = $this->donePlan();
        $this->postJson('/api/performance/reopen', ['id' => $id], $this->headers($this->adminToken))
            ->assertOk()->assertJson(['ok' => true, 'status' => 'approve']);

        $plan = $this->planData($id);
        $this->assertSame('approve', $plan['status']);
        $this->assertSame(0, (int) ($plan['currentStep'] ?? -1));
        $manual = $this->findItem($plan, 'm1');
        $this->assertEquals(70.0, (float) $manual['selfScore']);           // 自评保留
        $this->assertArrayNotHasKey('approverScore', $manual);             // 上级评分清除
        $this->assertArrayNotHasKey('finalManual', $manual);               // 终审微调清除
        // 重算暂定分：仅自评时回退自评（上级重评后自动覆盖）
        $this->assertEquals(70.0, (float) $manual['finalScore']);
        $this->assertNotEmpty($manual['attachments']);                    // 附件保留
        $this->assertEquals(16.0, (float) $this->findItem($plan, 'c1')['checkScore']); // 核查分保留
        $this->assertEmpty($plan['approverNames'] ?? []);
        // 重算：check 16 + 仅自评主观 70 = 86
        $this->assertEquals(86.0, (float) $plan['finalTotal']);
        $this->assertStringContainsString('撤销归档', json_encode($plan['logs'], JSON_UNESCAPED_UNICODE));
    }

    public function test_non_admin_reopen_forbidden(): void
    {
        $id = $this->donePlan();
        $this->postJson('/api/performance/reopen', ['id' => $id], $this->headers($this->leaderToken))
            ->assertStatus(403);
    }

    public function test_reopen_wrong_status(): void
    {
        $id = $this->putPlan(['status' => 'approve', 'categories' => [$this->category('考核', [
            $this->item(['weight' => 100]),
        ])]]);
        $this->postJson('/api/performance/reopen', ['id' => $id], $this->headers($this->adminToken))
            ->assertStatus(400);
    }

    public function test_detail_view_permission_matrix(): void
    {
        $id = $this->donePlan();
        $url = "/api/performance/plans/$id";
        $this->getJson($url, $this->headers($this->adminToken))->assertOk();
        $this->getJson($url, $this->headers($this->selfToken))->assertOk();      // 本人
        $this->getJson($url, $this->headers($this->founderToken))->assertOk();   // 发起人
        $this->getJson($url, $this->headers($this->leaderToken))->assertOk();    // 审批人
        $this->getJson($url, $this->headers($this->reporterToken))->assertOk();  // 核查人
        $this->getJson($url, $this->headers($this->outsiderToken))->assertStatus(403);
        $this->getJson($url)->assertStatus(401);                                 // 未登录
    }
}
