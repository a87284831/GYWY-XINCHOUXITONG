<?php

namespace App\Http\Controllers\Api;

use App\Services\PerformanceExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Color, Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use ZipArchive;

/**
 * 绩效导出（独立模块）：GET /api/export/performance-detail[/preview]
 *
 * 与「数据导出 → ③ 绩效工资专项表」(ExportController::performance) 完全独立：
 *   - 本接口按「人员类别 + 发放法」分批输出 Sheet，并附汇总与说明；
 *   - 旧专项表保持原样，不受本控制器影响。
 */
class PerformanceExportController extends ApiController
{
    private const BAND_BASE = 'DDEBF7';
    private const BAND_ATT  = 'F2F2F2';
    private const BAND_PAY  = 'E2EFDA';
    private const BAND_DATE = 'FCE4D6';
    private const BAND_NOTE = 'FFF2CC';

    private const METHODS = ['follow', 'monthly', 'quarter', 'installment'];
    private const MODES = ['book', 'summary', 'split'];

    public function preview(Request $request): JsonResponse
    {
        $ctx = $this->prepare($request);
        if ($ctx instanceof JsonResponse) return $ctx;

        try {
            $data = $this->service()->dataset(
                $ctx['ym'], $ctx['cats'], $ctx['project'], $ctx['method'],
                $ctx['project_scope'], $ctx['scope_project']
            );
        } catch (\RuntimeException $e) {
            return $this->domainError($e);
        }

        // 预览不返回全量明细，只给 Sheet 元信息与前 3 行，避免大响应
        $data['sheets'] = array_map(fn ($s) => [
            'title' => $s['title'],
            'cat' => $s['cat'],
            'cat_label' => $s['cat_label'],
            'method' => $s['method'],
            'method_label' => $s['method_label'],
            'columns' => $s['columns'],
            'count' => count($s['entries']),
            'sample' => $s['sample'],
        ], $data['sheets']);

        return response()->json(array_merge(['ok' => true], $data));
    }

    public function export(Request $request)
    {
        $ctx = $this->prepare($request);
        if ($ctx instanceof JsonResponse) return $ctx;

        try {
            $data = $this->service()->dataset(
                $ctx['ym'], $ctx['cats'], $ctx['project'], $ctx['method'],
                $ctx['project_scope'], $ctx['scope_project']
            );
        } catch (\RuntimeException $e) {
            return $this->domainError($e);
        }

        if ($ctx['mode'] === 'split') {
            return $this->zipResponse($data, $ctx['ym']);
        }

        $book = new Spreadsheet();
        if ($ctx['mode'] === 'summary') {
            $this->fillSummarySheet($book->getActiveSheet(), $data);
        } else {
            $first = true;
            foreach ($data['sheets'] as $def) {
                $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
                $first = false;
                $this->fillDetailSheet($sheet, $def, $data['ym'], $data['quarter_months']);
            }
            if ($first) {
                $book->getActiveSheet()->setTitle('说明');
                $book->getActiveSheet()->setCellValue('A1', $data['ym'] . ' 无匹配数据');
            } else {
                $this->fillSummarySheet($book->createSheet(), $data);
                $this->fillNotesSheet($book->createSheet(), $data, $ctx);
                $book->setActiveSheetIndex(0);
            }
        }

        $filename = $data['filename'];
        if ($ctx['mode'] === 'summary') {
            $filename = '绩效导出_' . $ctx['ym'] . '_仅汇总.xlsx';
        }
        return $this->xlsx($book, $filename);
    }

    // ---------------- 参数 / 权限 ----------------

    private function service(): PerformanceExportService
    {
        return app(PerformanceExportService::class);
    }

    /** @return array|JsonResponse */
    private function prepare(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;

        $deny = $this->requireExportPerm($account);
        if ($deny) return $deny;

        $ym = trim((string) $request->string('ym')->toString());
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份无效'], 400);
        }

        $catsRaw = trim((string) $request->string('cats')->toString());
        $cats = $catsRaw === ''
            ? array_keys(PerformanceExportService::CATS)
            : array_values(array_unique(array_map('trim', explode(',', $catsRaw))));
        $cats = array_values(array_filter($cats, fn ($c) => $c !== ''));
        if (!$cats || array_diff($cats, array_keys(PerformanceExportService::CATS))) {
            return response()->json(['ok' => false, 'error' => '人员类别无效'], 422);
        }

        $method = trim((string) $request->string('method')->toString()) ?: 'follow';
        if (!in_array($method, self::METHODS, true)) {
            return response()->json(['ok' => false, 'error' => '发放法无效'], 422);
        }

