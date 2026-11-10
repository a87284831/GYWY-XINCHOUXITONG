<?php

namespace Tests\Feature;

use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 第二阶段：统计列存值锚定 + 上传一致性校验 + 符号失配告警 + 改属性二次确认。
 *
 * 4 个虚拟员工（月份 2026-09，30 天）：
 *  甲 转正日 9/13（月中）
 *  乙 整月试用（转正日 10/15）
 *  丙 整月正式
 *  丁 转正日 9/14，且 9/14 当天为「休」
 * 每人另造一条 2026-10 的"统计列 ≠ 格子数"考勤，验证核算按存值锚定（不再被格子反推虚高）。
 */
class StoredAttendanceAnchorTest extends TestCase
{
    use RefreshDatabase;

    private const YM = '2026-09';
    private const YM2 = '2026-10';
    private const PROJ = '测试项目';

    private array $symbolItems;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $rules = [
            'base_salary' => ['segment_by_date' => true, 'prorate_base' => 'required'],
            'performance' => ['enabled' => true, 'probation_excluded' => true],
            'sick_pay' => ['enabled' => false],
            'deduction_rules' => [
                'miss_punch' => ['enabled' => false],
                'late_early' => ['enabled' => false],
                'absent' => ['enabled' => false],
            ],
            'tax' => ['basic_deduction' => 5000, 'cum_start' => 'jan', 'brackets' => [
                [36000, 0.03, 0], [144000, 0.1, 2520], [300000, 0.2, 16920], [420000, 0.25, 31920],
                [660000, 0.3, 52920], [960000, 0.35, 85920], [99999999999, 0.45, 181920],
            ]],
        ];
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'calc_rules.json',
            'payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $def = [
            '√' => ['出勤', true, true, 1.0, '正常'],
            '休' => ['休息日', false, false, 0.0, '公休'],
            '事' => ['事假', true, false, 0.0, '事假'],
            '病' => ['病假', true, false, 0.0, '病假'],
            '旷' => ['旷工', true, false, 0.0, '旷工'],
            '缺' => ['缺卡', true, true, 1.0, '缺卡'],
            '迟' => ['迟到', true, true, 1.0, '迟到'],
        ];
        $this->symbolItems = [];
        foreach ($def as $symbol => [$name, $req, $act, $value, $cat]) {
            $this->symbolItems[] = ['symbol' => $symbol, 'name' => $name, 'desc' => '',
                'in_required' => $req, 'in_actual' => $act, 'value' => $value, 'category' => $cat];
        }
        DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'symbols.json',
            'payload' => json_encode(['items' => $this->symbolItems], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('payroll_projects')->insert([
            'name' => self::PROJ, 'status' => '启用', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminId = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => 9001, 'username' => 'hq_admin', 'name' => '总部管理员',
            'role' => 'admin', 'project_name' => null, 'password_hash' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminToken = str_repeat('c', 64);
        Cache::put('payroll_api_token:' . $this->adminToken, $adminId, now()->addHours(8));
    }

    private function money(float $expected, $actual, string $msg = ''): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.001, $msg);
    }

    /** 建一个虚拟员工（含调薪历史与转正日） */
    private function staff(string $name, string $regular, string $hire, array $history, int $id): void
    {
        $last = $history[count($history) - 1];
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => $name, 'project_name' => self::PROJ, 'position' => '岗位',
            'status' => '正式', 'person_type' => 'staff', 'deleted' => false,
            'fixed_monthly' => $last['fixed_monthly'], 'base_salary' => $last['base_salary'],
            'hire_date' => $hire, 'regular_date' => $regular,
            'data' => json_encode([
                'salary_history' => $history, 'special_deductions' => [],
                'regular_date' => $regular, 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 直接写考勤块（locked=true 才会被核算读取） */
    private function seedAttendance(string $ym, array $byName): void
    {
        $rows = [];
        foreach ($byName as $name => $r) {
            $rows[$name] = array_merge([
                'days' => $r['days'],
                'req_attend' => $r['req'] ?? null,
                'act_attend' => $r['act'] ?? null,
                'coef' => null, 'remark' => '',
                'reward' => 0, 'punish' => 0, 'meal_sub' => 0, 'night_sub' => 0, 'title_sub' => 0,
                'pen' => 0, 'med' => 0, 'une' => 0, 'house' => 0, 'big' => 0,
                'miss_deduct' => 0, 'late_deduct' => 0, 'other_deduct' => 0, 'uniform_deduct' => 0,
                'welfare' => 0, 'backpay' => 0,
            ], $r['extra'] ?? []);
        }
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => $ym . '|' . self::PROJ],
            ['year_month' => $ym, 'project_name' => self::PROJ,
             'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'locked' => true,
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function calc(string $ym): array
    {
        (new PayrollCalculator())->calculate($ym, [self::PROJ]);
        return DB::table('payroll_results')->where('year_month', $ym)->get()
            ->mapWithKeys(fn ($r) => [(int) $r->staff_legacy_id => json_decode($r->row_data, true)])->all();
    }

    /** 9 月：四个虚拟员工 + 月内考勤 */
    private function seedSeptember(): void
    {
        $this->staff('甲', '2026-09-13', '2026-08-01', [
            ['effective_date' => '2026-08-01', 'fixed_monthly' => 10000, 'base_salary' => 6000, 'type' => '初始', 'note' => ''],
            ['effective_date' => '2026-09-13', 'fixed_monthly' => 12000, 'base_salary' => 8000, 'type' => '转正', 'note' => ''],
        ], 1);
        $this->staff('乙', '2026-10-15', '2026-09-01', [
            ['effective_date' => '2026-09-01', 'fixed_monthly' => 9000, 'base_salary' => 5000, 'type' => '初始', 'note' => ''],
        ], 2);
        $this->staff('丙', '2026-07-01', '2026-06-01', [
            ['effective_date' => '2026-06-01', 'fixed_monthly' => 15000, 'base_salary' => 10000, 'type' => '初始', 'note' => ''],
        ], 3);
        $this->staff('丁', '2026-09-14', '2026-08-01', [
            ['effective_date' => '2026-08-01', 'fixed_monthly' => 11000, 'base_salary' => 7000, 'type' => '初始', 'note' => ''],
            ['effective_date' => '2026-09-14', 'fixed_monthly' => 13000, 'base_salary' => 9000, 'type' => '转正', 'note' => ''],
        ], 4);

        $grid = [];
        // 甲：1-12 → 10√+2休；13-30 → 16√+2休（应出勤/实际 26）
        for ($d = 1; $d <= 30; $d++) $grid['甲'][$d - 1] = ($d <= 12 ? ($d <= 10 ? '√' : '休') : ($d <= 28 ? '√' : '休'));
        // 乙：1-22 出勤，23-30 休（整月试用）
        for ($d = 1; $d <= 30; $d++) $grid['乙'][$d - 1] = $d <= 22 ? '√' : '休';
        // 丙：1-24 出勤，25-30 休（整月正式）
        for ($d = 1; $d <= 30; $d++) $grid['丙'][$d - 1] = $d <= 24 ? '√' : '休';
        // 丁：1-11√ 12-13休；14（转正日）休；15-29√ 30休
        for ($d = 1; $d <= 30; $d++) {
            $grid['丁'][$d - 1] = $d <= 11 ? '√' : (($d <= 14) ? '休' : ($d <= 29 ? '√' : '休'));
        }

        $this->seedAttendance(self::YM, [
            '甲' => ['days' => $grid['甲'], 'req' => 26, 'act' => 24], // 统计列 24 ≠ 格子 26：核算按存值 24
            '乙' => ['days' => $grid['乙'], 'req' => 22, 'act' => 22],
            '丙' => ['days' => $grid['丙'], 'req' => 24, 'act' => 24],
            '丁' => ['days' => $grid['丁'], 'req' => 26, 'act' => 26],
        ]);
    }

    public function test_segment_attendance_is_anchored_to_stored_month_value(): void
    {
        $this->seedSeptember();
        $rows = $this->calc(self::YM);

        // 甲：段出勤合计 = 存值 24（格子 26），基本/绩效按段占比拆分
        $r = $rows[1];
        $segAnchor = [24 * 10 / 26, 24 - 24 * 10 / 26];
        $this->assertSame(24.0, round(array_sum($segAnchor), 2), '各段出勤之和必须等于整月存值');
        $this->money(26, $r['req_att']);
        $this->money(24, $r['act_att']);
        $this->money(6674.56, $r['base_pay'], '基本工资 = 6000×9.23/26 + 8000×14.77/26');
        $this->money(2272.19, $r['perf_pay'], '绩效只算正式段');
        $this->money(8946.75, $r['gross']);
        $this->money(14.77, $r['perf_att']);

        // 乙：整月试用 → 绩效 0，基本按 22/22 全额
        $r = $rows[2];
        $this->money(5000, $r['base_pay']);
        $this->money(0, $r['perf_pay']);
        $this->money(5000, $r['gross']);
        $this->money(0, $r['perf_att']);

        // 丙：整月正式
        $r = $rows[3];
        $this->money(10000, $r['base_pay']);
        $this->money(5000, $r['perf_pay']);
        $this->money(15000, $r['gross']);
        $this->money(24, $r['perf_att']);

        // 丁：转正日当天是「休」，正式段 15 天；各段之和 = 26
        $r = $rows[4];
        $this->money(8153.85, $r['base_pay'], '7000×11/26 + 9000×15/26');
        $this->money(2307.69, $r['perf_pay'], '4000×15/26');
        $this->money(10461.54, $r['gross']);
        $this->money(15, $r['perf_att']);
        $this->money(13.85, $r['actual_tax'], '累计扣除 10000 后 461.54×3%');
        $this->money(10447.69, $r['net']);
    }

    /** 第二阶段核心：改符号属性（把「休」改成计薪出勤）后重算，金额不能虚高 */
    public function test_symbol_property_change_does_not_inflate_stored_amounts(): void
    {
        $this->seedSeptember();
        $before = $this->calc(self::YM);

        // 把「休」改成 in_actual=true, value=1（第一阶段会按格子 30 天放大）
        $items = $this->symbolItems;
        foreach ($items as &$it) {
            if ($it['symbol'] === '休') { $it['in_actual'] = true; $it['value'] = 1.0; }
        }
        unset($it);
        DB::table('legacy_json_snapshots')->where('file_name', 'symbols.json')
            ->update(['payload' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)]);

        $after = $this->calc(self::YM);

        // 乙整月试用：格子从 22 变成 30，但存值 22 锚定 → 基本工资不变（不虚高）
        $this->money(5000, $after[2]['base_pay'], '存值 22 锚定，不能按格子 30 放大成 6818.18');
        $this->money($before[2]['base_pay'], $after[2]['base_pay']);
        // 丁：出勤合计仍为存值 26（不是格子 30/32），绩效不虚高（未锚定时会是 4000×17/26=2615.38）
        $this->money(26, $after[4]['act_att']);
        $this->money(8133.33, $after[4]['base_pay'], '段权重随格子变为 13/30、17/30，总额仍锚定 26');
        $this->money(2266.67, $after[4]['perf_pay']);
        $this->assertLessThan(2615.38, $after[4]['perf_pay'], '属性改动后绩效不得按格子数放大');
        $this->money(10400, $after[4]['gross']);
    }

    /** 统计列 ≠ 格子数时：核算按存值，不被格子反推 */
    public function test_inconsistent_stored_value_drives_calculation(): void
    {
        $this->seedSeptember();
        $this->calc(self::YM);

        // 2026-10：乙在 10/15 转正，薪资同步上调
        DB::table('payroll_staff')->where('legacy_id', 2)->update([
            'regular_date' => '2026-10-15',
            'fixed_monthly' => 11000, 'base_salary' => 7000,
            'data' => json_encode([
                'salary_history' => [
                    ['effective_date' => '2026-09-01', 'fixed_monthly' => 9000, 'base_salary' => 5000, 'type' => '初始', 'note' => ''],
                    ['effective_date' => '2026-10-15', 'fixed_monthly' => 11000, 'base_salary' => 7000, 'type' => '转正', 'note' => ''],
                ],
                'special_deductions' => [], 'regular_date' => '2026-10-15', 'resign_date' => '',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $days = [];
        for ($d = 1; $d <= 31; $d++) {
            $days['甲'][$d - 1] = $d <= 22 ? '√' : '休';
            $days['乙'][$d - 1] = $d <= 14 ? ($d <= 10 ? '√' : '休') : ($d <= 28 ? '√' : '休');
            $days['丙'][$d - 1] = $d <= 24 ? '√' : '休';
            $days['丁'][$d - 1] = $d <= 26 ? '√' : '休';
        }
        $this->seedAttendance(self::YM2, [
            '甲' => ['days' => $days['甲'], 'req' => 22, 'act' => 18],
            '乙' => ['days' => $days['乙'], 'req' => 24, 'act' => 20],
            '丙' => ['days' => $days['丙'], 'req' => 24, 'act' => 20],
            '丁' => ['days' => $days['丁'], 'req' => 26, 'act' => 22],
        ]);

        $rows = $this->calc(self::YM2);
        // 甲：单段，直接等于存值 18（格子 22）
        $this->money(18, $rows[1]['act_att']);
        $this->money(round(8000 * 18 / 22, 2), $rows[1]['base_pay']);
        $this->money(round(4000 * 18 / 22, 2), $rows[1]['perf_pay']);
        // 乙：两段（1-14 试用 / 15-31 正式），段出勤合计 = 存值 20
        $this->money(20, $rows[2]['act_att']);
        $seg1 = 20 * 10 / 24;
        $seg2 = 20 - $seg1;
        $this->money(round(5000 * $seg1 / 24 + 7000 * $seg2 / 24, 2), $rows[2]['base_pay']);
        $this->money(round(4000 * $seg2 / 24, 2), $rows[2]['perf_pay']);
    }

    /** 存值 0 就是 0：不能被格子反推成满勤 */
    public function test_stored_zero_actual_yields_zero_attendance_pay(): void
    {
        $this->seedSeptember();
        $days = array_fill(0, 30, '√');
        $this->seedAttendance(self::YM, [
            '甲' => ['days' => $days, 'req' => 30, 'act' => 0],
            '乙' => ['days' => $days, 'req' => 30, 'act' => 0],
            '丙' => ['days' => $days, 'req' => 30, 'act' => 0],
            '丁' => ['days' => $days, 'req' => 30, 'act' => 0],
        ]);
        $rows = $this->calc(self::YM);
        $this->money(0, $rows[3]['base_pay']);
        $this->money(0, $rows[3]['perf_pay']);
        $this->money(0, $rows[3]['gross']);
    }

    /** 存值缺失（null）才回退数格子 */
    public function test_null_stored_value_falls_back_to_grid(): void
    {
        $this->seedSeptember();
        $days = array_fill(0, 30, '√');
        $this->seedAttendance(self::YM, [
            '甲' => ['days' => $days, 'req' => null, 'act' => null],
            '乙' => ['days' => $days, 'req' => null, 'act' => null],
            '丙' => ['days' => $days, 'req' => null, 'act' => null],
            '丁' => ['days' => $days, 'req' => null, 'act' => null],
        ]);
        $rows = $this->calc(self::YM);
        $this->money(30, $rows[3]['req_att']);
        $this->money(30, $rows[3]['act_att']);
        $this->money(10000, $rows[3]['base_pay']);
    }

    /** 构造最小可解析考勤 xlsx（表头在第 3 行） */
    private function makeWorkbook(array $people): string
    {
        $days = 30;
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B3', '姓名')->setCellValue('C3', '人员状态')->setCellValue('D3', '岗位');
        for ($d = 1; $d <= $days; $d++) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4 + $d) . '3', (string) $d);
        }
        $col = 5 + $days;
        $headers = ['应出勤(手填)', '实际出勤(天)', '事假(天)', '病假(天)', '产假(天)', '带薪假(天)', '缺卡(次)', '旷工(天)', '迟到(次)', '早退(次)', '月度奖励'];
        $letters = [];
        foreach ($headers as $h) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}3", $h);
            $letters[$h] = $letter;
        }
        $r = 5;
        foreach ($people as $p) {
            $sheet->setCellValue("B{$r}", $p['name']);
            $sheet->setCellValue("C{$r}", '正式');
            for ($d = 1; $d <= $days; $d++) {
                if (!empty($p['days'][$d - 1])) {
                    $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4 + $d) . $r, $p['days'][$d - 1]);
                }
            }
            foreach ($p['stats'] ?? [] as $h => $v) {
                $sheet->setCellValue($letters[$h] . $r, $v);
            }
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'attanchor') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/attendance/upload', [
            'ym' => self::YM, 'project' => self::PROJ,
            'file' => new UploadedFile($path, 'att.xlsx', null, null, true),
        ] + $extra, ['X-Token' => $this->adminToken]);
    }

    private function seedUploadStaff(): array
    {
        $this->staff('上传甲', '2026-07-01', '2026-06-01', [
            ['effective_date' => '2026-06-01', 'fixed_monthly' => 10000, 'base_salary' => 8000, 'type' => '初始', 'note' => ''],
        ], 11);
        $days = array_fill(0, 30, '休');
        for ($d = 0; $d < 22; $d++) $days[$d] = '√';
        return $days;
    }

    public function test_upload_rejects_stat_column_mismatch_with_readable_message(): void
    {
        $days = $this->seedUploadStaff();
        $path = $this->makeWorkbook([['name' => '上传甲', 'days' => $days, 'stats' => [
            '应出勤(手填)' => 22, '实际出勤(天)' => 25,
        ]]]);
        try {
            $resp = $this->upload($path, ['dry_run' => 1]);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(400);
        $msg = $resp->json('error');
        $this->assertStringContainsString('第5行 上传甲', $msg);
        $this->assertStringContainsString('实际出勤列填 25，按符号统计为 22', $msg);
        $this->assertStringContainsString('请核对格子符号', $msg);
        $this->assertStringContainsString('att.xlsx', $msg);
        $this->assertStringContainsString('项目 ' . self::PROJ, $msg);
        $this->assertStringContainsString('月份 ' . self::YM, $msg);
        // 校验口径锁定在「实际出勤」：条目字段/Label 必须是 act_attend，不能串回应出勤
        $item = $resp->json('error_items.0');
        $this->assertSame('act_attend', $item['field']);
        $this->assertSame('实际出勤', $item['label']);
        $items = json_encode($resp->json('error_items'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('应出勤', $items, '应出勤(手填) 是月级手填值，不参与符号数格子校验');
    }

    /**
     * 应出勤(手填) 与格子里的 in_required 符号数不同属于正常业务（人力填当月标准工作日），
     * 不得据此整单拒绝；实际出勤匹配时就应正常通过并保留应出勤存值。
     */
    public function test_upload_ignores_req_attend_handfill_mismatch(): void
    {
        $days = $this->seedUploadStaff(); // 22 个 √ + 8 个 休 → required=22 / attend=22
        $path = $this->makeWorkbook([['name' => '上传甲', 'days' => $days, 'stats' => [
            '应出勤(手填)' => 26, '实际出勤(天)' => 22,
        ]]]);
        try {
            $resp = $this->upload($path);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(200);
        $this->assertTrue($resp->json('ok'));
        $this->assertNull($resp->json('error_items'));
        $row = json_decode(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . self::PROJ)->value('rows'), true)['上传甲'];
        $this->money(26, $row['req_attend'], '应出勤手填值仍须原样落库');
        $this->money(22, $row['act_attend']);
    }

    /** 实际出勤显式填 0 是真实值（存值锚定），必须参与比对而不是当作空跳过 */
    public function test_upload_checks_explicit_zero_actual_attend(): void
    {
        $days = $this->seedUploadStaff();
        $path = $this->makeWorkbook([['name' => '上传甲', 'days' => $days, 'stats' => [
            '实际出勤(天)' => 0,
        ]]]);
        try {
            $resp = $this->upload($path, ['dry_run' => 1]);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(400);
        $this->assertStringContainsString('实际出勤列填 0，按符号统计为 22', (string) $resp->json('error'));
        $this->assertTrue($resp->json('can_force'));
    }

    /**
     * 上传乱序（王五 → 张三 → 李四，与姓名/工号/部门排序都不同）后，
     * 页面查看与导出都必须保持 Excel 行序（MySQL JSON 会重排对象键，靠 data.order 还原）。
     */
    public function test_upload_keeps_excel_row_order_for_view_and_export(): void
    {
        $days = array_fill(0, 30, '休');
        for ($i = 0; $i < 22; $i++) $days[$i] = '√';
        foreach ([['王五', 21], ['张三', 22], ['李四', 23]] as [$nm, $id]) {
            $this->staff($nm, '2026-07-01', '2026-06-01', [
                ['effective_date' => '2026-06-01', 'fixed_monthly' => 9000, 'base_salary' => 7000, 'type' => '初始', 'note' => ''],
            ], $id);
        }
        $path = $this->makeWorkbook([
            ['name' => '王五', 'days' => $days],
            ['name' => '张三', 'days' => $days],
            ['name' => '李四', 'days' => $days],
        ]);
        try {
            $resp = $this->upload($path);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(200);

        // 页面查看：order 严格等于 Excel 行序
        $view = $this->get('/api/attendance?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->adminToken]);
        $view->assertOk();
        $this->assertSame(['王五', '张三', '李四'], $view->json('order'), '页面必须按上传表格行序渲染');

        // 导出（需锁定）：姓名列同样按上传行序
        DB::table('payroll_attendance')->where('record_key', self::YM . '|' . self::PROJ)->update(['locked' => true]);
        $export = $this->get('/api/attendance/export?ym=' . self::YM . '&project=' . self::PROJ, ['X-Token' => $this->adminToken]);
        $export->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $export->streamedContent());
        $grid = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getSheet(0)->toArray(null, true, false, false);
        @unlink($tmp);
        $names = [];
        foreach ($grid as $row) {
            $n = trim((string) ($row[3] ?? ''));
            if ($n !== '' && $n !== '姓名') $names[] = $n;
        }
        $this->assertSame(['王五', '张三', '李四'], $names, '导出必须保持上传表格行序');
    }

    public function test_upload_force_stats_keeps_column_value_and_returns_warning(): void
    {
        $days = $this->seedUploadStaff();
        $path = $this->makeWorkbook([['name' => '上传甲', 'days' => $days, 'stats' => [
            '应出勤(手填)' => 22, '实际出勤(天)' => 25,
        ]]]);
        try {
            $resp = $this->upload($path, ['force_stats' => 1, 'dry_run' => 1]);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('warnings'));
    }

    public function test_upload_blank_cells_are_stored_as_null(): void
    {
        $days = $this->seedUploadStaff();
        $path = $this->makeWorkbook([['name' => '上传甲', 'days' => $days, 'stats' => [
            '应出勤(手填)' => 22, '实际出勤(天)' => 22, '事假(天)' => 0,
        ]]]);
        try {
            $resp = $this->upload($path);
        } finally {
            @unlink($path);
        }
        $resp->assertStatus(200);
        $row = json_decode(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . self::PROJ)->value('rows'), true)['上传甲'];
        $this->assertArrayHasKey('reward', $row);
        $this->assertNull($row['reward'], '空单元格必须是 null，不能写 0');
        $this->assertNull($row['stat_sick'], '空统计列必须是 null');
        $this->money(0, $row['stat_personal'], '填了 0 的统计列必须存 0，不能写成 null');
        $this->money(22, $row['act_attend']);
    }

    /** 改 in_actual 触发未归档月份核算变化 → 必须二次确认 */
    public function test_symbol_property_change_requires_confirmation(): void
    {
        $this->seedSeptember();
        $items = $this->symbolItems;
        foreach ($items as &$it) {
            if ($it['symbol'] === '休') { $it['in_actual'] = true; }
        }
        unset($it);

        $resp = $this->post('/api/symbols/save', ['items' => $items], ['X-Token' => $this->adminToken]);
        $resp->assertStatus(409);
        $this->assertTrue($resp->json('need_confirm'));
        $this->assertArrayHasKey(self::YM, $resp->json('affected.months'));
        $this->assertGreaterThan(0, $resp->json('affected.people'));

        $ok = $this->post('/api/symbols/save', ['items' => $items, 'confirm' => 1], ['X-Token' => $this->adminToken]);
        $ok->assertStatus(200);
        $this->assertTrue($ok->json('ok'));
    }

    /** 考勤页提示条数据源：warnings_only 轻量返回未知符号告警与处数 */
    public function test_attendance_api_exposes_symbol_warning_count(): void
    {
        $this->seedSeptember();
        $rows = json_decode(DB::table('payroll_attendance')->where('record_key', self::YM . '|' . self::PROJ)->value('rows'), true);
        $rows['甲']['days'][4] = '★'; // 9/5 放一个符号库中不存在的字符
        DB::table('payroll_attendance')->where('record_key', self::YM . '|' . self::PROJ)
            ->update(['rows' => json_encode($rows, JSON_UNESCAPED_UNICODE)]);

        $light = $this->get('/api/attendance?ym=' . self::YM . '&project=' . urlencode(self::PROJ) . '&warnings_only=1',
            ['X-Token' => $this->adminToken]);
        $light->assertOk();
        $this->assertSame('甲', $light->json('symbol_warnings.0.name'));
        $this->assertSame(1, $light->json('symbol_warnings.0.count'));
        $this->assertStringContainsString('★', (string) $light->json('symbol_warnings.0.detail'));
        $this->assertArrayNotHasKey('rows', $light->json(), 'warnings_only 不应返回整表行数据');

        $full = $this->get('/api/attendance?ym=' . self::YM . '&project=' . urlencode(self::PROJ),
            ['X-Token' => $this->adminToken]);
        $full->assertOk();
        $this->assertSame(1, $full->json('symbol_warnings.0.count'));
    }
}
