<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 考勤 Excel 导出的唯一实现。
 *
 * 考勤管理页「导出考勤」与数据导出模块「考勤导出 / 考勤全量数据包」共用本类，
 * 避免两处各写一套导致列序、统计口径、行序出现漂移。
 */
class AttendanceExportService
{
    private function jsonRows($value): array
    {
        if ($value === null || $value === '') return [];
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 取数并按上传表格行序组装人员行。
     *
     * @param string[] $projects 真实项目名（顺序即导出时项目拼接顺序）
     * @param string   $category ''=全部 | manager | case | staff
     * @param array    $opts     use_month_category: 按月 categoryMap 分类（与工资核算同源）
     *                           skip_unlocked: 只导出已锁定项目，其余进 skipped_unlocked
     * @return array{projects: array<string,array{people: array,locked: bool}>,
     *               skipped_unlocked: string[], skipped_no_data: string[], skipped_no_type_rows: string[]}
     */
    public function prepare(string $ym, array $projects, string $category = '', array $opts = []): array
    {
        $useMonthCategory = (bool) ($opts['use_month_category'] ?? false);
        $skipUnlocked = (bool) ($opts['skip_unlocked'] ?? false);
        $projects = array_values(array_filter(array_map('strval', $projects), fn ($p) => $p !== ''));

        // 离职过滤：当月离职保留（要算离职当月工资），永久/上月离职排除——与核算、考勤页一致
        $staffQuery = DB::table('payroll_staff')->whereIn('project_name', $projects)->where('deleted', false)
            ->where(function ($q) use ($ym) {
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            });
        if ($category !== '' && !$useMonthCategory) {
            $staffQuery->where('person_type', $category);
        }
        $staff = $staffQuery->get();
        if ($useMonthCategory && $category !== '') {
            $catMap = PayrollCalculator::categoryMap($ym);
            $staff = $staff->filter(function ($p) use ($catMap, $category) {
                if (($catMap[(int) $p->legacy_id] ?? 'staff') !== $category) return false;
                // 管理人员不含总部载体项目：总部人员走自己的项目入口，避免重复数据源
                return !($category === 'manager' && $p->project_name === PayrollCalculator::HQ_PROJECT);
            });
        }
        foreach ($staff as $person) {
            $person->_dept = PayrollCalculator::deptName($person->dept_path ?? null, $person->project_name);
        }
        $staffByName = [];
        foreach ($staff->all() as $person) {
            $staffByName[$person->project_name][$person->name][] = $person;
        }

        $out = [];
        $skippedUnlocked = [];
        $skippedNoData = [];
        $skippedNoTypeRows = [];
        foreach ($projects as $p) {
            $record = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
            $att = $record ? $this->jsonRows($record->rows) : [];
            if (!$att) { $skippedNoData[] = $p; continue; }
            if ($skipUnlocked && !(bool) $record->locked) { $skippedUnlocked[] = $p; continue; }
            // 行序严格跟随上传表格行序，不按部门/岗位/姓名重排
            $people = [];
            foreach (PayrollCalculator::attendanceOrder($record, $att) as $name) {
                foreach ($staffByName[$p][$name] ?? [] as $person) {
                    $person->_att = $att[$name];
                    $people[] = $person;
                }
            }
            if (!$people) { $skippedNoTypeRows[] = $p; continue; }
            $out[$p] = ['people' => $people, 'locked' => (bool) $record->locked];
        }

        return [
            'projects' => $out,
            'skipped_unlocked' => $skippedUnlocked,
            'skipped_no_data' => $skippedNoData,
            'skipped_no_type_rows' => $skippedNoTypeRows,
        ];
    }

    /**
     * 把一组人员写成一张考勤表（表头/符号图例/统计列/金额列与考勤管理页导出完全一致）。
     *
     * @param array  $people      prepare() 返回的人员行（含 _att / _dept）
     * @param string $label       标题里的范围名（项目名或「全部项目」/「管理人员」等）
     * @param bool   $withProject 是否输出「项目」列（全部项目合并表必须输出，单项目表不输出）
     */
    public function writeSheet(Worksheet $sheet, array $people, string $ym, string $label, bool $withProject, string $typeLabel): void
    {
        $daysInMonth = (int) date('t', strtotime($ym . '-01'));
        $baseCols = $withProject
            ? ['序号', '项目', '部门', '岗位', '姓名', '员工状态', '入职日期']
            : ['序号', '部门', '岗位', '姓名', '员工状态', '入职日期'];
        $dateCols = [];
        for ($d = 1; $d <= $daysInMonth; $d++) $dateCols[] = (string) $d;
        $statCols = ['应出勤(天)', '实际出勤(天)', '绩效系数', '事假(天)', '病假(天)', '产假(天)',
            '带薪假(天)', '缺卡(次)', '旷工(天)', '迟到(次)', '早退(次)'];
        // 奖励组 → 补贴组 → 扣罚组 → 五险一金组（与上传模板列序完全一致）
        $moneyCols = ['月度奖励', '已发福利/奖励', '补发工资',
            '餐补', '夜班/话费补贴', '职称/证书补贴',
            '月度扣罚', '其他扣款', '工装扣款',
            '养老保险', '医疗保险', '失业保险', '住房公积金', '大病'];
        $tailCols = ['备注'];
        $headers = array_merge($baseCols, $dateCols, $statCols, $moneyCols, $tailCols);
        $lastCol = count($headers);
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);
        $dateStart = count($baseCols) + 1;
        $dateEnd = count($baseCols) + count($dateCols);
        $moneyStart = $dateEnd + count($statCols) + 1;
        $statStart = $dateEnd + 1;
        $tailStart = $moneyStart + count($moneyCols);

        // 归档月口径：统计列优先读上传/归档时落库的存值；旧月份无存值需在表头注明按当前符号库计算
        $missingSnapshot = false;
        foreach ($people as $person) {
            $att = $person->_att;
            foreach (array_keys(PayrollCalculator::STAT_FIELDS) as $k) {
                if (!array_key_exists($k, $att) || $att[$k] === null) { $missingSnapshot = true; break 2; }
            }
        }

        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', "【{$label}】" . substr($ym, 0, 4) . '年' . (int) substr($ym, 5, 2) . "月考勤表（{$typeLabel}）"
            . ($missingSnapshot ? '（统计按当前符号库计算）' : ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(26);

        // 第2行：符号图例（动态读符号库）
        $legendParts = [];
        $symItems = PayrollCalculator::symbols();
        foreach ($symItems as $sym => $meta) {
            $name = trim((string) ($meta['name'] ?? $meta['label'] ?? ''));
            if ($sym !== '' && $name !== '') $legendParts[] = "\"{$sym}\"{$name}";
        }
        if (empty($legendParts)) {
            foreach (\App\Http\Controllers\Api\ConfigController::defaultSymbols() as $it) {
                $sym = (string) ($it['symbol'] ?? '');
                $name = (string) ($it['name'] ?? '');
                if ($sym !== '' && $name !== '') $legendParts[] = "\"{$sym}\"{$name}";
            }
        }
        $legendRow = 2;
        $sheet->mergeCells("A{$legendRow}:{$lastLetter}{$legendRow}");
        $sheet->setCellValue("A{$legendRow}", implode('  ', $legendParts));
        $sheet->getStyle("A{$legendRow}")->getFont()->setSize(10)->getColor()->setRGB('334155');
        $sheet->getStyle("A{$legendRow}")->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle("A{$legendRow}:{$lastLetter}{$legendRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
        $sheet->getRowDimension($legendRow)->setRowHeight(22);

        // 第3行：表头（奖罚区按分组配色，与上传模板一致）
        $sheet->fromArray($headers, null, 'A3');
        $sheet->getStyle("A3:{$lastLetter}3")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("A3:{$lastLetter}3")->getAlignment()->setHorizontal('center')->setWrapText(true);
        $sheet->getStyle("A3:{$lastLetter}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5496');
        $sheet->getStyle("A3:{$lastLetter}3")->getFont()->getColor()->setRGB('FFFFFF');
        $L = fn ($idx) => Coordinate::stringFromColumnIndex($idx);
        $fillH = function ($from, $to, $rgb) use ($sheet, $L) {
            $sheet->getStyle("{$L($from)}3:{$L($to)}3")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
            $sheet->getStyle("{$L($from)}3:{$L($to)}3")->getFont()->getColor()->setRGB('1F2329');
        };
        $fillH($moneyStart, $moneyStart + 2, 'E2EFDA');    // 奖励项
        $fillH($moneyStart + 3, $moneyStart + 5, 'DDEBF7');// 补贴项
        $fillH($moneyStart + 6, $moneyStart + 8, 'FCE4E4');// 扣罚项
        $fillH($moneyStart + 9, $moneyStart + 13, 'EDEDF0');// 五险一金
        $fillH($tailStart, $tailStart, 'EDEDED');           // 备注

        $symbols = PayrollCalculator::symbols();
        $row = 4;
        $deptColLetter = Coordinate::stringFromColumnIndex($withProject ? 3 : 2);
        foreach ($people as $i => $person) {
            $att = $person->_att;
            $stats = PayrollCalculator::storedStats($att, $symbols);
            $hireDate = $person->hire_date ? substr((string) $person->hire_date, 0, 10) : '';
            $baseValues = $withProject
                ? [$i + 1, $person->project_name, $person->_dept, $person->position ?: '', $person->name, $person->status ?: '正式', $hireDate]
                : [$i + 1, $person->_dept, $person->position ?: '', $person->name, $person->status ?: '正式', $hireDate];
            $sheet->fromArray($baseValues, null, "A{$row}");
            foreach (($att['days'] ?? []) as $dayIdx => $sym) {
                $col = Coordinate::stringFromColumnIndex($dateStart + $dayIdx);
                $sheet->setCellValue("{$col}{$row}", $sym);
            }
            $c = fn ($off) => Coordinate::stringFromColumnIndex($statStart + $off);
            // 应出勤/实际出勤：与核算、页面共用 storedOrFallback——字段缺失才回退符号统计，填 0 就是 0
            $sheet->setCellValue("{$c(0)}{$row}", PayrollCalculator::storedOrFallback($att, 'req_attend', $stats['required']));
            $sheet->setCellValue("{$c(1)}{$row}", PayrollCalculator::storedOrFallback($att, 'act_attend', $stats['attend']));
            $sheet->setCellValue("{$c(2)}{$row}", $att['coef'] ?? 1);
            $sheet->setCellValue("{$c(3)}{$row}", $stats['personal']);
            $sheet->setCellValue("{$c(4)}{$row}", $stats['sick']);
            $sheet->setCellValue("{$c(5)}{$row}", $stats['maternity']);
            $sheet->setCellValue("{$c(6)}{$row}", $stats['paid']);
            $sheet->setCellValue("{$c(7)}{$row}", $stats['miss']);
            $sheet->setCellValue("{$c(8)}{$row}", $stats['absent']);
            $sheet->setCellValue("{$c(9)}{$row}", $stats['late']);
            $sheet->setCellValue("{$c(10)}{$row}", $stats['early']);
            $m = fn ($off) => Coordinate::stringFromColumnIndex($moneyStart + $off);
            $sheet->setCellValue("{$m(0)}{$row}", (float) ($att['reward'] ?? 0));
            $sheet->setCellValue("{$m(1)}{$row}", (float) ($att['welfare'] ?? 0));
            $sheet->setCellValue("{$m(2)}{$row}", (float) ($att['backpay'] ?? 0));
            $sheet->setCellValue("{$m(3)}{$row}", (float) ($att['meal_sub'] ?? 0));
            $sheet->setCellValue("{$m(4)}{$row}", (float) ($att['night_sub'] ?? 0));
            $sheet->setCellValue("{$m(5)}{$row}", (float) ($att['title_sub'] ?? 0));
            $sheet->setCellValue("{$m(6)}{$row}", (float) ($att['punish'] ?? 0));
            $sheet->setCellValue("{$m(7)}{$row}", (float) ($att['other_deduct'] ?? 0));
            $sheet->setCellValue("{$m(8)}{$row}", (float) ($att['uniform_deduct'] ?? 0));
            $sheet->setCellValue("{$m(9)}{$row}", (float) ($att['pen'] ?? 0));
            $sheet->setCellValue("{$m(10)}{$row}", (float) ($att['med'] ?? 0));
            $sheet->setCellValue("{$m(11)}{$row}", (float) ($att['une'] ?? 0));
            $sheet->setCellValue("{$m(12)}{$row}", (float) ($att['house'] ?? 0));
            $sheet->setCellValue("{$m(13)}{$row}", (float) ($att['big'] ?? 0));
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($tailStart) . $row, (string) ($att['remark'] ?? ''));
            $row++;
        }
        $sheet->getStyle("A3:{$lastLetter}" . ($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        foreach ($baseCols as $bi => $h) {
            $w = match ($h) {
                '序号' => 5, '项目' => 14, '部门' => 12, '岗位' => 14, '姓名' => 10,
                '员工状态' => 8, '入职日期' => 12, default => 11,
            };
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($bi + 1))->setWidth($w);
        }
        $sheet->freezePane('E3');

        // 部门列合并单元格：相同部门连续行合并，内容水平+垂直居中
        $deptStart = 4;
        $deptEnd = $row - 1;
        if ($deptEnd >= $deptStart) {
            $mergeStart = $deptStart;
            $prevDept = $sheet->getCell($deptColLetter . $deptStart)->getValue();
            for ($r = $deptStart + 1; $r <= $deptEnd + 1; $r++) {
                $curDept = $r <= $deptEnd ? $sheet->getCell($deptColLetter . $r)->getValue() : null;
                if ($curDept !== $prevDept) {
                    if ($r - 1 > $mergeStart) {
                        $sheet->mergeCells($deptColLetter . $mergeStart . ':' . $deptColLetter . ($r - 1));
                    }
                    $sheet->getStyle($deptColLetter . $mergeStart . ':' . $deptColLetter . ($r - 1))
                        ->getAlignment()->setHorizontal('center')->setVertical('center');
                    $mergeStart = $r;
                    $prevDept = $curDept;
                }
            }
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($dateStart + $d - 1))->setWidth(3.4);
        }
        // 奖惩/补贴/扣罚/五险一金区列宽（14 列，与模板列序一致）；社保公积金数值列加宽避免 ### 
        $exportMoneyWidths = [9, 10, 9, 6, 10, 10, 9, 8, 8, 10, 10, 10, 11, 8];
        foreach ($exportMoneyWidths as $i => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($moneyStart + $i))->setWidth($w);
        }
    }

    /**
     * Excel Sheet 名：去除非法字符、限长 31，重名自动追加序号。
     */
    public function sheetTitle(string $name, array $existing): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '_', $name) ?? '';
        $title = trim($title) ?: 'Sheet';
        $title = mb_substr($title, 0, 31);
        $base = $title;
        $i = 2;
        while (in_array($title, $existing, true)) {
            $suffix = '(' . $i++ . ')';
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        return $title;
    }
}
