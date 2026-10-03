<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Feature\Concerns\SeedsPerformancePlan;

/**
 * 绩效考核算分闭环：
 * - 客观项（ratio/ladder/count/check）由核查数据锁定分数，本人/上级不可评分
 * - 主观项（manual）走 自评×占比 + 上级评分×占比（默认各50%）
 * - 终审归档前客观项必须有锁定分，否则拒绝
 */
class PerformanceScoringFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPerformancePlan;

    private string $adminToken;
    private string $selfToken;     // 被考核人本人（staff=101, account=1001）
    private string $leaderToken;   // 审批人（staff=201, account=2001）
    private string $reporterToken; // 核查人（staff=301, account=3001）

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStaff(101, '张三');
        $this->seedStaff(201, '李经理');
        $this->seedStaff(301, '王核查');
        $this->adminToken = $this->seedAccount(9001, null, 'admin');
        $this->selfToken = $this->seedAccount(1001, 101, 'staff');
        $this->leaderToken = $this->seedAccount(2001, 201, 'staff');
        $this->reporterToken = $this->seedAccount(3001, 301, 'staff');
    }

    private function headers(string $token): array
    {
        return ['X-Token' => $token];
    }

    public function test_objective_ratio_score_is_locked_and_counts_in_final_total(): void
    {
        $ratio = $this->item(['id' => 'r1', 'content' => '回款率', 'weight' => 20,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100],
            'actualValue' => 75, 'reportBy' => '王核查']);
        $manual = $this->item(['id' => 'm1', 'content' => '团队管理', 'weight' => 80, 'calcType' => 'manual']);
        $id = $this->putPlan(['status' => 'self',
            'categories' => [$this->category('考核', [$ratio, $manual])]]);

        $this->postJson("/api/performance/self_submit", [
            'id' => $id, 'items' => [['id' => 'm1', 'selfScore' => 70]],
        ], $this->headers($this->selfToken))->assertOk()->assertJson(['ok' => true]);

        $resp = $this->getJson("/api/performance/plans/$id", $this->headers($this->selfToken))->json('plan');
        $this->assertEquals(15.0, $this->findItem($resp, 'r1')['finalScore']); // 20*75/100
        $this->assertEquals(70.0, $this->findItem($resp, 'm1')['finalScore']); // 无上级分→回退自评
        $this->assertEquals(85.0, $resp['finalTotal']);
        // 自评总分只含主观项
        $this->assertEquals(70.0, $resp['selfTotal']);
    }

    public function test_check_item_reporter_enters_score_and_locks(): void
    {
        $check = $this->item(['id' => 'c1', 'content' => '流程及时性', 'weight' => 20,
            'calcType' => 'check', 'reporterId' => 301, 'reporterName' => '王核查']);
        $id = $this->putPlan(['status' => 'report',
            'categories' => [$this->category('考核', [$check])]]);

        $this->postJson('/api/performance/report', [
            'id' => $id, 'itemId' => 'c1', 'checkScore' => 16,
        ], $this->headers($this->reporterToken))
            ->assertOk()->assertJson(['ok' => true]);

        $plan = $this->planData($id);
        $it = $this->findItem($plan, 'c1');
        $this->assertEquals(16.0, (float) $it['checkScore']);
        $this->assertSame('', $it['actualValue']);
        $this->assertNotEmpty($it['reportBy']);

        $resp = $this->getJson("/api/performance/plans/$id", $this->headers($this->adminToken))->json('plan');
        $this->assertEquals(16.0, $this->findItem($resp, 'c1')['finalScore']);
    }

    public function test_check_score_zero_is_valid_not_missing(): void
    {
        $check = $this->item(['id' => 'c0', 'content' => '违规扣分', 'weight' => 20,
            'calcType' => 'check', 'reporterId' => 301, 'reporterName' => '王核查',
            'checkScore' => 0, 'reportBy' => '王核查', 'reportTime' => now()->toDateTimeString()]);
        $manual = $this->item(['id' => 'm0', 'content' => '管理', 'weight' => 80, 'calcType' => 'manual']);
        $id = $this->putPlan(['status' => 'self',
            'categories' => [$this->category('考核', [$check, $manual])]]);

        // 本人自评 + 上级终审（同一步骤内 selfScore/approverScore 由接口分别写）
        $this->postJson("/api/performance/self_submit", [
            'id' => $id, 'items' => [['id' => 'm0', 'selfScore' => 60]],
        ], $this->headers($this->selfToken))->assertOk();
        $this->postJson("/api/performance/approve", [
            'id' => $id, 'items' => [['id' => 'm0', 'approverScore' => 80]],
        ], $this->headers($this->leaderToken))->assertOk()->assertJson(['status' => 'done']);

        $plan = $this->planData($id);
        $this->assertSame('done', $plan['status']);
        // check 项 0 分是有效锁定分
        $this->assertEquals(0.0, (float) $this->findItem($plan, 'c0')['finalScore']);
        // 0 锁定 + manual(60*0.5+80*0.5)=70 → 70
        $this->assertEquals(70.0, (float) $plan['finalTotal']);
    }

    public function test_check_score_out_of_range_rejected(): void
    {
        $mk = function (float $score) {
            $check = $this->item(['id' => 'cx', 'content' => '定分', 'weight' => 20,
                'calcType' => 'check', 'reporterId' => 301, 'reporterName' => '王核查']);
            $id = $this->putPlan(['status' => 'report',
                'categories' => [$this->category('考核', [$check])]]);
            return $this->postJson('/api/performance/report', [
                'id' => $id, 'itemId' => 'cx', 'checkScore' => $score,
            ], $this->headers($this->reporterToken));
        };
        $mk(-1)->assertStatus(400);
        $mk(21)->assertStatus(400);
    }

    public function test_self_submit_rejects_score_on_objective_item(): void
    {
        $ratio = $this->item(['id' => 'r2', 'content' => '回款率', 'weight' => 100,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100]]);
        $id = $this->putPlan(['status' => 'self',
            'categories' => [$this->category('考核', [$ratio])]]);

        $this->postJson('/api/performance/self_submit', [
            'id' => $id, 'items' => [['id' => 'r2', 'selfScore' => 90]],
        ], $this->headers($this->selfToken))->assertStatus(400);
    }

    public function test_approve_rejects_score_on_objective_item(): void
    {
        $ratio = $this->item(['id' => 'r3', 'content' => '回款率', 'weight' => 100,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100],
            'actualValue' => 90, 'reportBy' => '王核查']);
        $id = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'categories' => [$this->category('考核', [$ratio])]]);

        $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [['id' => 'r3', 'approverScore' => 95]],
        ], $this->headers($this->leaderToken))->assertStatus(400);
    }

    public function test_final_approve_blocked_when_objective_score_missing(): void
    {
        $ratio = $this->item(['id' => 'r4', 'content' => '回款率', 'weight' => 20,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100],
            'reporterId' => 301, 'reporterName' => '王核查']); // 已指派但漏填 actualValue
        $manual = $this->item(['id' => 'm4', 'content' => '管理', 'weight' => 80, 'calcType' => 'manual',
            'selfScore' => 70]);
        $id = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'categories' => [$this->category('考核', [$ratio, $manual])]]);

        $resp = $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [['id' => 'm4', 'approverScore' => 80]],
        ], $this->headers($this->leaderToken));
        $resp->assertStatus(400);
        $this->assertNotEmpty($resp->json('missing'));
        $this->assertSame('approve', $this->planData($id)['status']);
    }

    public function test_mid_level_approve_not_blocked_by_missing_objectives(): void
    {
        $this->seedStaff(202, '赵总');
        $bossToken = $this->seedAccount(2002, 202, 'staff');
        $ratio = $this->item(['id' => 'r5', 'content' => '回款率', 'weight' => 20,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100],
            'reporterId' => 301, 'reporterName' => '王核查']); // 已指派但漏填
        $manual = $this->item(['id' => 'm5', 'content' => '管理', 'weight' => 80, 'calcType' => 'manual',
            'selfScore' => 70]);
        $id = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'approvers' => [
                ['staffId' => 201, 'userId' => 2001, 'name' => '李经理'],
                ['staffId' => 202, 'userId' => 2002, 'name' => '赵总'],
            ],
            'categories' => [$this->category('考核', [$ratio, $manual])]]);

        // 第一级通过（不触发归档校验）
        $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [['id' => 'm5', 'approverScore' => 75]],
        ], $this->headers($this->leaderToken))->assertOk()->assertJson(['status' => 'approve', 'step' => 1]);

        // 第二级终审 → 缺分拦截
        $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [['id' => 'm5', 'approverScore' => 75]],
        ], $this->headers($bossToken))->assertStatus(400);
    }

    public function test_reporter_can_be_subject_employee(): void
    {
        $check = $this->item(['id' => 'c9', 'content' => '流程及时（自查）', 'weight' => 100,
            'calcType' => 'check', 'reporterId' => 101, 'reporterName' => '张三']);
        $id = $this->putPlan(['status' => 'report',
            'categories' => [$this->category('考核', [$check])]]);

        $this->postJson('/api/performance/report', [
            'id' => $id, 'itemId' => 'c9', 'checkScore' => 20,
        ], $this->headers($this->selfToken))->assertOk(); // reporterId=本人，允许自我核查

        $this->assertEquals(20.0, (float) $this->findItem($this->planData($id), 'c9')['checkScore']);
    }

    public function test_legacy_item_without_calctype_defaults_ratio_and_guards_archive(): void
    {
        $legacy = $this->item(['id' => 'x1', 'content' => '旧指标', 'weight' => 100]);
        unset($legacy['calcType'], $legacy['calcParams']); // 历史脏数据：无类型无值
        $id = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'categories' => [$this->category('考核', [$legacy])]]);

        $resp = $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [],
        ], $this->headers($this->leaderToken));
        $resp->assertStatus(400);
        $missing = $resp->json('missing');
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString('旧指标', implode('；', $missing));
    }

    public function test_objective_item_without_reporter_is_skipped_not_deadlocking_archive(): void
    {
        // 发起页明确承诺：未指派填报人的指标"填报阶段自动跳过"；
        // 合法五类类型 + 无 reporterId + 无锁定分 → 不拦截终审（该项不参与计分）
        $skipped = $this->item(['id' => 'r6', 'content' => '备注类指标（不考核）', 'weight' => 20,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100]]); // 无 reporterId / actualValue
        $manual = $this->item(['id' => 'm6', 'content' => '管理', 'weight' => 80, 'calcType' => 'manual',
            'selfScore' => 70]);
        $id = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'categories' => [$this->category('考核', [$skipped, $manual])]]);

        $this->postJson('/api/performance/approve', [
            'id' => $id, 'items' => [['id' => 'm6', 'approverScore' => 80]],
        ], $this->headers($this->leaderToken))->assertOk()->assertJson(['status' => 'done']);

        $plan = $this->planData($id);
        // 跳过项无分，主观项 75 → 总分 75（权重20作废，纸质表可追溯该项未考核）
        $this->assertEquals(75.0, (float) $plan['finalTotal']);
    }

    public function test_non_admin_cannot_report_unassigned_item(): void
    {
        $ratio = $this->item(['id' => 'r7', 'content' => '回款率', 'weight' => 100,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100]]); // 无 reporterId
        $id = $this->putPlan(['status' => 'report',
            'categories' => [$this->category('考核', [$ratio])]]);

        // 被考核人本人不是填报人：未指派项禁止填报（管理员仍可代填）
        $this->postJson('/api/performance/report', [
            'id' => $id, 'itemId' => 'r7', 'actualValue' => 90,
        ], $this->headers($this->selfToken))->assertStatus(403);

        $this->postJson('/api/performance/admin_fill', [
            'id' => $id, 'itemId' => 'r7', 'actualValue' => 90,
        ], $this->headers($this->adminToken))->assertOk();
    }

    public function test_manual_scores_out_of_item_weight_are_rejected(): void
    {
        // 主观分合法域 0~该项权重（权重合计=100、总分=Σ各项分，与 check 0~权重 同口径）
        $manual = $this->item(['id' => 'm9', 'content' => '管理', 'weight' => 80, 'calcType' => 'manual']);
        $ratio = $this->item(['id' => 'r9', 'content' => '回款率', 'weight' => 20,
            'calcType' => 'ratio', 'calcParams' => ['target' => 100],
            'reporterId' => 301, 'reporterName' => '王核查', 'actualValue' => 100]);

        // 自评：越上界 / 负数 均拒绝；边界值合法
        $selfId = $this->putPlan(['status' => 'self',
            'categories' => [$this->category('考核', [$manual, $ratio])]]);
        $this->postJson('/api/performance/self_submit', [
            'id' => $selfId, 'items' => [['id' => 'm9', 'selfScore' => 80.01]],
        ], $this->headers($this->selfToken))->assertStatus(400);
        $this->postJson('/api/performance/self_submit', [
            'id' => $selfId, 'items' => [['id' => 'm9', 'selfScore' => -0.01]],
        ], $this->headers($this->selfToken))->assertStatus(400);
        $this->postJson('/api/performance/self_submit', [
            'id' => $selfId, 'items' => [['id' => 'm9', 'selfScore' => 80]],
        ], $this->headers($this->selfToken))->assertOk();

        // 上级分与终审微调同样限界
        $apprId = $this->putPlan(['status' => 'approve', 'currentStep' => 0,
            'categories' => [$this->category('考核', [$manual, $ratio])]]);
        $this->postJson('/api/performance/approve', [
            'id' => $apprId, 'items' => [['id' => 'm9', 'approverScore' => 999]],
        ], $this->headers($this->leaderToken))->assertStatus(400);
        $this->postJson('/api/performance/approve', [
            'id' => $apprId, 'items' => [['id' => 'm9', 'approverScore' => 80, 'finalScore' => 81]],
        ], $this->headers($this->leaderToken))->assertStatus(400);
        $this->postJson('/api/performance/approve', [
            'id' => $apprId, 'items' => [['id' => 'm9', 'approverScore' => 80, 'finalScore' => 0]],
        ], $this->headers($this->leaderToken))->assertOk()->assertJson(['status' => 'done']);
    }
}
