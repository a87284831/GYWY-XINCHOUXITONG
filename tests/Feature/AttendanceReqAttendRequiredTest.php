<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 应出勤 0/留空 = 漏填（不是真实值）：
 *  - 考勤上传整单拒绝，报错指明行/人/字段；
 *  - 微调只允许 >0 的整数，后端同样校验；
 *  - 核算兜底保留（历史存量不动），但必须记日志并在结果里给出可见提示。
 */
class AttendanceReqAttendRequiredTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-10';
    private const PROJ = '测试项目';
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9301, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('r', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));

        DB::table('payroll_projects')->insert([
            ['name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payroll_roles')->insert([
            ['role_key' => 'project', 'name' => '项目人力', 'scope' => 'project',
             'permissions' => null, 'data' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'symbols.json'], [
            'payload' => json_encode(['items' => [
                ['symbol' => '√', 'name' => '出勤', 'desc' => '', 'in_required' => true, 'in_actual' => true, 'value' => 1.0, 'category' => '正常'],
                ['symbol' => '休', 'name' => '休息日', 'desc' => '', 'in_required' => false, 'in_actual' => false, 'value' => 0.0, 'category' => '公休'],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'calc_rules.json'], [
            'payload' => json_encode(['rules' => [
                'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
                'performance' => ['enabled' => true, 'probation_excluded' => true],
                'sick_pay' => ['enabled' => false],
                'deduction_rules' => ['miss_punch' => ['enabled' => false], 'absent' => ['enabled' => false]],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['张三', 1], ['李四', 2]] as [$name, $id]) {
            DB::table('payroll_staff')->insert([
                'legacy_id' => $id, 'name' => $name, 'project_name' => self::PROJ, 'position' => '岗位',
                'status' => '正式', 'person_type' => 'staff', 'deleted' => false,
                'hire_date' => '2025-01-01', 'fixed_monthly' => 10000, 'base_salary' => 8000,
                'data' => json_encode(['salary_history' => [
                    ['effective_date' => '2025-01-01', 'fixed_monthly' => 10000, 'base_salary' => 8000, 'type' => '初始', 'note' => ''],
                ], 'special_deductions' => []], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string,int|string|null> $reqByName 姓名 => 应出勤（null 表示留空） */
    private function makeWorkbook(array $reqByName): string
    {
        $days = (int) date('t', strtotime(self::YM . '-01'));
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B3', '姓名');
        $sheet->setCellValue('C3', '人员状态');
        $sheet->setCellValue('D3', '岗位');
        for ($d = 1; $d <= $days; $d++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + $d) . '3', (string) $d);
        }
        $reqLetter = Coordinate::stringFromColumnIndex(5 + $days);
        $sheet->setCellValue("{$reqLetter}3", '应出勤(手填)');
        $r = 5;
        foreach ($reqByName as $name => $req) {
            $sheet->setCellValue("A{$r}", $r - 4);
            $sheet->setCellValue("B{$r}", $name);
            $sheet->setCellValue("C{$r}", '正式');
            for ($d = 1; $d <= 20; $d++) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(4 + $d) . $r, '√');
            }
            if ($req !== null) $sheet->setCellValue("{$reqLetter}{$r}", $req);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'reqatt') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path)
    {
        return $this->post('/api/attendance/upload', [
            'ym' => self::YM, 'project' => self::PROJ, 'dry_run' => '1',
            'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
        ], ['X-Token' => $this->adminToken]);
    }

    public function test_upload_rejects_blank_or_zero_req_with_row_name_and_field(): void
    {
        foreach ([null, 0] as $bad) {
            $path = $this->makeWorkbook(['张三' => $bad]);
            try {
                $resp = $this->upload($path);
            } finally {
                @unlink($path);
            }
            $resp->assertStatus(400);
            $msg = (string) $resp->json('error');
            $this->assertStringContainsString('第5行', $msg);
            $this->assertStringContainsString('张三', $msg);
            $this->assertStringContainsString('应出勤', $msg);
            $item = null;
            foreach ((array) $resp->json('error_items') as $it) {
                if (($it['field'] ?? '') === 'req_attend') { $item = $it; break; }
            }
            $this->assertNotNull($item, '错误项必须带 req_attend 字段标识');
            $this->assertSame('应出勤(手填)', $item['label']);
            $this->assertSame(5, (int) $item['row']);
            $this->assertSame('张三', $item['name']);
            $this->assertSame(0, DB::table('payroll_attendance')->count(), '整单拒绝，不得写入任何考勤块');
        }
    }

    public function test_upload_points_to_the_second_row_when_only_it_is_missing(): void
    {
        $path = $this->makeWorkbook(['张三' => 22, '李四' => null]);
        try {
            $resp = $this->upload($path);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(400);
        $msg = (string) $resp->json('error');
        $this->assertStringContainsString('第6行', $msg);
        $this->assertStringContainsString('李四', $msg);
        $this->assertStringNotContainsString('第5行 张三', $msg, '已填合法应出勤的行不应被误报');
    }

    public function test_upload_accepts_positive_req(): void
    {
        $path = $this->makeWorkbook(['张三' => 22, '李四' => 21]);
        try {
            $resp = $this->upload($path);
        } finally {
            @unlink($path);
        }
        $resp->assertOk()->assertJson(['ok' => true]);
    }

    // ===== 微调应出勤校验 =====

    private function seedResultRow(): void
    {
        DB::table('payroll_results')->insert([
            'year_month' => self::YM, 'project_name' => self::PROJ, 'staff_legacy_id' => 1,
            'row_data' => json_encode([
                'project' => self::PROJ, 'name' => '张三', 'fixed' => 10000, 'base' => 8000,
                'req_att' => 22, 'act_att' => 22, 'perf_att' => 22, 'coef' => 1,
                'base_pay' => 8000, 'perf_pay' => 2000, 'gross' => 10000, 'net' => 10000,
                'soc_total' => 0, 'actual_tax' => 0, 'welfare' => 0,
            ], JSON_UNESCAPED_UNICODE),
            'archived' => false, 'is_manager_row' => false, 'is_case_row' => false, 'is_hq_row' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_adjust_rejects_zero_or_non_integer_req(): void
    {
        $this->seedResultRow();
        foreach ([0, -1, 22.5] as $bad) {
            $resp = $this->postJson('/api/payroll/adjust', [
                'ym' => self::YM, 'staff_id' => 1, 'fields' => ['req_att' => $bad],
            ], ['X-Token' => $this->adminToken]);
            $resp->assertStatus(400);
            $this->assertStringContainsString('大于0的整数', (string) $resp->json('error'));
        }
    }

    public function test_adjust_accepts_positive_integer_req(): void
    {
        $this->seedResultRow();
        $resp = $this->postJson('/api/payroll/adjust', [
            'ym' => self::YM, 'staff_id' => 1, 'fields' => ['req_att' => 23],
        ], ['X-Token' => $this->adminToken]);
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertEqualsWithDelta(23.0, (float) $resp->json('row.req_att'), 0.001);
    }

    // ===== 核算兜底：保留但必须可见 =====

    public function test_calc_zero_req_falls_back_to_calendar_and_warns(): void
    {
        $days = array_fill(0, (int) date('t', strtotime(self::YM . '-01')), '休');
        for ($i = 0; $i < 20; $i++) $days[$i] = '√';
        DB::table('payroll_attendance')->insert([
            'record_key' => self::YM . '|' . self::PROJ, 'year_month' => self::YM, 'project_name' => self::PROJ,
            'rows' => json_encode(['张三' => [
                'days' => $days, 'req_attend' => 0, 'act_attend' => 20, 'coef' => 1,
            ]], JSON_UNESCAPED_UNICODE),
            'locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::spy();
        $result = (new PayrollCalculator())->calculate(self::YM, [self::PROJ]);

        $warnings = collect($result['warnings'] ?? []);
        $hit = $warnings->first(fn ($w) => str_contains((string) ($w['reason'] ?? ''), '应出勤缺失'));
        $this->assertNotNull($hit, '核算结果必须提示应出勤缺失并按日历天数计算');
        $this->assertSame('张三', $hit['name']);
        Log::shouldHaveReceived('warning')->withArgs(function ($msg, $ctx) {
            return $msg === 'payroll.calc.req_att_fallback' && ($ctx['name'] ?? '') === '张三';
        })->once();

        $row = json_decode((string) DB::table('payroll_results')
            ->where('year_month', self::YM)->where('staff_legacy_id', 1)->value('row_data'), true);
        $this->assertEqualsWithDelta(0.0, (float) $row['req_att'], 0.001, '存量 0 原样保留，不静默改数');
        $daysInMonth = (int) date('t', strtotime(self::YM . '-01'));
        $this->assertEqualsWithDelta(round(8000 * 20 / $daysInMonth, 2), (float) $row['base_pay'], 0.01,
            '兜底口径为日历天数');
    }
}