        $mode = trim((string) $request->string('mode')->toString()) ?: 'book';
        if (!in_array($mode, self::MODES, true)) {
            return response()->json(['ok' => false, 'error' => '导出方式无效'], 422);
        }

        $projectScope = $this->isProjectScope($account);
        $scopeProject = $projectScope ? (string) $account->project_name : null;
        $project = $projectScope ? (string) $scopeProject : trim((string) $request->string('project')->toString());

        if ($projectScope) {
            $archived = DB::table('payroll_results')->where('year_month', $ym)
                ->where('project_name', $scopeProject)->where('archived', true)->exists();
            if (!$archived) {
                return response()->json(['ok' => false, 'error' => '本月薪资总部尚未核定，核定完成后才能导出'], 403);
            }
        }

        $svc = $this->service();
        if (!$svc->isQuarterEnd($ym)) {
            if (in_array($method, ['quarter', 'installment'], true)) {
                return response()->json([
                    'ok' => false,
                    'error' => '季度/分期兑现仅支持季度末月（4/7/10/1 月）导出',
                ], 422);
            }
            if ($method === 'follow' && array_intersect($cats, ['manager', 'hq'])) {
                return response()->json([
                    'ok' => false,
                    'error' => '跟随发放法下管理人员/总部人员需选择季度末月（4/7/10/1 月）；如只需月度口径请改用「统一按月度绩效法」',
                ], 422);
            }
        }

