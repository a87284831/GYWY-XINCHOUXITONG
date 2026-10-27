<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Color, Fill, Font};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * 全年工资发放明细统计（只读，不影响核算流程）
 *
 * 口径（2026-10-27 与业务确认）：
 * - 明细横向一人一行：项目|部门|岗位|姓名|员工状态|入职日期|离职日期|1-12月应发|全年应发
 * - 应发 = row_data.gross；只统计工资发放记录，离职人员照常纳入
 * - 全年发薪人数 = 当年≥1条工资记录的人数（含离职）；平均工资 = 全年应发总额 ÷ 全年发薪人数
 * - 上月实际发薪人数/上月应发总额 = 导出时上一自然月（与所选年份无关，恒为真实上月）
 * - 同一人同月存在多行时（历史重算残留），优先取 archived=false 的最新行，避免重复累计
 */
class PayrollYearController extends ApiController
{
    /** GET /api/payroll/annual?year=2026&project=&name= */
    public function data(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = $this->year($request);
        $view = $this->buildView($account, $request, $year);
        return response()->json(array_merge(['ok' => true, 'year' => $year], $view));
    }

    /** GET /api/payroll/annual/export?year=2026&project=&name= */
    public function export(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = $this->year($request);
        $view = $this->buildView($account, $request, $year);
        if (empty($view['rows'])) {
            return response()->json(['ok' => false, 'error' => '该年度无工资发放数据'], 404);
        }
        $project = $this->projectFilter($account, $request);
        $book = new Spreadsheet();
        $this->fillSummarySheet($book->getActiveSheet(), $view, $year);
        $detail = $book->createSheet();
        $this->fillDetailSheet($detail, $view, $year, $project);
        $name = '全年发放明细_' . $year . ($project !== '' ? '_' . $project : '') . '.xlsx';
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ==================== 数据构建 ====================

    private function year(Request $request): int
    {
        $y = (int) $request->input('year', date('Y'));
        return ($y >= 2020 && $y <= 2100) ? $y : (int) date('Y');
    }

    private function projectFilter(object $account, Request $request): string
    {
        if ($this->isProjectScope($account)) return (string) $account->project_name;
        return $request->string('project')->toString();
    }

    /** 同一人同月多行去重：archived=false 优先，其次取 id 更大（更新）的行 */
    private function pickRow(array $candidates): ?object
    {
        usort($candidates, function ($a, $b) {
            $aa = $a->archived ? 1 : 0;
            $bb = $b->archived ? 1 : 0;
            if ($aa !== $bb) return $aa - $bb;          // archived=false 在前
            return $b->id <=> $a->id;                    // 再取 id 大的
        });
        return $candidates[0] ?? null;
    }

    private function personKey(object $r): string
    {
        $sid = (int) ($r->staff_legacy_id ?? 0);
        return $sid > 0 ? 's:' . $sid : 'n:' . ($r->project_name ?? '') . ':' . ($r->name ?? '');
    }

    /** @return array{rows: array, months: array, prev_ym: string, prev_rows: array} */
    private function buildView(object $account, Request $request, int $year): array
    {
        $query = DB::table('payroll_results')->where('year_month', 'like', $year . '-%');
        $project = $this->projectFilter($account, $request);
        if ($project !== '') $query->where('project_name', $project);
        $name = trim((string) $request->input('name', ''));
        if ($name !== '') {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.name')) LIKE ?", ['%' . $name . '%']);
        }
        $raw = $query->orderBy('year_month')->get();

        // 按人+月去重
        $byKeyMonth = [];
        foreach ($raw as $r) {
            $byKeyMonth[$this->personKey($r)][$r->year_month][] = $r;
        }

        // 人员档案（离职状态/入职离职日期以档案为准）
        $staffIds = collect($raw)->pluck('staff_legacy_id')->filter()->unique()->values()->all();
        $staffMap = $staffIds
            ? DB::table('payroll_staff')->whereIn('legacy_id', $staffIds)->get()->keyBy('legacy_id')
            : collect();

        $months = [];
        for ($m = 1; $m <= 12; $m++) $months[] = sprintf('%d-%02d', $year, $m);

        $people = [];
        foreach ($byKeyMonth as $key => $monthRows) {
            $picked = [];
            foreach ($monthRows as $ym => $cands) {
                $row = $this->pickRow($cands);
                if ($row) $picked[$ym] = $row;
            }
            if (!$picked) continue;
            $latestYm = max(array_keys($picked));
            $latest = $picked[$latestYm];
            $latestData = $this->jsonValue($latest->row_data) ?: [];

            $staff = $staffMap->get((int) ($latest->staff_legacy_id ?? 0));
            $resigned = $staff
                ? (($staff->status ?? '') === '离职')
                : ((string) ($latestData['status'] ?? '') === '离职');

            $monthly = [];
            $totalGross = 0.0;
            foreach ($months as $ym) {
                $g = 0.0;
                if (isset($picked[$ym])) {
                    $d = $this->jsonValue($picked[$ym]->row_data) ?: [];
                    $g = round((float) ($d['gross'] ?? 0), 2);
                }
                $monthly[$ym] = $g;
                $totalGross += $g;
            }

            $people[] = [
                'key' => $key,
                'project' => (string) ($latestData['project'] ?? $latest->project_name ?? ''),
                'department' => (string) ($latestData['department'] ?? ''),
                'position' => (string) ($latestData['position'] ?? ($staff->position ?? '')),
                'name' => (string) ($latestData['name'] ?? $latest->name ?? ($staff->name ?? '')),
                'resigned' => $resigned,
                'status_label' => $resigned ? '离职' : '在职',
                'hire_date' => $staff->hire_date ?? ($latestData['hire_date'] ?? ''),
                'resign_date' => ($resigned && $staff && $staff->resign_date) ? substr((string) $staff->resign_date, 0, 10) : '',
                'monthly' => $monthly,
                'total_gross' => round($totalGross, 2),
                'picked_months' => array_keys($picked),
            ];
        }

        // 项目→部门→岗位→姓名 排序（与系统其余视图一致）
        $people = \App\Services\PayrollCalculator::orderRows($people);

        // 上月口径：恒为真实上一自然月（与所选年份无关，单独查询）
        $prevYm = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $prevRows = $this->monthStats($prevYm, $project, $name);

        // 汇总：按项目分组
        $grouped = collect($people)->groupBy(fn ($p) => $p['project'] !== '' ? $p['project'] : '未分配');
        $summary = $grouped->map(function ($ps, $proj) use ($prevYm, $prevRows) {
            $total = round($ps->sum('total_gross'), 2);
            $cnt = $ps->count();
            $prevCnt = $prevRows['by_project'][$proj]['cnt'] ?? 0;
            $prevGross = $prevRows['by_project'][$proj]['gross'] ?? 0;
            return [
                'project' => $proj,
                'headcount' => $cnt,
                'total_gross' => $total,
                'avg_gross' => $cnt > 0 ? round($total / $cnt, 2) : 0,
                'prev_headcount' => $prevCnt,
                'prev_gross' => $prevGross,
            ];
        })->values()->all();

        // 明细行（剥离内部字段）
        $list = collect($people)->map(function ($p) use ($months) {
            $monthly = [];
            foreach ($months as $ym) $monthly[$ym] = $p['monthly'][$ym];
            return [
                'project' => $p['project'], 'department' => $p['department'], 'position' => $p['position'],
                'name' => $p['name'], 'status_label' => $p['status_label'],
                'hire_date' => (string) $p['hire_date'], 'resign_date' => (string) $p['resign_date'],
                'monthly' => $monthly, 'total_gross' => $p['total_gross'],
            ];
        })->values()->all();

        // 底部合计行
        $totals = [
            'headcount' => count($people),
            'total_gross' => round(collect($people)->sum('total_gross'), 2),
            'monthly' => collect($months)->mapWithKeys(function ($ym) use ($people) {
                return [$ym => round(collect($people)->sum(fn ($p) => $p['monthly'][$ym]), 2)];
            })->all(),
            'prev_headcount' => $prevRows['total']['cnt'],
            'prev_gross' => $prevRows['total']['gross'],
        ];

        return ['months' => $months, 'prev_ym' => $prevYm, 'summary' => $summary, 'rows' => $list, 'totals' => $totals];
    }

    /** 某月发薪统计（含分项目），供汇总表"上月"两列使用 */
    private function monthStats(string $ym, string $project, string $name): array
    {
        $q = DB::table('payroll_results')->where('year_month', $ym);
        if ($project !== '') $q->where('project_name', $project);
        if ($name !== '') $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.name')) LIKE ?", ['%' . $name . '%']);
        $raw = $q->get();
        $byKeyMonth = [];
        foreach ($raw as $r) $byKeyMonth[$this->personKey($r)][] = $r;
        $byProject = [];
        $totalCnt = 0;
        $totalGross = 0.0;
        foreach ($byKeyMonth as $cands) {
            $row = $this->pickRow($cands);
            if (!$row) continue;
            $d = $this->jsonValue($row->row_data) ?: [];
            $g = round((float) ($d['gross'] ?? 0), 2);
            $proj = (string) ($d['project'] ?? $row->project_name ?? '未分配');
            if ($proj === '') $proj = '未分配';
            $byProject[$proj]['cnt'] = ($byProject[$proj]['cnt'] ?? 0) + 1;
            $byProject[$proj]['gross'] = round(($byProject[$proj]['gross'] ?? 0) + $g, 2);
            $totalCnt++;
            $totalGross += $g;
        }
        return ['by_project' => $byProject, 'total' => ['cnt' => $totalCnt, 'gross' => round($totalGross, 2)]];
    }

    // ==================== Excel 导出 ====================

    private const HEAD_FILL = 'DDEBF7';
    private const TOTAL_FILL = 'F2F2F2';

    private function styleHeader($sheet, int $row, int $lastCol): void
    {
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);
        $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEAD_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B8C4D0']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function fillSummarySheet($sheet, array $view, int $year): void
    {
        $sheet->setTitle('分项目全年汇总');
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', $year . '年 分项目全年工资汇总');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $heads = ['项目', '全年发薪人数', '全年应发总额(元)', '人均年应发(元)', '上月实际发薪人数(' . $view['prev_ym'] . ')', '上月应发总额(元)'];
        foreach ($heads as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '2', $h);
        $this->styleHeader($sheet, 2, 6);

        $r = 3;
        foreach ($view['summary'] as $s) {
            $sheet->setCellValue("A{$r}", $s['project']);
            $sheet->setCellValue("B{$r}", $s['headcount']);
            $sheet->setCellValue("C{$r}", $s['total_gross']);
            $sheet->setCellValue("D{$r}", $s['avg_gross']);
            $sheet->setCellValue("E{$r}", $s['prev_headcount']);
            $sheet->setCellValue("F{$r}", $s['prev_gross']);
            $r++;
        }
        // 合计行
        $t = $view['totals'];
        $sheet->setCellValue("A{$r}", '合计');
        $sheet->setCellValue("B{$r}", $t['headcount']);
        $sheet->setCellValue("C{$r}", $t['total_gross']);
        $sheet->setCellValue("D{$r}", '');
        $sheet->setCellValue("E{$r}", $t['prev_headcount']);
        $sheet->setCellValue("F{$r}", $t['prev_gross']);
        $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:F{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TOTAL_FILL);

        // 全表边框 + 数字格式
        $last = $r;
        $sheet->getStyle("A2:F{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B8C4D0');
        $sheet->getStyle("C3:F{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 18, 'B' => 14, 'C' => 18, 'D' => 16, 'E' => 20, 'F' => 18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A3');
    }

    private function fillDetailSheet($sheet, array $view, int $year, string $project): void
    {
        $sheet->setTitle('全年明细');
        $months = $view['months'];
        $lastCol = 7 + count($months) + 1; // 7 基础列 + 12 月 + 全年合计 = 20
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);

        $sheet->mergeCells("A1:{$lastLetter}1");
        $title = $year . '年 全年工资发放明细' . ($project !== '' ? ' · ' . $project : '');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $heads = ['项目', '部门', '岗位', '姓名', '员工状态', '入职日期', '离职日期'];
        foreach ($months as $i => $ym) $heads[] = (int) substr($ym, 5, 2) . '月应发';
        $heads[] = '全年应发';
        foreach ($heads as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '2', $h);
        $this->styleHeader($sheet, 2, $lastCol);

        $r = 3;
        foreach ($view['rows'] as $row) {
            $sheet->setCellValue("A{$r}", $row['project']);
            $sheet->setCellValue("B{$r}", $row['department']);
            $sheet->setCellValue("C{$r}", $row['position']);
            $sheet->setCellValue("D{$r}", $row['name']);
            $sheet->setCellValue("E{$r}", $row['status_label']);
            $sheet->setCellValue("F{$r}", (string) $row['hire_date']);
            $sheet->setCellValue("G{$r}", (string) $row['resign_date']);
            $col = 8;
            foreach ($months as $ym) {
                $g = $row['monthly'][$ym];
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $g > 0 ? $g : '');
                $col++;
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $row['total_gross']);
            $r++;
        }

        // 底部合计行
        $t = $view['totals'];
        $sheet->setCellValue("A{$r}", '合计');
        $sheet->setCellValue("D{$r}", $t['headcount'] . '人');
        $col = 8;
        foreach ($months as $ym) {
            $g = $t['monthly'][$ym];
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $g > 0 ? $g : '');
            $col++;
        }
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $t['total_gross']);
        $sheet->getStyle("A{$r}:{$lastLetter}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastLetter}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TOTAL_FILL);

        $sheet->getStyle("A2:{$lastLetter}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B8C4D0');
        // 金额列数字格式（1-12月 + 全年应发）
        $firstMoney = Coordinate::stringFromColumnIndex(8);
        $sheet->getStyle("{$firstMoney}3:{$lastLetter}{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        $widths = ['A' => 16, 'B' => 12, 'C' => 12, 'D' => 10, 'E' => 9, 'F' => 12, 'G' => 12];
        foreach ($widths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
        for ($c = 8; $c <= $lastCol; $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
        $sheet->freezePane('H3');
    }
}
