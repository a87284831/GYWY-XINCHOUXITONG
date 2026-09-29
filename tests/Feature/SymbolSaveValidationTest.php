<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 符号库保存端校验（9/27 符号错位事故防复发）：
 * - 基础校验：符号/名称必填、计勤值 0~1、符号字面查重（重复会静默覆盖导致核算错乱）
 * - 兼容性检查：历史考勤 rows.days 里出现过的符号字面，新符号库必须仍能识别（含勾号别名容错），
 *   否则已上传考勤在核算时被静默跳过 → 出勤少算 → 工资错误。
 */
class SymbolSaveValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = str_repeat('t', 64);
        Cache::put('payroll_api_token:' . $this->token, $id, now()->addHours(8));
    }

    /** 标准 12 项符号库（与生产一致的字面） */
    private function baseItems(): array
    {
        return [
            ['symbol' => '√', 'name' => '出勤', 'category' => '正常', 'value' => 1, 'in_required' => 1, 'in_actual' => 1],
            ['symbol' => '休', 'name' => '休息日', 'category' => '公休', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '缺', 'name' => '缺卡', 'category' => '缺卡', 'value' => 1, 'in_required' => 1, 'in_actual' => 1],
            ['symbol' => '事', 'name' => '事假', 'category' => '事假', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '病', 'name' => '病假', 'category' => '病假', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '产', 'name' => '产假', 'category' => '产假', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '迟', 'name' => '迟到', 'category' => '迟到', 'value' => 1, 'in_required' => 1, 'in_actual' => 1],
            ['symbol' => '早', 'name' => '早退', 'category' => '早退', 'value' => 1, 'in_required' => 1, 'in_actual' => 1],
            ['symbol' => '值', 'name' => '值班', 'category' => '值班', 'value' => 1, 'in_required' => 1, 'in_actual' => 1],
            ['symbol' => '旷', 'name' => '旷工', 'category' => '旷工', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '带', 'name' => '带薪假', 'category' => '年假调休', 'value' => 0, 'in_required' => 0, 'in_actual' => 0],
            ['symbol' => '半', 'name' => '半天出勤', 'category' => '正常', 'value' => 0.5, 'in_required' => 1, 'in_actual' => 1],
        ];
    }

    private function save(array $items) : \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/symbols/save', ['items' => $items], ['X-Token' => $this->token]);
    }

    /** 造一条历史考勤：days 全月用指定符号 */
    private function seedAttendance(string $recordKey, string $ym, string $symbol): void
    {
        DB::table('payroll_attendance')->insert([
            'record_key' => $recordKey, 'year_month' => $ym, 'project_name' => '测试项目',
            'rows' => json_encode([['name' => '张三', 'days' => array_fill(0, 31, $symbol)]], JSON_UNESCAPED_UNICODE),
            'locked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_valid_symbol_set_saves(): void
    {
        $this->save($this->baseItems())->assertOk()->assertJsonPath('ok', true);
        $payload = json_decode(DB::table('legacy_json_snapshots')->where('file_name', 'symbols.json')->value('payload'), true);
        $this->assertCount(12, $payload['items']);
    }

    public function test_duplicate_symbol_literal_rejected(): void
    {
        $items = $this->baseItems();
        $items[11]['symbol'] = '√'; // 「半」改成与「出勤」同字面
        $resp = $this->save($items);
        $resp->assertStatus(400);
        $this->assertStringContainsString('重复', $resp->json('error'));
    }

    public function test_empty_symbol_or_name_rejected(): void
    {
        $items = $this->baseItems();
        $items[3]['symbol'] = '  ';
        $this->save($items)->assertStatus(400);
        $items = $this->baseItems();
        $items[4]['name'] = '';
        $this->save($items)->assertStatus(400);
    }

    public function test_invalid_value_rejected(): void
    {
        $items = $this->baseItems();
        $items[11]['value'] = 2; // 超出 0~1
        $this->save($items)->assertStatus(400);
        $items = $this->baseItems();
        $items[11]['value'] = 'abc';
        $this->save($items)->assertStatus(400);
    }

    public function test_symbol_used_by_history_attendance_removal_blocked(): void
    {
        $this->seedAttendance('2026-08|测试项目', '2026-08', '休');
        $items = $this->baseItems();
        array_splice($items, 1, 1); // 删掉「休」——历史考勤全月在用
        $resp = $this->save($items);
        $resp->assertStatus(400);
        $this->assertStringContainsString('休', $resp->json('error'));
        $this->assertStringContainsString('2026-08', $resp->json('error'));
    }

    public function test_symbol_rename_used_by_history_attendance_blocked(): void
    {
        // 9/27 事故场景：把库里符号字面改掉，历史考勤的旧字面将无法识别
        $this->seedAttendance('2026-08|测试项目', '2026-08', '休');
        $items = $this->baseItems();
        $items[1]['symbol'] = '息'; // 休 → 息
        $resp = $this->save($items);
        $resp->assertStatus(400);
        $this->assertStringContainsString('休', $resp->json('error'));
    }

    public function test_checkmark_family_not_false_positive(): void
    {
        // 考勤里用 V，新符号库写 √：勾号别名容错应识别，不误报冲突
        $this->seedAttendance('2026-08|测试项目', '2026-08', 'V');
        $this->save($this->baseItems())->assertOk();
    }

    public function test_new_extra_symbols_allowed_without_attendance(): void
    {
        // 历史考勤没用到的自定义符号可以随意增删
        $items = $this->baseItems();
        $items[] = ['symbol' => '▲', 'name' => '驻场', 'category' => '其他', 'value' => 1, 'in_required' => 1, 'in_actual' => 1];
        $this->save($items)->assertOk();
    }
}
