<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 薪酬微调专项改造：
 * 1. /api/payroll 列表返回 adjusted_staff_ids（本月有微调日志的人员）→ 前端高亮依据
 * 2. /api/payroll/adjust-logs/export 微调日志 Excel 导出（仅总部管理员）：
 *    中文标签"原值 → 新值"、操作人、调整后实发快照、原因
 */
class PayrollAdjustHighlightExportTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-09';
    private const PROJ = '测试项目';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->seedResult(2, '张三', ['meal' => 200, 'gross' => 5000, 'net' => 4000]);
        $this->seedResult(3, '李四', ['meal' => 300, 'gross' => 6000, 'net' => 4800]);
        // 张三有一条微调日志；李四没有 → 高亮名单只应有张三
        DB::table('payroll_adjust_logs')->insert([
            'staff_legacy_id' => 2, 'staff_name' => '张三', 'project_name' => self::PROJ, 'ym' => self::YM,
            'changes' => json_encode([['field' => 'meal', 'old' => 0, 'new' => 200]], JSON_UNESCAPED_UNICODE),
            'row_after' => json_encode(['gross' => 5200, 'net' => 4200, 'actual_tax' => 0, 'soc_total' => 1000], JSON_UNESCAPED_UNICODE),
            'reason' => '加餐补', 'operator' => '总部管理员', 'operator_role' => 'admin',
            'created_at' => '2026-09-15 10:30:00',
        ]);
    }

    private function seedResult(int $staffId, string $name, array $rowExtra): void
    {
        $row = array_merge([
            'staff_id' => $staffId, 'name' => $name, 'project' => self::PROJ,
            'department' => '客服部', 'position' => '专员', 'status' => '正式',
        ], $rowExtra);
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'staff_legacy_id' => $staffId, 'project_name' => self::PROJ,
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'archived' => false,
            'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_list_returns_adjusted_staff_ids_for_highlight(): void
    {
        $resp = $this->getJson('/api/payroll?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $ids = $resp->json('adjusted_staff_ids');
        $this->assertIsArray($ids);
        $this->assertContains(2, $ids, '张三本月有微调日志 → 应在高亮名单');
        $this->assertNotContains(3, $ids, '李四没有微调 → 不在高亮名单');
    }

    public function test_export_adjust_logs_xlsx(): void
    {
        $resp = $this->getJson('/api/payroll/adjust-logs/export?ym=' . self::YM, ['X-Token' => $this->adminToken]);
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $resp->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);

        $headers = $grid[0];
        foreach (['时间', '操作人', '项目', '姓名', '调整内容', '调整后实发', '原因'] as $h) {
            $this->assertContains($h, $headers, "导出表头缺少：{$h}");
        }
        $text = implode('|', array_map(fn ($v) => (string) $v, $grid[1]));
        $this->assertStringContainsString('餐补: 0 → 200', $text, '调整内容应为中文标签+原值→新值');
        $this->assertStringContainsString('总部管理员', $text);
        $this->assertStringContainsString('张三', $text);
        $this->assertStringContainsString('加餐补', $text);
        $this->assertStringContainsString('4200', $text, '应含调整后实发快照');

        @unlink($tmp);
    }

    public function test_export_forbidden_for_project_account(): void
    {
        $pid = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9002, 'username' => 'proj_user', 'name' => '项目账号',
            'role' => 'project', 'project_name' => self::PROJ, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pToken = str_repeat('p', 64);
        Cache::put('payroll_api_token:' . $pToken, $pid, now()->addHours(8));
        $this->getJson('/api/payroll/adjust-logs/export?ym=' . self::YM, ['X-Token' => $pToken])
            ->assertStatus(403);
    }
}
