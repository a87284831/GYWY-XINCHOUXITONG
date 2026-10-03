<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Feature\Concerns\SeedsPerformancePlan;

/**
 * 自评依据附件：self 阶段本人上传（图片/pdf），鉴权下载、归档前可删；
 * 文件存非公开目录，路径防穿越。
 */
class PerformanceAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPerformancePlan;

    private string $selfToken;
    private string $outsiderToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStaff(101, '张三');
        $this->seedStaff(404, '赵无关');
        $this->selfToken = $this->seedAccount(1001, 101, 'staff');
        $this->outsiderToken = $this->seedAccount(4004, 404, 'staff');
        $this->adminToken = $this->seedAccount(9001, null, 'admin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/perf-attachments'));
        parent::tearDown();
    }

    private function headers(string $token): array { return ['X-Token' => $token]; }

    private function planWithManual(string $status = 'self'): int
    {
        return $this->putPlan(['status' => $status, 'founderId' => 1001,
            'categories' => [$this->category('考核', [
                $this->item(['id' => 'm1', 'content' => '团队管理', 'weight' => 100, 'calcType' => 'manual']),
            ])]]);
    }

    public function test_subject_uploads_attachment_in_self_stage(): void
    {
        $id = $this->planWithManual();
        $resp = $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1',
            'file' => UploadedFile::fake()->image('依据.png', 80, 80),
        ], $this->headers($this->selfToken))->assertOk()->assertJson(['ok' => true]);

        $stored = $resp->json('file') ?? basename((string) $resp->json('url'));
        $this->assertTrue(is_file(storage_path('app/perf-attachments/' . $id . '/' . $stored)));

        $plan = $this->planData($id);
        $att = $this->findItem($plan, 'm1')['attachments'][0];
        $this->assertSame('依据.png', $att['name']);
        $this->assertEquals(1001, $att['uploaderId']);
    }

    public function test_upload_rejected_wrong_type_size_count(): void
    {
        $id = $this->planWithManual();
        $h = $this->headers($this->selfToken);
        // 类型不允许
        $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1', 'file' => UploadedFile::fake()->create('note.txt', 10),
        ], $h)->assertStatus(400);
        // 超 10MB
        $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1', 'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
        ], $h)->assertStatus(400);
        // 每项 ≤5 个
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/performance/attachment', [
                'id' => $id, 'itemId' => 'm1',
                'file' => UploadedFile::fake()->image("ok$i.png", 10, 10),
            ], $h)->assertOk();
        }
        $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1', 'file' => UploadedFile::fake()->image('sixth.png', 10, 10),
        ], $h)->assertStatus(400);
    }

    public function test_upload_forbidden_non_self_status_and_outsider(): void
    {
        $id = $this->planWithManual('ongoing');
        $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1', 'file' => UploadedFile::fake()->image('x.png', 10, 10),
        ], $this->headers($this->selfToken))->assertStatus(400);

        $selfId = $this->planWithManual('self');
        $this->post('/api/performance/attachment', [
            'id' => $selfId, 'itemId' => 'm1', 'file' => UploadedFile::fake()->image('x.png', 10, 10),
        ], $this->headers($this->outsiderToken))->assertStatus(403);
    }

    public function test_download_requires_view_permission(): void
    {
        $id = $this->planWithManual();
        $resp = $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1',
            'file' => UploadedFile::fake()->image('shot.png', 10, 10),
        ], $this->headers($this->selfToken))->assertOk();
        $stored = $resp->json('file');

        $this->get("/api/performance/attachment?id=$id&file=$stored", $this->headers($this->selfToken))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/api/performance/attachment?id=$id&file=$stored", $this->headers($this->outsiderToken))
            ->assertStatus(403);
    }

    public function test_download_blocks_path_traversal(): void
    {
        $id = $this->planWithManual();
        $this->get("/api/performance/attachment?id=$id&file=" . urlencode('../../.env'), $this->headers($this->selfToken))
            ->assertStatus(400);
    }

    public function test_delete_attachment_owner_before_done_and_block_after(): void
    {
        $id = $this->planWithManual();
        $resp = $this->post('/api/performance/attachment', [
            'id' => $id, 'itemId' => 'm1',
            'file' => UploadedFile::fake()->image('shot.png', 10, 10),
        ], $this->headers($this->selfToken))->assertOk();
        $stored = $resp->json('file');

        // 非上传者不能删
        $this->delete('/api/performance/attachment',
            ['id' => $id, 'itemId' => 'm1', 'file' => $stored], $this->headers($this->outsiderToken))
            ->assertStatus(403);
        // 上传者本人 self 阶段可删
        $this->delete('/api/performance/attachment',
            ['id' => $id, 'itemId' => 'm1', 'file' => $stored], $this->headers($this->selfToken))
            ->assertOk();
        $this->assertFalse(is_file(storage_path('app/perf-attachments/' . $id . '/' . $stored)));
        $this->assertEmpty($this->findItem($this->planData($id), 'm1')['attachments'] ?? []);

        // 归档后禁删（构造 done 单 + 已有附件元数据）
        $doneId = $this->putPlan(['status' => 'done', 'founderId' => 1001,
            'categories' => [$this->category('考核', [
                $this->item(['id' => 'm1', 'weight' => 100, 'calcType' => 'manual',
                    'attachments' => [['name' => 'a.png', 'file' => 'locked_a.png', 'size' => 1,
                        'uploaderId' => 1001, 'ts' => '2026-10-01 10:00:00']]]),
            ])]]);
        $this->delete('/api/performance/attachment',
            ['id' => $doneId, 'itemId' => 'm1', 'file' => 'locked_a.png'], $this->headers($this->selfToken))
            ->assertStatus(400);
    }
}