        return [
            'account' => $account,
            'ym' => $ym,
            'cats' => $cats,
            'project' => $project,
            'method' => $method,
            'mode' => $mode,
            'project_scope' => $projectScope,
            'scope_project' => $scopeProject,
        ];
    }

    /** 权限点 perf_export：admin 全通过；角色 permissions 含 perf_export 或 * 放行 */
    private function requireExportPerm(object $account): ?JsonResponse
    {
        if (($account->role ?? '') === 'admin') return null;
        $role = DB::table('payroll_roles')->where('role_key', $account->role)->first();
        $perms = $role ? ($this->jsonValue($role->permissions) ?: []) : [];
        if (in_array('*', $perms, true) || in_array('perf_export', $perms, true)) return null;
        return response()->json(['ok' => false, 'error' => '无绩效导出权限'], 403);
    }

    private function domainError(\RuntimeException $e)
    {
        $msg = $e->getMessage();
        return response()->json(['ok' => false, 'error' => $msg], $msg === '无核算数据' ? 404 : 422);
    }

    // ---------------- 明细 Sheet ----------------

    private function fillDetailSheet($sheet, array $def, string $ym, array $quarterMonths): void
    {
        $columns = $def['columns'];
        $count = count($columns);
        $lastCol = Coordinate::stringFromColumnIndex($count);

        $sheet->setTitle($def['title']);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $ym . ' ' . $def['title'] . ' 绩效工资明细（' . $def['method_label'] . '）');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BAND_BASE);
        $sheet->getRowDimension(1)->setRowHeight(28);

        foreach ($columns as $ci => $col) {
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($letter . '2', $col['label']);
            $st = $sheet->getStyle($letter . '2');
            $st->getFont()->setBold(true);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()
                ->setRGB($this->bandFor($ci, $col));
            $st->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                ->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(26);

        $byProject = [];
        foreach ($def['entries'] as $entry) {
            $byProject[(string) $entry['project']][] = $entry;
        }

        $r = 3;
        foreach ($byProject as $project => $entries) {
            foreach ($entries as $entry) {
                foreach ($entry['values'] as $ci => $value) {
                    $letter = Coordinate::stringFromColumnIndex($ci + 1);
                    $isRemark = $columns[$ci]['label'] === '备注';
                    $cell = $sheet->getCell($letter . $r);
                    $cell->setValue(($value === null || $value === '') ? ($isRemark ? '' : '—') : $value);
                    $st = $sheet->getStyle($letter . $r);
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                        ->setColor(new Color('D9D9D9'));
                    $type = $columns[$ci]['type'];
                    if ($type === 'money' && is_numeric($value)) {
                        $st->getNumberFormat()->setFormatCode('#,##0.00');
                        $st->getAlignment()->setHorizontal('right');
                    } elseif ($type === 'ratio' && is_numeric($value)) {
                        $st->getNumberFormat()->setFormatCode('0.00');
                        $st->getAlignment()->setHorizontal('center');
                    } elseif ($type === 'count' && is_numeric($value)) {
                        $st->getNumberFormat()->setFormatCode('0.##');
                        $st->getAlignment()->setHorizontal('center');
                    } elseif ($isRemark) {
                        $st->getAlignment()->setHorizontal('left')->setVertical('center')->setWrapText(true);
                    } else {
                        $st->getAlignment()->setHorizontal($ci <= 3 ? 'left' : 'center')->setVertical('center');
                    }
                }
                // 异常/提示：备注列红字；>1 系数整行备注提示
                if (!empty($entry['is_anomaly'])) {
                    $sheet->getStyle($lastCol . $r)->getFont()->getColor()->setRGB('C00000');
                }
                $sheet->getRowDimension($r)->setRowHeight(20);
                $r++;
            }
            $this->writeTotalRow($sheet, $r, '小计（' . $project . '） · ' . count($entries) . ' 人', $entries, $columns, self::BAND_ATT);
            $r++;
        }

        $this->writeTotalRow($sheet, $r, '总计 · ' . count($def['entries']) . ' 人', $def['entries'], $columns, self::BAND_PAY);

        $this->applyWidths($sheet, $columns);
        $sheet->freezePane('G3');
        $sheet->setAutoFilter('A2:' . $lastCol . '2');
    }

    private function writeTotalRow($sheet, int $row, string $label, array $entries, array $columns, string $fill): void
    {
        $sheet->setCellValue('A' . $row, $label);
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal('right')->setVertical('center');
        foreach ($columns as $ci => $col) {
            if ($ci < 6 || $col['type'] !== 'money') continue;
            $sum = 0.0;
            foreach ($entries as $entry) {
                $v = $entry['values'][$ci] ?? null;
                if (is_numeric($v)) $sum += (float) $v;
            }
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($letter . $row, round($sum, 2));
            $sheet->getStyle($letter . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($letter . $row)->getAlignment()->setHorizontal('right');
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
    }

    private function bandFor(int $ci, array $col): string
    {
        if ($ci <= 5) return self::BAND_BASE;
        if ($col['label'] === '备注') return self::BAND_NOTE;
        if (in_array($col['label'], ['入职日期', '转正日期'], true)) return self::BAND_DATE;
        if ($col['type'] === 'money') return self::BAND_PAY;
        return self::BAND_ATT;
    }

    private function applyWidths($sheet, array $columns): void
    {
        foreach ($columns as $ci => $col) {
            $width = match ($col['type']) {
                'money' => 14,
                'ratio' => 9,
                'count' => 10,
                default => 10,
            };
            $width = match ($col['label']) {
                '项目' => 16,
                '部门' => 12,
                '岗位' => 12,
                '薪酬档位' => 10,
                '备注' => 38,
                '入职日期', '转正日期' => 12,
                default => $width,
            };
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($width);
        }
    }

    // ---------------- 汇总 Sheet ----------------

    private function fillSummarySheet($sheet, array $data): void
    {
        $head = ['人员类别', '项目', '发放法', '人数', '固定月薪合计', '标准绩效工资',
            '本期应发绩效工资', '季度兑现', '月度发放', '半年度兑现', '备注'];
        $bands = [self::BAND_BASE, self::BAND_BASE, self::BAND_ATT, self::BAND_ATT,
            self::BAND_PAY, self::BAND_PAY, self::BAND_PAY, self::BAND_PAY, self::BAND_PAY,
            self::BAND_PAY, self::BAND_NOTE];

        $sheet->setTitle('汇总');
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', $data['ym'] . ' 绩效工资汇总（' . $data['method_label'] . '）');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BAND_BASE);
        $sheet->getRowDimension(1)->setRowHeight(30);

        foreach ($head as $ci => $label) {
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($letter . '2', $label);
            $st = $sheet->getStyle($letter . '2');
            $st->getFont()->setBold(true);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bands[$ci]);
            $st->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                ->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(26);

        $r = 3;
        $grand = $this->emptyTotals();
        foreach ($data['sheets'] as $def) {
            $byProject = [];
            foreach ($def['entries'] as $entry) {
                $byProject[(string) $entry['project']][] = $entry;
            }
            $catTotals = $this->emptyTotals();
            foreach ($byProject as $project => $entries) {
                $t = $this->sumEntries($entries);
                $this->writeSummaryRow($sheet, $r, $def['title'], (string) $project, $def['method_label'], $t);
                $r++;
                $catTotals = $this->addTotals($catTotals, $t);
                $grand = $this->addTotals($grand, $t);
            }
            $this->writeSummaryRow($sheet, $r, $def['title'], '类别小计', $def['method_label'], $catTotals, self::BAND_ATT);
            // 类别小计行不重复累计到总计（项目行已累计）
            $r++;
        }
        $this->writeSummaryRow($sheet, $r, '合计', '总计', '', $grand, self::BAND_DATE);

        foreach ([12, 16, 20, 8, 14, 14, 16, 13, 13, 13, 26] as $ci => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($w);
        }
        $sheet->freezePane('A3');
    }

    private function writeSummaryRow($sheet, int $row, string $cat, string $project, string $method, array $t, string $fill = ''): void
    {
        $values = [
            $cat, $project, $method, $t['count'], $t['fixed'], $t['standard'],
            $t['period'], $t['quarter'], $t['monthly'], $t['half'], $t['note'],
        ];
        foreach ($values as $ci => $value) {
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($letter . $row, $value);
            $st = $sheet->getStyle($letter . $row);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                ->setColor(new Color('D9D9D9'));
            if ($ci >= 4 && $ci <= 9) {
                $st->getNumberFormat()->setFormatCode('#,##0.00');
                $st->getAlignment()->setHorizontal('right');
            } elseif ($ci === 3) {
                $st->getAlignment()->setHorizontal('center');
            } else {
                $st->getAlignment()->setHorizontal('left')->setVertical('center');
            }
        }
        if ($fill !== '') {
            $sheet->getStyle("A{$row}:K{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
        }
        if ($t['note'] !== '') {
            $sheet->getStyle('K' . $row)->getFont()->getColor()->setRGB('C00000');
        }
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    private function emptyTotals(): array
    {
        return ['count' => 0, 'fixed' => 0.0, 'standard' => 0.0, 'period' => 0.0,
            'quarter' => 0.0, 'monthly' => 0.0, 'half' => 0.0, 'anomaly' => 0, 'over' => 0, 'note' => ''];
    }

    private function sumEntries(array $entries): array
    {
        $t = $this->emptyTotals();
        foreach ($entries as $entry) {
            $t['count']++;
            $t['fixed'] += (float) ($entry['fixed'] ?? 0);
            $t['standard'] += (float) ($entry['standard'] ?? 0);
            $t['period'] += (float) ($entry['period_amount'] ?? 0);
            $t['quarter'] += (float) ($entry['quarter_amount'] ?? 0);
            $t['monthly'] += (float) ($entry['monthly_amount'] ?? 0);
            $t['half'] += (float) ($entry['half_amount'] ?? 0);
            if (!empty($entry['is_anomaly'])) $t['anomaly']++;
            if (($entry['ratio'] ?? null) !== null && $entry['ratio'] > 1.0001) $t['over']++;
        }
        foreach (['fixed', 'standard', 'period', 'quarter', 'monthly', 'half'] as $k) {
            $t[$k] = round($t[$k], 2);
        }
        $notes = [];
        if ($t['anomaly'] > 0) $notes[] = '异常 ' . $t['anomaly'] . ' 人';
        if ($t['over'] > 0) $notes[] = '出勤系数>1 ' . $t['over'] . ' 人';
        $t['note'] = implode('；', $notes);
        return $t;
    }

    private function addTotals(array $a, array $b): array
    {
        foreach (['count', 'fixed', 'standard', 'period', 'quarter', 'monthly', 'half', 'anomaly', 'over'] as $k) {
            $a[$k] += $b[$k];
        }
        foreach (['fixed', 'standard', 'period', 'quarter', 'monthly', 'half'] as $k) {
            $a[$k] = round($a[$k], 2);
        }
        $notes = [];
        if ($a['anomaly'] > 0) $notes[] = '异常 ' . $a['anomaly'] . ' 人';
        if ($a['over'] > 0) $notes[] = '出勤系数>1 ' . $a['over'] . ' 人';
        $a['note'] = implode('；', $notes);
        return $a;
    }

    // ---------------- 说明 Sheet ----------------

    private function fillNotesSheet($sheet, array $data, array $ctx): void
    {
        $sheet->setTitle('说明');
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', $data['ym'] . ' 绩效导出说明与数据提示');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BAND_BASE);
        $sheet->getRowDimension(1)->setRowHeight(28);

        foreach (['项目', '内容'] as $ci => $label) {
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($letter . '2', $label);
            $sheet->getStyle($letter . '2')->getFont()->setBold(true);
            $sheet->getStyle($letter . '2')->getAlignment()->setHorizontal('center');
            $sheet->getStyle($letter . '2')->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::BAND_ATT);
        }

        $rows = [
            ['导出参数', '核算月份 ' . $data['ym'] . '；季度口径 ' . ($data['quarter'] ?: '—')
                . '；项目范围 ' . ($ctx['project'] === '' ? '全部项目' : $ctx['project'])
                . '；发放法 ' . $data['method_label'] . '；人员类别 '
                . implode('、', array_map(fn ($c) => PerformanceExportService::CATS[$c], $ctx['cats'])) . '。'],
            ['周期归属', '季度按结算月归属：4 月→Q1、7 月→Q2、10 月→Q3、1 月→上年 Q4；季度数据取季度末月工资行。'],
            ['月度系数', '取自考勤块 payroll_attendance.rows[].coef，缺省 1.0。'],
            ['季度系数', '取 payroll_period_coefs（period_type=quarterly）；录入时双写季度末月 row_data.coef，仅历史行缺表记录时才回退行内 coef。'],
            ['系数取值', '0 按 0 计、1.0 按 1.0 计，不再视为未录入；仅空值 / 缺档位 / 未配发放规则才标异常。'],
            ['出勤系数', 'Σ转正出勤天数 ÷ Σ应出勤天数，保留 2 位小数；不封顶，>1.00 在备注列与提示区列出。'],
            ['月度绩效法', '当月金额 = Σ段((固定月薪−基本工资) × 月系数 × 出勤天数/应出勤天数)，无季度列。'],
            ['季度绩效法', '季度末月金额 = ROUND(标准绩效工资 × 出勤系数 × 季度系数, 2)；季度中月份不发放。'],
            ['分期兑现绩效法', '季度末月金额 = ROUND(Σ(月基数×当月出勤率) × 季度系数 × 职级季度比例, 2)；7 月/1 月另加半年度部分。'],
            ['统一发放法', '口径视图并按人员实际发放法过滤：统一季度剔除月度绩效法人员、统一月度剔除季度绩效法人员，均不计入本表与汇总人数/金额；分期兑现视图不剔除，非分期人员显示「—」并备注。'],
            ['权限', '项目账号仅能导出本项目基层/案场且须总部核定（归档）后；管理人员与总部仅总部账号可见。'],
        ];
        if ($data['ratio_over_one']) {
            $names = array_map(fn ($r) => $r['name'] . '(' . number_format($r['ratio'], 2) . ')', $data['ratio_over_one']);
            $rows[] = ['出勤系数>1', implode('、', $names)];
        }

        $r = 3;
        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $r, $row[0]);
            $sheet->setCellValue('B' . $r, $row[1]);
            $sheet->mergeCells("B{$r}:D{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:D{$r}")->getAlignment()
                ->setVertical('top')->setWrapText(true);
            $sheet->getStyle("A{$r}:D{$r}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
            $sheet->getRowDimension($r)->setRowHeight(30);
            $r++;
        }

        $r++;
        $sheet->setCellValue('A' . $r, '数据提示');
        $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(12);
        $r++;
        foreach ($data['warnings'] as $warning) {
            $label = match ($warning['level']) {
                'danger' => '异常', 'warn' => '提示', 'ok' => '正常', default => '说明',
            };
            $sheet->setCellValue('A' . $r, $label);
            $sheet->setCellValue('B' . $r, $warning['text']);
            $sheet->mergeCells("B{$r}:D{$r}");
            $sheet->getStyle("A{$r}:D{$r}")->getAlignment()
                ->setVertical('top')->setWrapText(true);
            if ($warning['level'] === 'danger') {
                $sheet->getStyle("A{$r}:D{$r}")->getFont()->getColor()->setRGB('C00000');
            }
            $sheet->getRowDimension($r)->setRowHeight(30);
            $r++;
        }

        foreach ([30, 55, 25, 25] as $ci => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($w);
        }
    }

    // ---------------- 输出 ----------------

    private function xlsx(Spreadsheet $book, string $filename)
    {
        $base = tempnam(sys_get_temp_dir(), 'perf_export_');
        $tmp = $base . '.xlsx';
        @unlink($base);
        (new Xlsx($book))->save($tmp);
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function zipResponse(array $data, string $ym)
    {
        $base = tempnam(sys_get_temp_dir(), 'perf_export_');
        $zipPath = $base . '.zip';
        @unlink($base);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($data['sheets'] as $def) {
            $book = new Spreadsheet();
            $this->fillDetailSheet($book->getActiveSheet(), $def, $ym, $data['quarter_months']);
            $tmp = tempnam(sys_get_temp_dir(), 'perf_split_') . '.xlsx';
            (new Xlsx($book))->save($tmp);
            $zip->addFromString($def['title'] . '_' . $ym . '.xlsx', (string) file_get_contents($tmp));
            @unlink($tmp);
        }
        $zip->close();
        return response()->download($zipPath, '绩效导出_' . $ym . '_分类.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
