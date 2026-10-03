<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Feature\Concerns\SeedsPerformancePlan;

/**
 * 上级审核指标：confirm 态首位审批人可直接改指标（留痕）；
 * 指标冻结：非 draft 态 save 拒绝；done 单不可删除。
 */
class PerformanceConfirmEditTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPerformancePlan;

    private string $adminToken;
    private string $selfToken;
    private string $leaderToken;
    private string $outsiderToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStaff(101, '张三');
        $this->seedStaff(201, '李经理');
        $this->seedStaff(404, '赵无关');
        $this->adminToken = $this->seedAccount(9001, null, 'admin');
        $this->selfToken = $this->seedAccount(1001, 101, 'staff');
        $this->leaderToken = $this->seedAccount(2001, 201, 'staff');
        $this->outsiderToken = $this->seedAccount(4004, 404, 'staff');
    }

    private function headers(string $token): array { return ['X-Token' => $token]; }

    private function baseCats(): array
    {
        return [$this->category('考核', [
            $this->item(['id' => 'r1', 'content' => '回款率', 'weight' => 20,
                'calcType' => 'ratio', 'calcParams' => ['target' => 100]]),
            $this->item(['id' => 'm1', 'content' => '团队管理', 'weight' => 80, 'calcType' => 'manual']),
        ])];
    }

    public function test_first_approver_can_save_edited_categories_in_confirm(): void
    {
        $id = $this->putPlan(['status' => 'confirm', 'categories' => $this->baseCats()]);
        $cats = $this->baseCats();
        $cats[0]['items'][0]['weight'] = 25; // 20→25
        $cats[0]['items'][1]['weight'] = 75; // 80→75

        $this->postJson('/api/performance/confirm_save',
            ['id' => $id, 'categories' => $cats], $this->headers($this->leaderToken))
            ->assertOk()->assertJson(['ok' => true]);

        $plan = $this->planData($id);
        $this->assertEquals(25.0, (float) $this->findItem($plan, 'r1')['weight']);
        $this->assertEquals(75.0, (float) $this->findItem($plan, 'm1')['weight']);
        $logText = json_encode($plan['logs'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('权重', $logText);
        $this->assertStringContainsString('20→25', $logText);
    }

    public function test_non_approver_confirm_save_forbidden(): void
    {
        $id = $this->putPlan(['status' => 'confirm', 'founderId' => 9001,
            'categories' => $this->baseCats()]);
        // 无关账号：既不是发起人(9001)、也不是审批人(201)
        $this->postJson('/api/performance/confirm_save',
            ['id' => $id, 'categories' => $this->baseCats()], $this->headers($this->outsiderToken))
            ->assertStatus(403);
    }

    public function test_confirm_save_wrong_status_rejected(): void
    {
        $id = $this->putPlan(['status' => 'ongoing', 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/confirm_save',
            ['id' => $id, 'categories' => $this->baseCats()], $this->headers($this->leaderToken))
            ->assertStatus(400);
    }

    public function test_confirm_save_cannot_change_employee_period_approvers(): void
    {
        $id = $this->putPlan(['status' => 'confirm', 'employeeId' => 101,
            'periodStart' => '2026-07-01', 'periodEnd' => '2026-09-30',
            'categories' => $this->baseCats()]);
        $payload = [
            'id' => $id, 'categories' => $this->baseCats(),
            'employeeId' => 404, 'periodStart' => '2020-01-01', 'periodEnd' => '2020-12-31',
            'approvers' => [['staffId' => 404, 'userId' => 4004, 'name' => '赵无关']],
        ];
        $this->postJson('/api/performance/confirm_save', $payload, $this->headers($this->leaderToken))->assertOk();

        $plan = $this->planData($id);
        $this->assertEquals(101, $plan['employeeId']);
        $this->assertSame('2026-07-01', $plan['periodStart']);
        $this->assertSame('2026-09-30', $plan['periodEnd']);
        $this->assertEquals(201, $plan['approvers'][0]['staffId']);
    }

    public function test_confirm_save_weight_sum_rejected(): void
    {
        $id = $this->putPlan(['status' => 'confirm', 'categories' => $this->baseCats()]);
        $cats = $this->baseCats();
        $cats[0]['items'][0]['weight'] = 10; // 合计 90
        $this->postJson('/api/performance/confirm_save',
            ['id' => $id, 'categories' => $cats], $this->headers($this->leaderToken))
            ->assertStatus(400);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frozenStatusProvider')]
    public function test_save_rejects_editing_after_confirm(string $status): void
    {
        $id = $this->putPlan(['status' => $status, 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/plans/save',
            ['id' => $id, 'categories' => $this->baseCats()], $this->headers($this->selfToken))
            ->assertStatus(400);
    }

    public static function frozenStatusProvider(): array
    {
        return [['ongoing'], ['report'], ['self'], ['approve'], ['done']];
    }

    public function test_save_draft_allowed_and_delete_guard(): void
    {
        // draft 态本人可保存
        $id = $this->putPlan(['status' => 'draft', 'founderId' => 1001, 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/plans/save', [
            'id' => $id, 'periodStart' => '2026-07-01', 'periodEnd' => '2026-09-30',
            'categories' => $this->baseCats(),
            'approvers' => [['staffId' => 201, 'userId' => 2001, 'name' => '李经理']],
        ], $this->headers($this->selfToken))->assertOk();

        // done 不可删除
        $doneId = $this->putPlan(['status' => 'done', 'founderId' => 1001, 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/delete', ['id' => $doneId], $this->headers($this->selfToken))
            ->assertStatus(400);
        // draft 发起人可删
        $draftId = $this->putPlan(['status' => 'draft', 'founderId' => 1001, 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/delete', ['id' => $draftId], $this->headers($this->selfToken))
            ->assertOk();
    }

    public function test_confirm_save_logs_added_and_removed_items(): void
    {
        $id = $this->putPlan(['status' => 'confirm', 'categories' => $this->baseCats()]);
        $cats = [$this->category('考核', [
            // 删除 r1，保留 m1（改权重），新增 n1
            $this->item(['id' => 'm1', 'content' => '团队管理', 'weight' => 70, 'calcType' => 'manual']),
            $this->item(['id' => 'n1', 'content' => '新增指标', 'weight' => 30, 'calcType' => 'manual']),
        ])];
        $this->postJson('/api/performance/confirm_save',
            ['id' => $id, 'categories' => $cats], $this->headers($this->leaderToken))->assertOk();

        $logText = json_encode($this->planData($id)['logs'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('新增', $logText);
        $this->assertStringContainsString('删除', $logText);
    }

    /**
     * 安全：指标定义入口只接受定义字段，流程数据（自评分/核查定分/实际值/附件等）
     * 必须由各自阶段的专用接口产生；否则被考核人本人(founder)可在审核态注入分数直接加分。
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('processFieldInjectionProvider')]
    public function test_define_endpoints_strip_process_fields(array $dirtyItem): void
    {
        // 入口1：confirm_save（founder=被考核人本人即有权调用）
        $id = $this->putPlan(['status' => 'confirm', 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/confirm_save', [
            'id' => $id,
            'categories' => [$this->category('考核', [$dirtyItem['check'], $dirtyItem['manual']])],
        ], $this->headers($this->selfToken))->assertOk();

        foreach (['c8' => $dirtyItem['check'], 'm8' => $dirtyItem['manual']] as $iid => $source) {
            $saved = $this->findItem($this->planData($id), $iid);
            foreach (array_keys($source) as $key) {
                if (in_array($key, ['id', 'content', 'definition', 'sourceDept', 'weight',
                        'calcType', 'calcParams', 'reporterId', 'reporterName'], true)) continue;
                $this->assertArrayNotHasKey($key, $saved, "confirm_save 未剥离流程字段 {$key}");
            }
        }

        // 入口2：draft save 同样清洗（用不重叠周期，避开同员工在途考核单的重复校验）
        $draftId = $this->putPlan(['status' => 'draft', 'founderId' => 1001,
            'periodStart' => '2027-01-01', 'periodEnd' => '2027-03-31', 'categories' => $this->baseCats()]);
        $this->postJson('/api/performance/plans/save', [
            'id' => $draftId, 'periodStart' => '2027-01-01', 'periodEnd' => '2027-03-31',
            'categories' => [$this->category('考核', [$dirtyItem['check'], $dirtyItem['manual']])],
            'approvers' => [['staffId' => 201, 'userId' => 2001, 'name' => '李经理']],
        ], $this->headers($this->selfToken))->assertOk();
        $savedCheck = $this->findItem($this->planData($draftId), 'c8');
        $this->assertArrayNotHasKey('checkScore', $savedCheck);
        $this->assertArrayNotHasKey('attachments', $savedCheck);
    }

    public static function processFieldInjectionProvider(): array
    {
        // DataProvider 在另一实例上执行，无法调用 trait helper，直接内联构造
        return [[
            [
                'check' => [
                    'id' => 'c8', 'content' => '核查项', 'weight' => 50, 'calcType' => 'check',
                    'calcParams' => [], 'reporterId' => 301, 'reporterName' => '王核查',
                    // —— 以下为注入的流程字段，必须全部被剥离 ——
                    'checkScore' => 50, 'actualValue' => 50, 'actualText' => 'x', 'autoScore' => 50,
                    'finalScore' => 50, 'reportBy' => '张三', 'reportTime' => '2026-10-01 10:00:00',
                    'attachments' => [['name' => 'a.png', 'file' => 'a.png', 'size' => 1]],
                ],
                'manual' => [
                    'id' => 'm8', 'content' => '主观项', 'weight' => 50, 'calcType' => 'manual',
                    'calcParams' => [], 'reporterId' => null, 'reporterName' => '',
                    // —— 注入 ——
                    'selfScore' => 100, 'approverScore' => 100, 'finalScore' => 100,
                    'finalManual' => true, 'selfWeighted' => 100, 'approverWeighted' => 100,
                ],
            ],
        ]];
    }
}
