<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Color, Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use ZipArchive;

class ExportController extends ApiController
{
    private const COLUMNS = ['序号', '项目', '部门', '岗位', '姓名', '人员状态', '固定月薪', '基本工资', '应出勤', '实际出勤',
        '绩效系数', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴', '月度奖励',
        '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款', '应发工资合计', '养老保险',
        '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计', '专项附加扣除', '本月个税', '个税补差', '实发工资', '备注'];

    /** 表头分区配色（列序号从 1 开始），与考勤模板风格一致 */
    private const COL_BASE = 'DDEBF7';   // 基本信息：浅蓝
    private const COL_ATT  = 'F2F2F2';   // 出勤统计：浅灰
    private const COL_PAY  = 'E2EFDA';   // 应发项目：浅绿
    private const COL_SOC  = 'FFF2CC';   // 五险一金：浅黄
    private const COL_NET  = 'FCE4D6';   // 个税/实发：浅橙

    /** 绩效周期兑现错误码 → 台账中文状态（显式失败，禁止静默显示 0） */
    private const PERF_ERROR_LABELS = [
        'missing_coef'       => '缺周期系数，未发放（请录入系数后重新核算）',
        'missing_pay_grade'  => '缺薪酬档位',
        'invalid_pay_grade'  => '薪酬档位非法',
        'missing_pay_rule'   => '该档位未配置绩效规则',
    ];

    /** 每列归属的分区（与 COLUMNS 一一对应） */
    private function columnBand(int $idx): string
    {
        return match (true) {
            $idx <= 8                       => self::COL_BASE,  // 序号..基本工资
            $idx <= 11                      => self::COL_ATT,   // 应出勤..绩效系数
            $idx <= 25                      => self::COL_PAY,   // 应发基本..应发合计
            $idx <= 31                      => self::COL_SOC,   // 五险..五险一金合计
            default                         => self::COL_NET,   // 专项/个税/实发/备注
        };
    }

    private function denyProjectNotArchived($account, string $ym): ?JsonResponse
    {
        if (!$this->isProjectScope($account)) return null;
        $archived = DB::table('payroll_results')->where('year_month', $ym)
            ->where('project_name', (string) $account->project_name)->where('archived', true)->exists();
        if (!$archived) {
            return response()->json(['ok' => false, 'error' => '本月薪资总部尚未核定，核定完成后才能导出'], 403);
        }
        return null;
    }

    public function project(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if ($project === '') return response()->json(['ok' => false, 'error' => '项目不能为空'], 400);
        $rows = $this->rows($ym, $project);
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $project . ' ' . $ym . '工资表');
        return $this->xlsx($book, $project . '_' . $ym . '工资表.xlsx');
    }

    public function summary(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        // 汇总表主体 = 项目员工（不含管理人员与案场人员）；管理人员/案场人员单独成行/单独 Sheet
        $all = $query->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false)->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($all->isEmpty()) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);
        $allMgrs = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_manager_row', true)->where('is_hq_row', false)->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        // 权限收紧：项目账号导出完全不可见管理人员工资（含本项目的），仅总部可见
        if ($this->isProjectScope($account)) $allMgrs = collect();
        // 案场人员行：各项目可查看/导出本项目案场人员（is_case_row=1）；总部可见全部
        $caseQuery = DB::table('payroll_results')->where('year_month', $ym)->where('is_case_row', true)->where('is_hq_row', false);
        if ($this->isProjectScope($account)) $caseQuery->where('project_name', $account->project_name);
        $allCases = $caseQuery->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        // 总部人员行（is_hq_row=1）：仅总部可见
        $hqQuery = DB::table('payroll_results')->where('year_month', $ym)->where('is_hq_row', true);
        if ($this->isProjectScope($account)) $hqQuery->whereRaw('1=0');
        $allHq = $hqQuery->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();

        $book = new Spreadsheet();
        // ===== Sheet1：汇总报表 =====
        $sheet = $book->getActiveSheet();
                $sheet->setTitle('汇总');
        // ===== 汇总报表（新格式：项目行=基层+管理两维；物业总部固定置底；仅总部含管理/总部行） =====
        // 列：项目|基层员工人数|基层应发工资合计|管理人员人数|管理人员应发工资合计|人数合计|应发合计
        //     |月度预算|月度预算执行率|年度预算执行率|上月应发合计|较上月增减|较上月幅度
        $year = (int) substr($ym, 0, 4);
        $monthNo = (int) substr($ym, 5, 2);
        $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
        $isProjScope = $this->isProjectScope($account);

        $aggByProj = function ($rows) {
            return $rows->groupBy(fn ($x) => $x['project'] ?? '未分配')
                ->map(fn ($rs) => ['cnt' => $rs->count(), 'gross' => round($rs->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2)])
                ->all();
        };
        $curBase = $aggByProj($all);
        $curMgr = $aggByProj($allMgrs);
        $curCase = $aggByProj($allCases);
        $curHq = $aggByProj($allHq);

        $prevBase = $aggByProj(DB::table('payroll_results')->where('year_month', $prevYm)
            ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false)->get()
            ->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $prevMgr = $aggByProj(DB::table('payroll_results')->where('year_month', $prevYm)
            ->where('is_manager_row', true)->where('is_hq_row', false)->get()
            ->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $prevCase = $aggByProj(DB::table('payroll_results')->where('year_month', $prevYm)
            ->where('is_case_row', true)->where('is_hq_row', false)->get()
            ->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $prevHq = $aggByProj(DB::table('payroll_results')->where('year_month', $prevYm)
            ->where('is_hq_row', true)->get()
            ->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());

        $ytdQ = DB::table('payroll_results')->where('year_month', 'like', $year . '-%')->get()
            ->filter(fn ($r) => (int) substr((string) $r->year_month, 5, 2) <= $monthNo);
        $ytdBase = $aggByProj($ytdQ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false)->values()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $ytdMgr = $aggByProj($ytdQ->where('is_manager_row', true)->where('is_hq_row', false)->values()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $ytdCase = $aggByProj($ytdQ->where('is_case_row', true)->where('is_hq_row', false)->values()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());
        $ytdHq = $aggByProj($ytdQ->where('is_hq_row', true)->values()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter());

        $budgetMap = DB::table('payroll_budgets')->where('year', $year)->get()
            ->mapWithKeys(fn ($row) => [$row->project_name => $this->jsonValue($row->data) ?: []])->all();
        $monthKey = (string) $monthNo;

        // 第1行 大标题
        $sheet->setCellValue('A1', $ym . ' 工资汇总报表');
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $sheet->getRowDimension(1)->setRowHeight(30);

        // 第2行 表头
        $detailHead = ['项目', '基层员工人数', '基层应发工资合计', '管理人员人数', '管理人员应发工资合计',
            '人数合计', '应发合计', '月度预算', '月度预算执行率', '年度预算执行率', '上月应发合计', '较上月增减', '较上月幅度'];
        $band = [self::COL_BASE, self::COL_BASE, self::COL_PAY, self::COL_BASE, self::COL_PAY,
            self::COL_PAY, self::COL_PAY, self::COL_SOC, self::COL_SOC, self::COL_SOC, self::COL_ATT, self::COL_ATT, self::COL_ATT];
        foreach ($detailHead as $ci => $v) {
            $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
            $sheet->setCellValue($col, $v);
            $sheet->getStyle($col)->getFont()->setBold(true);
            $sheet->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($band[$ci]);
            $sheet->getStyle($col)->getAlignment()->setHorizontal('center');
        }
        $sheet->getRowDimension(2)->setRowHeight(22);

        // 明细行：项目（基层+管理），物业总部不在此列
        $projs = collect(array_unique(array_merge(array_keys($curBase), array_keys($curMgr))))
            ->filter(fn ($p) => $p !== '物业总部')->values()->all();
        $r = 3;
        $sumB = ['cnt' => 0, 'gross' => 0.0]; $sumM = ['cnt' => 0, 'gross' => 0.0]; $sumCase = ['cnt' => 0, 'gross' => 0.0];
        $sumBudget = 0.0; $sumAnnual = 0.0; $sumPrev = 0.0; $sumYtd = 0.0;
        $cCnt = 0; $cGross = 0.0; $hCnt = 0; $hGross = 0.0;
        foreach ($projs as $p) {
            $b = $curBase[$p] ?? ['cnt' => 0, 'gross' => 0];
            $m = $curMgr[$p] ?? ['cnt' => 0, 'gross' => 0];
            $cnt = $b['cnt'] + $m['cnt'];
            $gross = round($b['gross'] + $m['gross'], 2);
            $budget = (float) ($budgetMap[$p]['months'][$monthKey] ?? 0);
            $annualBudget = (float) ($budgetMap[$p]['annual'] ?? 0);
            $ytd = round(($ytdBase[$p]['gross'] ?? 0) + ($isProjScope ? 0 : ($ytdMgr[$p]['gross'] ?? 0)), 2);
            $prev = round(($prevBase[$p]['gross'] ?? 0) + ($isProjScope ? 0 : ($prevMgr[$p]['gross'] ?? 0)), 2);
            $diff = $prev > 0 ? round($gross - $prev, 2) : null;
            $diffRate = ($prev > 0 && $diff !== null) ? round($diff / $prev, 4) : null;
            $vals = [$p, $b['cnt'], $b['gross'], $m['cnt'], $m['gross'], $cnt, $gross,
                $budget > 0 ? $budget : null,
                $budget > 0 ? round($gross / $budget, 4) : null,
                $annualBudget > 0 ? round($ytd / $annualBudget, 4) : null,
                $prev > 0 ? $prev : null, $diff, $diffRate];
            foreach ($vals as $ci => $v) {
                if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sumB['cnt'] += $b['cnt']; $sumB['gross'] += $b['gross'];
            $sumM['cnt'] += $m['cnt']; $sumM['gross'] += $m['gross'];
            $sumBudget += $budget; $sumAnnual += $annualBudget; $sumPrev += $prev; $sumYtd += ($ytdBase[$p]['gross'] ?? 0);
            $r++;
        }
        $last = $r - 1;

        // 合计行（基层员工合计，不含物业总部）
        $bGross = round($sumB['gross'], 2);
        $prevBaseScope = $isProjScope ? array_intersect_key($prevBase, $curBase) : array_diff_key($prevBase, ['物业总部' => true]);
        $bPrev = round(array_sum(array_column($prevBaseScope, 'gross')), 2);
        $bYtd = round(array_sum(array_column($isProjScope ? array_intersect_key($ytdBase, $curBase) : array_diff_key($ytdBase, ['物业总部' => true]), 'gross')), 2);
        $bDiff = $bPrev > 0 ? round($bGross - $bPrev, 2) : null;
        $totalVals = ['合计', null, null, null, null, $sumB['cnt'], $bGross,
            $sumBudget > 0 ? round($sumBudget, 2) : null,
            $sumBudget > 0 ? round($bGross / $sumBudget, 4) : null,
            $sumAnnual > 0 ? round($bYtd / $sumAnnual, 4) : null,
            $bPrev > 0 ? $bPrev : null, $bDiff,
            ($bPrev > 0 && $bDiff !== null) ? round($bDiff / $bPrev, 4) : null];
        foreach ($totalVals as $ci => $v) {
            if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
        $r++;

        $mYtd = round(array_sum(array_column(array_diff_key($ytdMgr, ['物业总部' => true]), 'gross')), 2);
        $cYtd = round(array_sum(array_column($ytdCase, 'gross')), 2);

        // 管理人员合计行（仅总部；管理数据项目账号完全不可见；无数据也显示固定结构，便于核对遗漏）
        if (!$isProjScope) {
            $mGross = round($sumM['gross'], 2);
            $prevMgrNoHq = array_diff_key($prevMgr, ['物业总部' => true]);
            $mPrev = round(array_sum(array_column($prevMgrNoHq, 'gross')), 2);
            $mDiff = $mPrev > 0 ? round($mGross - $mPrev, 2) : null;
            $mgrVals = ['管理人员合计', null, null, null, null, $sumM['cnt'], $mGross,
                null, null, null, $mPrev > 0 ? $mPrev : null, $mDiff,
                ($mPrev > 0 && $mDiff !== null) ? round($mDiff / $mPrev, 4) : null];
            foreach ($mgrVals as $ci => $v) {
                if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            $r++;
        }

        // 案场人员合计行（纯案场项目只在此体现；无数据也显示固定结构）
        if (true) {
            $cCnt = array_sum(array_column($curCase, 'cnt'));
            $cGross = round(array_sum(array_column($curCase, 'gross')), 2);
            $cPrev = round(array_sum(array_column($isProjScope ? array_intersect_key($prevCase, $curCase) : $prevCase, 'gross')), 2);
            $cDiff = $cPrev > 0 ? round($cGross - $cPrev, 2) : null;
            $caseVals = ['案场人员合计', null, null, null, null, $cCnt, $cGross,
                null, null, null, $cPrev > 0 ? $cPrev : null, $cDiff,
                ($cPrev > 0 && $cDiff !== null) ? round($cDiff / $cPrev, 4) : null];
            foreach ($caseVals as $ci => $v) {
                if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $r++;
        }

        // 物业总部合计行（固定置底、总计之前；仅总部；后续架构如何调整物业总部都在此）
        if (!$isProjScope) {
            // 总部人员一套标签（不分基层/管理），在汇总表单列一行：仅人数合计与应发合计
            $hqAgg = $curHq['物业总部'] ?? ['cnt' => 0, 'gross' => 0.0];
            $hCnt = (int) $hqAgg['cnt'];
            $hGross = round((float) $hqAgg['gross'], 2);
            $hBudget = (float) ($budgetMap['物业总部']['months'][$monthKey] ?? 0);
            $hAnnual = (float) ($budgetMap['物业总部']['annual'] ?? 0);
            $hYtd = round((float) ($ytdHq['物业总部']['gross'] ?? 0), 2);
            $hPrev = round((float) ($prevHq['物业总部']['gross'] ?? 0), 2);
            $hDiff = $hPrev > 0 ? round($hGross - $hPrev, 2) : null;
            $hVals = ['物业总部合计', null, null, null, null, $hCnt, $hGross,
                $hBudget > 0 ? $hBudget : null,
                $hBudget > 0 ? round($hGross / $hBudget, 4) : null,
                $hAnnual > 0 ? round($hYtd / $hAnnual, 4) : null,
                $hPrev > 0 ? $hPrev : null, $hDiff,
                ($hPrev > 0 && $hDiff !== null) ? round($hDiff / $hPrev, 4) : null];
            foreach ($hVals as $ci => $v) {
                if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
            $sumBudget += $hBudget; $sumAnnual += $hAnnual; $sumPrev += $hPrev; $sumYtd += $hYtd;
            $r++;
        }

        // 项目账号不渲染管理/总部合计行时，其变量需兜底
        $mPrev = $mPrev ?? 0.0; $hPrev = $hPrev ?? 0.0;
        $cPrev = $cPrev ?? 0.0; $bPrev = $bPrev ?? 0.0;

        // 总计行（基层合计 + 管理人员合计 + 案场人员合计 + 物业总部合计）
        $gCnt = $sumB['cnt'] + $sumM['cnt'] + $cCnt + $hCnt;
        $gGross = round($sumB['gross'] + $sumM['gross'] + $cGross + $hGross, 2);
        $gPrev = round($bPrev + $mPrev + $cPrev + $hPrev, 2);
        $gDiff = $gPrev > 0 ? round($gGross - $gPrev, 2) : null;
        $gVals = ['总计', $sumB['cnt'], round($sumB['gross'], 2), $sumM['cnt'], round($sumM['gross'], 2), $gCnt, $gGross,
            $sumBudget > 0 ? round($sumBudget, 2) : null,
            $sumBudget > 0 ? round($gGross / $sumBudget, 4) : null,
            $sumAnnual > 0 ? round(($sumYtd + $mYtd + $cYtd) / $sumAnnual, 4) : null,
            $gPrev > 0 ? $gPrev : null, $gDiff,
            ($gPrev > 0 && $gDiff !== null) ? round($gDiff / $gPrev, 4) : null];
        foreach ($gVals as $ci => $v) {
            if ($v !== null) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $last = $r;

        // 样式：列宽/边框/金额与百分比格式
        $sheet->getColumnDimension('A')->setWidth(22);   // 项目
        $sheet->getColumnDimension('B')->setWidth(12);   // 基层员工人数
        $sheet->getColumnDimension('C')->setWidth(14);   // 基层应发
        $sheet->getColumnDimension('D')->setWidth(12);   // 管理人员人数
        $sheet->getColumnDimension('E')->setWidth(14);   // 管理应发
        $sheet->getColumnDimension('F')->setWidth(10);   // 人数合计
        $sheet->getColumnDimension('G')->setWidth(14);   // 应发合计
        $sheet->getColumnDimension('H')->setWidth(12);   // 月度预算
        $sheet->getColumnDimension('I')->setWidth(13);   // 月度预算执行率
        $sheet->getColumnDimension('J')->setWidth(13);   // 年度预算执行率
        $sheet->getColumnDimension('K')->setWidth(13);   // 上月应发合计
        $sheet->getColumnDimension('L')->setWidth(13);   // 较上月增减
        $sheet->getColumnDimension('M')->setWidth(13);   // 较上月幅度
        $moneyCols = ['C', 'E', 'G', 'H', 'K', 'L'];
        $pctCols = ['I', 'J', 'M'];
        foreach (range(2, $last) as $rr) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'] as $cc) {
                $st = $sheet->getStyle("{$cc}{$rr}");
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                if (in_array($cc, $moneyCols, true)) $st->getNumberFormat()->setFormatCode('#,##0.00');
                if (in_array($cc, $pctCols, true)) $st->getNumberFormat()->setFormatCode('0.00%');
            }
            $sheet->getRowDimension($rr)->setRowHeight(20);
        }
        $sheet->freezePane('A3');
// ===== 管理人员汇总 Sheet（第2个：汇总后面，按项目汇总管理人员工资；仅总部导出时生成） =====
        $mgrSummaryRows = $allMgrs;
        if ($mgrSummaryRows->isNotEmpty()) {
            $ms = $book->createSheet();
            $ms->setTitle('管理人员汇总');
            // 第1行 大标题
            $ms->setCellValue('A1', $ym . ' 管理人员工资汇总');
            $ms->mergeCells('A1:H1');
            $ms->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
            $ms->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
            $ms->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            $ms->getRowDimension(1)->setRowHeight(30);
            // 第2行 表头
            $mHead = ['项目', '人数', '应发工资合计', '五险一金合计', '本月个税', '已发/福利奖金', '实发工资合计', '调个税差额'];
            foreach ($mHead as $ci => $v) {
                $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
                $ms->setCellValue($col, $v);
                $ms->getStyle($col)->getFont()->setBold(true);
                $ms->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
                $ms->getStyle($col)->getAlignment()->setHorizontal('center');
            }
            $ms->getRowDimension(2)->setRowHeight(22);
            // 明细行（按项目分组）
            $mr = 3;
            $msGross = $msSoc = $msTax = $msBonus = $msNet = $msDiff = 0.0; $msCnt = 0;
            foreach ($mgrSummaryRows->groupBy(fn ($x) => $x['project'] ?? '') as $project => $rows) {
                $cnt = $rows->count();
                $gross = round($rows->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
                $soc = round($rows->sum(fn ($x) => (float) ($x['soc_total'] ?? 0)), 2);
                $tax = round($rows->sum(fn ($x) => (float) ($x['actual_tax'] ?? 0)), 2);
                $bonus = round($rows->sum(fn ($x) => (float) ($x['reward'] ?? 0) + (float) ($x['welfare'] ?? 0)), 2);
                $net = round($rows->sum(fn ($x) => (float) ($x['net'] ?? 0)), 2);
                $diff = round($rows->sum(fn ($x) => (float) ($x['tax_diff'] ?? 0)), 2);
                $vals = [$project, $cnt, $gross, $soc, $tax, $bonus, $net, $diff];
                foreach ($vals as $ci => $v) {
                    $ms->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $mr, $v);
                }
                $msGross += $gross; $msSoc += $soc; $msTax += $tax; $msBonus += $bonus; $msNet += $net; $msDiff += $diff; $msCnt += $cnt;
                $mr++;
            }
            // 合计行
            $mTotal = ['合计', $msCnt, round($msGross, 2), round($msSoc, 2), round($msTax, 2), round($msBonus, 2), round($msNet, 2), round($msDiff, 2)];
            foreach ($mTotal as $ci => $v) {
                $ms->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $mr, $v);
            }
            $ms->getStyle("A{$mr}:H{$mr}")->getFont()->setBold(true);
            $ms->getStyle("A{$mr}:H{$mr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF3E0');
            $mLast = $mr;
            // 样式：列宽/边框/金额格式
            $ms->getColumnDimension('A')->setWidth(20);
            $ms->getColumnDimension('B')->setWidth(8);
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $c) {
                $ms->getColumnDimension($c)->setWidth(15);
            }
            foreach (range(2, $mLast) as $rr) {
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $cc) {
                    $st = $ms->getStyle("{$cc}{$rr}");
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                    if (in_array($cc, ['C', 'D', 'E', 'F', 'G', 'H'], true)) $st->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $ms->getRowDimension($rr)->setRowHeight(20);
            }
            $ms->freezePane('A3');
        }

        // ===== 案场人员汇总 Sheet（管理人员汇总后，按项目汇总案场人员工资） =====
        if ($allCases->isNotEmpty()) {
            $cs = $book->createSheet();
            $cs->setTitle('案场人员汇总');
            $cs->setCellValue('A1', $ym . ' 案场人员工资汇总');
            $cs->mergeCells('A1:H1');
            $cs->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
            $cs->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
            $cs->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            $cs->getRowDimension(1)->setRowHeight(30);
            $cHead = ['项目', '人数', '应发工资合计', '五险一金合计', '本月个税', '已发/福利奖金', '实发工资合计', '调个税差额'];
            foreach ($cHead as $ci => $v) {
                $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
                $cs->setCellValue($col, $v);
                $cs->getStyle($col)->getFont()->setBold(true);
                $cs->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
                $cs->getStyle($col)->getAlignment()->setHorizontal('center');
            }
            $cs->getRowDimension(2)->setRowHeight(22);
            $cr = 3;
            $csGross = $csSoc = $csTax = $csBonus = $csNet = $csDiff = 0.0; $csCnt = 0;
            foreach ($allCases->groupBy(fn ($x) => $x['project'] ?? '') as $project => $rows) {
                $cnt = $rows->count();
                $gross = round($rows->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
                $soc = round($rows->sum(fn ($x) => (float) ($x['soc_total'] ?? 0)), 2);
                $tax = round($rows->sum(fn ($x) => (float) ($x['actual_tax'] ?? 0)), 2);
                $bonus = round($rows->sum(fn ($x) => (float) ($x['reward'] ?? 0) + (float) ($x['welfare'] ?? 0)), 2);
                $net = round($rows->sum(fn ($x) => (float) ($x['net'] ?? 0)), 2);
                $diff = round($rows->sum(fn ($x) => (float) ($x['tax_diff'] ?? 0)), 2);
                $vals = [$project, $cnt, $gross, $soc, $tax, $bonus, $net, $diff];
                foreach ($vals as $ci => $v) {
                    $cs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $cr, $v);
                }
                $csGross += $gross; $csSoc += $soc; $csTax += $tax; $csBonus += $bonus; $csNet += $net; $csDiff += $diff; $csCnt += $cnt;
                $cr++;
            }
            $cTotal = ['合计', $csCnt, round($csGross, 2), round($csSoc, 2), round($csTax, 2), round($csBonus, 2), round($csNet, 2), round($csDiff, 2)];
            foreach ($cTotal as $ci => $v) {
                $cs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $cr, $v);
            }
            $cs->getStyle("A{$cr}:H{$cr}")->getFont()->setBold(true);
            $cs->getStyle("A{$cr}:H{$cr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EBF5E9');
            $cLast = $cr;
            $cs->getColumnDimension('A')->setWidth(20);
            $cs->getColumnDimension('B')->setWidth(8);
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $c) {
                $cs->getColumnDimension($c)->setWidth(15);
            }
            foreach (range(2, $cLast) as $rr) {
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $cc) {
                    $st = $cs->getStyle("{$cc}{$rr}");
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                    if (in_array($cc, ['C', 'D', 'E', 'F', 'G', 'H'], true)) $st->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $cs->getRowDimension($rr)->setRowHeight(20);
            }
            $cs->freezePane('A3');
        }

        // ===== 总部人员汇总 Sheet（管理人员/案场人员汇总后，按项目汇总总部人员工资） =====
        if ($allHq->isNotEmpty()) {
            $hs = $book->createSheet();
            $hs->setTitle('总部人员汇总');
            $hs->setCellValue('A1', $ym . ' 总部人员工资汇总');
            $hs->mergeCells('A1:H1');
            $hs->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
            $hs->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
            $hs->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            $hs->getRowDimension(1)->setRowHeight(30);
            $hHead = ['项目', '人数', '应发工资合计', '五险一金合计', '本月个税', '已发/福利奖金', '实发工资合计', '调个税差额'];
            foreach ($hHead as $ci => $v) {
                $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
                $hs->setCellValue($col, $v);
                $hs->getStyle($col)->getFont()->setBold(true);
                $hs->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
                $hs->getStyle($col)->getAlignment()->setHorizontal('center');
            }
            $hs->getRowDimension(2)->setRowHeight(22);
            $hr = 3;
            $hGross2 = $hSoc = $hTax = $hBonus = $hNet = $hDiff = 0.0; $hCnt2 = 0;
            foreach ($allHq->groupBy(fn ($x) => $x['project'] ?? '') as $project => $rows) {
                $cnt = $rows->count();
                $gross = round($rows->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
                $soc = round($rows->sum(fn ($x) => (float) ($x['soc_total'] ?? 0)), 2);
                $tax = round($rows->sum(fn ($x) => (float) ($x['actual_tax'] ?? 0)), 2);
                $bonus = round($rows->sum(fn ($x) => (float) ($x['reward'] ?? 0) + (float) ($x['welfare'] ?? 0)), 2);
                $net = round($rows->sum(fn ($x) => (float) ($x['net'] ?? 0)), 2);
                $diff = round($rows->sum(fn ($x) => (float) ($x['tax_diff'] ?? 0)), 2);
                $vals = [$project, $cnt, $gross, $soc, $tax, $bonus, $net, $diff];
                foreach ($vals as $ci => $v) {
                    $hs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $hr, $v);
                }
                $hGross2 += $gross; $hSoc += $soc; $hTax += $tax; $hBonus += $bonus; $hNet += $net; $hDiff += $diff; $hCnt2 += $cnt;
                $hr++;
            }
            $hTotal = ['合计', $hCnt2, round($hGross2, 2), round($hSoc, 2), round($hTax, 2), round($hBonus, 2), round($hNet, 2), round($hDiff, 2)];
            foreach ($hTotal as $ci => $v) {
                $hs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $hr, $v);
            }
            $hs->getStyle("A{$hr}:H{$hr}")->getFont()->setBold(true);
            $hs->getStyle("A{$hr}:H{$hr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7E6');
            $hLast = $hr;
            $hs->getColumnDimension('A')->setWidth(20);
            $hs->getColumnDimension('B')->setWidth(8);
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $c) {
                $hs->getColumnDimension($c)->setWidth(15);
            }
            foreach (range(2, $hLast) as $rr) {
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $cc) {
                    $st = $hs->getStyle("{$cc}{$rr}");
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                    if (in_array($cc, ['C', 'D', 'E', 'F', 'G', 'H'], true)) $st->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $hs->getRowDimension($rr)->setRowHeight(20);
            }
            $hs->freezePane('A3');
        }

        // ===== 各项目明细 Sheet（不含管理人员） =====
        foreach ($all->groupBy(fn ($x) => $x['project'] ?? '') as $project => $rows) {
            $detail = $book->createSheet();
            $detail->setTitle(mb_substr((string) $project, 0, 28));
            $this->fillSheet($detail, $rows->values(), $project . ' ' . $ym . '工资表');
        }
        // ===== 管理人员 Sheet（所有项目管理人员明细） =====
        if ($allMgrs->isNotEmpty()) {
            $mgrSheet = $book->createSheet();
            $mgrSheet->setTitle('管理人员');
            $this->fillSheet($mgrSheet, collect(\App\Services\PayrollCalculator::orderRows($allMgrs->values()->all()))->values(), $ym . '管理人员工资表', true);
        }
        // ===== 案场人员 Sheet（所有项目案场人员明细） =====
        if ($allCases->isNotEmpty()) {
            $caseSheet = $book->createSheet();
            $caseSheet->setTitle('案场人员');
            $this->fillSheet($caseSheet, collect(\App\Services\PayrollCalculator::orderRows($allCases->values()->all()))->values(), $ym . '案场人员工资表');
        }
        // ===== 总部人员 Sheet（物业总部所有人员明细，仅总部导出时生成） =====
        if ($allHq->isNotEmpty()) {
            $hqSheet = $book->createSheet();
            $hqSheet->setTitle('总部人员');
            $this->fillSheet($hqSheet, collect(\App\Services\PayrollCalculator::orderRows($allHq->values()->all()))->values(), $ym . '总部人员工资表', true);
        }
        $book->setActiveSheetIndex(0);
        return $this->xlsx($book, '工资全量数据包_' . $ym . '.xlsx');
    }

    /**
     * 汇总展示页导出（GET /api/summary/export?ym=2026-09&project=项目名）
     * 与薪资模块"汇总展示"页同一口径：项目|发放人数|应发|实发|预算|执行率|年度累计|核算状态。
     * project 为空=全部；仅总部可见全部，项目账号限本项目。
     */
    public function summaryExport(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份无效'], 400);
        }
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        }
        $year = substr($ym, 0, 4); $month = (int) substr($ym, 5, 2);
        $budgets = DB::table('payroll_budgets')->where('year', (int) $year)->get()->keyBy('project_name');
        $allYtd = DB::table('payroll_results')
            ->whereBetween('year_month', [$year . '-01', $ym])
            ->get()->groupBy('project_name');
        $items = [];
        foreach ($query->get()->groupBy('project_name') as $project => $records) {
            $rows = $records->map(fn ($row) => $this->jsonValue($row->row_data));
            $budget = isset($budgets[$project]) ? ($this->jsonValue($budgets[$project]->data) ?: []) : [];
            $monthBudget = (float) ($budget['months'][(string) $month] ?? 0);
            $annualBudget = (float) ($budget['annual'] ?? 0);
            $ytd = $allYtd[$project] ?? collect();
            $ytdGross = round($ytd->sum(fn ($record) => (float) (($this->jsonValue($record->row_data)['gross'] ?? 0))), 2);
            $items[] = ['project' => $project, 'headcount' => $rows->count(),
                'gross' => round($rows->sum(fn ($row) => (float) ($row['gross'] ?? 0)), 2),
                'net' => round($rows->sum(fn ($row) => (float) ($row['net'] ?? 0)), 2),
                'month_budget' => $monthBudget, 'month_rate' => $monthBudget > 0 ? round($rows->sum(fn ($row) => (float) ($row['gross'] ?? 0)) / $monthBudget, 4) : 0,
                'annual_budget' => $annualBudget, 'ytd_gross' => $ytdGross,
                'annual_rate' => $annualBudget > 0 ? round($ytdGross / $annualBudget, 4) : 0, 'calculated' => true];
        }
        // 项目筛选（支持多选，逗号分隔）
        $projectFilter = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        $selected = array_values(array_filter(array_map('trim', explode(',', $projectFilter)), fn ($v) => $v !== ''));
        if ($selected) {
            $items = array_values(array_filter($items, fn ($it) => in_array($it['project'], $selected, true)));
        }
        if (!$items) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);

        $tHead = ['headcount' => 0, 'gross' => 0.0, 'net' => 0.0, 'month_budget' => 0.0, 'annual_budget' => 0.0, 'ytd_gross' => 0.0];
        foreach ($items as $it) {
            $tHead['headcount'] += $it['headcount'];
            $tHead['gross'] += $it['gross'];
            $tHead['net'] += $it['net'];
            $tHead['month_budget'] += $it['month_budget'];
            $tHead['annual_budget'] += $it['annual_budget'];
            $tHead['ytd_gross'] += $it['ytd_gross'];
        }
        $tHead['gross'] = round($tHead['gross'], 2);
        $tHead['net'] = round($tHead['net'], 2);
        $tHead['month_budget'] = round($tHead['month_budget'], 2);
        $tHead['annual_budget'] = round($tHead['annual_budget'], 2);
        $tHead['ytd_gross'] = round($tHead['ytd_gross'], 2);
        $tHead['month_rate'] = $tHead['month_budget'] > 0 ? round($tHead['gross'] / $tHead['month_budget'], 4) : 0;
        $tHead['annual_rate'] = $tHead['annual_budget'] > 0 ? round($tHead['ytd_gross'] / $tHead['annual_budget'], 4) : 0;

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('汇总展示');
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', $ym . ' 工资汇总展示' . ($selected ? '（筛选' . count($selected) . '项）' : ''));
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $head = ['项目', '发放人数', '应发总金额', '实发总金额', '当月预算', '当月执行率', '年度预算', '年度累计应发', '年度执行率', '核算状态'];
        foreach ($head as $ci => $v) {
            $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
            $sheet->setCellValue($col, $v);
            $sheet->getStyle($col)->getFont()->setBold(true);
            $sheet->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $sheet->getStyle($col)->getAlignment()->setHorizontal('center');
        }
        $sheet->getRowDimension(2)->setRowHeight(22);
        $r = 3;
        foreach ($items as $it) {
            $vals = [$it['project'], $it['headcount'], $it['gross'], $it['net'], $it['month_budget'],
                $it['month_rate'], $it['annual_budget'], $it['ytd_gross'], $it['annual_rate'],
                $it['calculated'] ? '已核算' : '未核算'];
            foreach ($vals as $ci => $v) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $r++;
        }
        $tVals = ['总计', $tHead['headcount'], $tHead['gross'], $tHead['net'], $tHead['month_budget'],
            $tHead['month_rate'], $tHead['annual_budget'], $tHead['ytd_gross'], $tHead['annual_rate'], ''];
        foreach ($tVals as $ci => $v) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $last = $r;
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(10);
        foreach (['C', 'D', 'E', 'G', 'H'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(14);
        }
        foreach (['F', 'I'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(12);
        }
        $sheet->getColumnDimension('J')->setWidth(10);
        foreach (range(2, $last) as $rr) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $cc) {
                $st = $sheet->getStyle("{$cc}{$rr}");
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                if (in_array($cc, ['C', 'D', 'E', 'G', 'H'], true)) $st->getNumberFormat()->setFormatCode('#,##0.00');
                if (in_array($cc, ['F', 'I'], true)) $st->getNumberFormat()->setFormatCode('0.00%');
            }
            $sheet->getRowDimension($rr)->setRowHeight(20);
        }
        $sheet->freezePane('A3');
        return $this->xlsx($book, '薪资汇总展示_' . $ym . ($projectFilter !== '' ? '_' . $projectFilter : '') . '.xlsx');
    }
    public function projectsAll(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        // 全部项目工资表 zip 同样不含管理人员与案场人员（管理人员/案场人员单独导出）
        $groups = $query->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false)->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->groupBy(fn ($r) => $r['project'] ?? '');
        if ($groups->isEmpty()) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);
        $zipPath = tempnam(sys_get_temp_dir(), 'payroll_export_') . '.zip'; $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($groups as $project => $rows) {
            $book = new Spreadsheet(); $this->fillSheet($book->getActiveSheet(), $rows, $project . ' ' . $ym . '工资表');
            $tmp = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($tmp); rewind($tmp);
            $zip->addFromString($project . '_' . $ym . '工资表.xlsx', stream_get_contents($tmp)); fclose($tmp);
        }
        $zip->close();
        return response()->download($zipPath, '全部项目工资表_' . $ym . '.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * 绩效工资专项表（GET /api/export/performance?ym=&project=）
     * Sheet1「当月绩效发放台账」：四类人员每人一行——发放方式（月度/季度/半年度）、
     *   系数、计提基数、应发绩效工资；缺系数/缺档位等异常显式标注，不静默按 0 展示。
     * Sheet2「周期兑现逐月基数」：季度/半年度兑现展开逐月绩效出勤/月基数/月计提额+周期系数+比例+小计。
     * 权限：项目账号限本项目且须总部核定（归档）后；管理/总部行仅总部可见。
     */
    public function performance(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份无效'], 400);
        }
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;

        $projScope = $this->isProjectScope($account);
        $project = $projScope ? (string) $account->project_name : $request->string('project')->toString();

        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($project !== '') $query->where('project_name', $project);
        if ($projScope) $query->where('is_manager_row', false)->where('is_hq_row', false);

        $records = $query->orderBy('id')->get();
        if ($records->isEmpty()) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);

        $catWeight = ['staff' => 0, 'case' => 1, 'manager' => 2, 'hq' => 3];
        $ledger = [];
        foreach ($records as $rec) {
            $row = $this->jsonValue($rec->row_data) ?: [];
            if (!$row) continue;
            $cat = $rec->is_hq_row ? 'hq' : ($rec->is_manager_row ? 'manager' : ($rec->is_case_row ? 'case' : 'staff'));
            $ledger[] = $this->perfLedgerEntry($row, $cat);
        }
        usort($ledger, fn ($a, $b) =>
            ($catWeight[$a['cat']] <=> $catWeight[$b['cat']])
            ?: strcmp($a['project'], $b['project'])
            ?: strcmp($a['name'], $b['name']));

        $book = new Spreadsheet();
        $this->fillPerfLedgerSheet($book->getActiveSheet(), $ledger, $ym);

        $blocks = [];
        foreach ($ledger as $entry) {
            foreach ($entry['blocks'] as $block) $blocks[] = ['entry' => $entry, 'block' => $block];
        }
        if ($blocks) $this->fillPerfPeriodSheet($book->createSheet(), $blocks, $ym);
        $book->setActiveSheetIndex(0);

        return $this->xlsx($book, '绩效工资专项表_' . $ym . ($project !== '' ? '_' . $project : '') . '.xlsx');
    }

    /** 单行工资结果 → 绩效台账条目（含可展开的周期兑现块） */
    private function perfLedgerEntry(array $row, string $cat): array
    {
        $catLabels = ['staff' => '基层员工', 'case' => '案场人员', 'manager' => '管理人员', 'hq' => '总部人员'];
        $perfPay = round((float) ($row['perf_pay'] ?? 0), 2);
        $entry = [
            'cat' => $cat, 'catLabel' => $catLabels[$cat],
            'project' => (string) ($row['project'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'grade' => '', 'mode' => '月度发放', 'coef' => '', 'base' => '',
            'perf_pay' => $perfPay, 'status' => '', 'blocks' => [],
        ];

        $d = is_array($row['perf_detail'] ?? null) ? $row['perf_detail'] : null;
        if ($d === null) {
            // 月度发放：基数按"应发÷月系数"反推（保证 基数×系数≈应发 可核对）；系数 0 时基数无意义留空
            $coef = (float) ($row['coef'] ?? 0);
            $entry['coef'] = $coef;
            $entry['base'] = $coef != 0.0 ? round($perfPay / $coef, 2) : '';
            $entry['status'] = $perfPay > 0 ? '正常' : '本月绩效为0';
            return $entry;
        }

        // 周期兑现（季度/半年度）
        $entry['grade'] = (string) ($d['pay_grade'] ?? '');
        $qErr = $d['error'] ?? null;
        $h = is_array($d['half_year'] ?? null) ? $d['half_year'] : null;
        $hErr = $h ? ($h['error'] ?? null) : null;
        $qOk = !$qErr && is_array($d['months'] ?? null);
        $hOk = $h && !$hErr && is_array($h['months'] ?? null);

        $modes = []; $notes = []; $baseSum = 0.0; $singleCoef = '';
        if ($qOk) {
            $modes[] = '季度兑现';
            $qBase = round(array_sum(array_map(fn ($m) => (float) ($m['amount'] ?? 0), $d['months'])), 2);
            $entry['blocks'][] = [
                'kind' => '季度', 'period' => (string) ($d['period'] ?? ''),
                'coef' => (float) ($d['coef'] ?? 0),
                'ratio' => array_key_exists('ratio', $d) ? (float) $d['ratio'] : 1.0,
                'months' => $d['months'],
            ];
            $baseSum += $qBase;
            $singleCoef = (float) ($d['coef'] ?? 0);
        } elseif ($qErr) {
            $notes[] = '季度：' . (self::PERF_ERROR_LABELS[$qErr] ?? $qErr);
        }
        if ($hOk) {
            $modes[] = '半年度兑现';
            $hBase = round(array_sum(array_map(fn ($m) => (float) ($m['amount'] ?? 0), $h['months'])), 2);
            $entry['blocks'][] = [
                'kind' => '半年度', 'period' => (string) ($h['period'] ?? ''),
                'coef' => (float) ($h['coef'] ?? 0),
                'ratio' => array_key_exists('ratio', $h) ? (float) $h['ratio'] : 1.0,
                'months' => $h['months'],
            ];
            $baseSum += $hBase;
            if (!$qOk) $singleCoef = (float) ($h['coef'] ?? 0);
        } elseif ($hErr) {
            $notes[] = '半年度：' . (self::PERF_ERROR_LABELS[$hErr] ?? $hErr);
        }

        $entry['mode'] = $modes ? implode('+', $modes) : '周期兑现失败';
        // 仅单笔兑现时展示系数（两笔混合系数不同，单一数字会误导）；基数为各笔逐月计提合计
        $entry['coef'] = count($modes) === 1 ? $singleCoef : '';
        $entry['base'] = $baseSum > 0 ? $baseSum : '';
        $entry['status'] = $notes
            ? implode('；', array_merge($modes ? ['正常发放：' . implode('+', $modes)] : [], $notes))
            : '正常';
        return $entry;
    }

    /** Sheet1：当月绩效发放台账 */
    private function fillPerfLedgerSheet($sheet, array $ledger, string $ym): void
    {
        $head = ['序号', '项目', '部门', '岗位', '姓名', '人员类别', '薪酬档位', '发放方式',
            '绩效系数', '计提基数', '应发绩效工资', '状态'];
        $widths = [6, 16, 12, 12, 10, 10, 10, 20, 9, 11, 12, 34];
        $bands = [self::COL_BASE, self::COL_BASE, self::COL_BASE, self::COL_BASE, self::COL_BASE,
            self::COL_BASE, self::COL_ATT, self::COL_ATT, self::COL_PAY, self::COL_PAY, self::COL_PAY, self::COL_NET];

        $sheet->setTitle('当月绩效发放台账');
        $sheet->mergeCells('A1:L1');
        $sheet->setCellValue('A1', $ym . ' 绩效工资发放台账（月度发放 / 季度·半年度兑现）');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(28);
        foreach ($head as $ci => $v) {
            $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
            $sheet->setCellValue($col, $v);
            $st = $sheet->getStyle($col);
            $st->getFont()->setBold(true);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bands[$ci]);
            $st->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(24);

        $r = 3;
        foreach ($ledger as $i => $e) {
            $values = [$i + 1, $e['project'], $e['department'], $e['position'], $e['name'], $e['catLabel'],
                $e['grade'], $e['mode'], $e['coef'], $e['base'], $e['perf_pay'], $e['status']];
            foreach ($values as $ci => $v) {
                $letter = Coordinate::stringFromColumnIndex($ci + 1);
                $sheet->setCellValue($letter . $r, $v);
                $st = $sheet->getStyle($letter . $r);
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                if (in_array($ci + 1, [9, 10, 11], true)) {
                    $st->getNumberFormat()->setFormatCode('#,##0.00');
                    $st->getAlignment()->setHorizontal('right');
                } elseif ($ci === 11) {
                    $st->getAlignment()->setHorizontal('left')->setWrapText(true);
                } else {
                    $st->getAlignment()->setHorizontal('center')->setVertical('center');
                }
            }
            // 异常状态整行标红字
            if ($e['status'] !== '正常') {
                $sheet->getStyle('L' . $r)->getFont()->getColor()->setRGB('C00000');
            }
            $sheet->getStyle('K' . $r)->getFont()->setBold(true);
            $sheet->getRowDimension($r)->setRowHeight(20);
            $r++;
        }

        // 合计行
        $sheet->setCellValue('A' . $r, '合计（' . count($ledger) . '人）');
        $sheet->mergeCells("A{$r}:J{$r}");
        $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal('right');
        $sheet->setCellValue('K' . $r, '=SUM(K3:K' . ($r - 1) . ')');
        $sheet->getStyle('K' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A{$r}:L{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:L{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');

        foreach ($widths as $ci => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($w);
        }
        $sheet->freezePane('F3');
    }

    /** Sheet2：周期兑现逐月基数（每人每周期一块，逐月行 + 小计行） */
    private function fillPerfPeriodSheet($sheet, array $blocks, string $ym): void
    {
        $head = ['序号', '项目', '姓名', '人员类别', '兑现类型', '周期', '月份',
            '绩效出勤(天)', '月基数', '月计提额', '周期系数', '兑现比例', '本笔兑现额'];
        $widths = [6, 16, 10, 10, 9, 10, 10, 11, 10, 11, 9, 9, 12];

        $sheet->setTitle('周期兑现逐月基数');
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', $ym . ' 季度/半年度绩效兑现逐月基数表');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(28);
        foreach ($head as $ci => $v) {
            $col = Coordinate::stringFromColumnIndex($ci + 1) . '2';
            $sheet->setCellValue($col, $v);
            $st = $sheet->getStyle($col);
            $st->getFont()->setBold(true);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COL_SOC);
            $st->getAlignment()->setHorizontal('center');
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(24);

        $r = 3; $seq = 0;
        foreach ($blocks as $item) {
            $e = $item['entry']; $b = $item['block'];
            $sumAmount = 0.0;
            foreach ($b['months'] as $m) {
                $seq++;
                $values = [$seq, $e['project'], $e['name'], $e['catLabel'], $b['kind'], $b['period'],
                    (string) ($m['ym'] ?? ''), (float) ($m['perf_att'] ?? 0), (float) ($m['base'] ?? 0),
                    (float) ($m['amount'] ?? 0), $b['coef'], $b['ratio'], ''];
                foreach ($values as $ci => $v) {
                    $letter = Coordinate::stringFromColumnIndex($ci + 1);
                    $sheet->setCellValue($letter . $r, $v);
                    $st = $sheet->getStyle($letter . $r);
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                    if (in_array($ci + 1, [9, 10], true)) {
                        $st->getNumberFormat()->setFormatCode('#,##0.00');
                        $st->getAlignment()->setHorizontal('right');
                    } elseif ($ci === 11) {
                        $st->getNumberFormat()->setFormatCode('0.00');
                        $st->getAlignment()->setHorizontal('center');
                    } else {
                        $st->getAlignment()->setHorizontal('center')->setVertical('center');
                    }
                }
                $sumAmount += (float) ($m['amount'] ?? 0);
                $r++;
            }
            // 小计行：兑现额 = Σ月计提 × 周期系数 × 兑现比例（与 PayrollCalculator 同口径，到分）
            $pay = round($sumAmount * (float) $b['coef'] * (float) $b['ratio'], 2);
            $sheet->setCellValue('A' . $r, $e['name'] . ' ' . $b['period'] . $b['kind'] . '小计');
            $sheet->mergeCells("A{$r}:I{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal('right');
            $sheet->setCellValue('J' . $r, round($sumAmount, 2));
            $sheet->setCellValue('K' . $r, $b['coef']);
            $sheet->setCellValue('L' . $r, $b['ratio']);
            $sheet->setCellValue('M' . $r, $pay);
            foreach (['J', 'M'] as $c) {
                $sheet->getStyle($c . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle($c . $r)->getAlignment()->setHorizontal('right');
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF3E0');
            $r++;
        }

        foreach ($widths as $ci => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci + 1))->setWidth($w);
        }
        $sheet->freezePane('C3');
    }

    public function dashboard(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->input('ym', now()->format('Y-m')); $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name)->where('is_manager_row', false)->where('is_hq_row', false);
        } else {
            $query->where('is_hq_row', false);
        }
        $rows = $query->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: []);
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('数据驾驶舱');
        $sheet->fromArray(['指标', '数值'], null, 'A1');
        $sheet->fromArray(['月份', $ym], null, 'A2');
        $sheet->fromArray(['发放人数', $rows->count()], null, 'A3');
        $sheet->fromArray(['应发总额', $rows->sum(fn ($r) => (float) ($r['gross'] ?? 0))], null, 'A4');
        $sheet->fromArray(['实发总额', $rows->sum(fn ($r) => (float) ($r['net'] ?? 0))], null, 'A5');
        return $this->xlsx($book, '薪资驾驶舱_' . $ym . '.xlsx');
    }

    private function rows(string $ym, string $project)
    {
        $rows = DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)
            ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->all();
        return collect(\App\Services\PayrollCalculator::orderRows($rows))->values();
    }

    /** 案场人员工资表导出：选月份 → 所有项目案场人员一张 Excel，含项目列（仅总部） */
    public function caseStaff(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '全部案场人员工资表仅总部可导出'], 403);
        }
        $ym = $request->string('ym')->toString();
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_case_row', true)->where('is_hq_row', false)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '无案场人员核算数据'], 404);
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $ym . '案场人员工资表');
        return $this->xlsx($book, '案场人员工资表_' . $ym . '.xlsx');
    }

    /** 分项目案场人员工资表导出：选项目 → 该项目案场人员单独一张 Excel（总部可任选；项目账号限本项目） */
    public function projectCaseStaff(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if ($project === '') return response()->json(['ok' => false, 'error' => '请选择项目'], 422);
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('project_name', $project)
            ->where('is_case_row', true)->where('is_hq_row', false)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '该项目无案场人员核算数据'], 404);
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $project . ' ' . $ym . '案场人员工资表');
        return $this->xlsx($book, $project . '_案场人员工资表_' . $ym . '.xlsx');
    }

    /** 管理人员工资表导出（仅总部）：选月份 → 所有项目管理人员一张 Excel，含项目列 */
    public function managers(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '管理人员工资表仅总部可导出'], 403);
        }
        $ym = $request->string('ym')->toString();
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_manager_row', true)->where('is_hq_row', false)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '无管理人员核算数据'], 404);
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $ym . '管理人员工资表', true);
        return $this->xlsx($book, '管理人员工资表_' . $ym . '.xlsx');
    }

    /** 总部人员工资表导出（仅总部）：选月份 → 物业总部所有人员一张 Excel，含项目列 */
    public function hqStaff(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '总部人员工资表仅总部可导出'], 403);
        }
        $ym = $request->string('ym')->toString();
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_hq_row', true)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '无总部人员核算数据'], 404);
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $ym . '总部人员工资表', true);
        return $this->xlsx($book, '总部人员工资表_' . $ym . '.xlsx');
    }
    /** 分项目管理人员工资表导出（仅总部）：选项目 → 该项目管理人员单独一张 Excel */
    public function projectManagers(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '管理人员工资表仅总部可导出'], 403);
        }
        $ym = $request->string('ym')->toString();
        $project = $request->string('project')->toString();
        if ($project === '') return response()->json(['ok' => false, 'error' => '请选择项目'], 422);
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('project_name', $project)
            ->where('is_manager_row', true)->where('is_hq_row', false)
            ->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->filter();
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '该项目无管理人员核算数据'], 404);
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $book = new Spreadsheet();
        $this->fillSheet($book->getActiveSheet(), $rows, $project . ' ' . $ym . '管理人员工资表', true);
        return $this->xlsx($book, $project . '_管理人员工资表_' . $ym . '.xlsx');
    }

    private function fillSheet($sheet, $rows, string $title, bool $withPerfDetail = false): void
    {
        $sheet->setTitle(preg_replace('#[\[\]:*?/\\\\]#u', '_', mb_substr($title, 0, 31)));
        // 季度绩效逐月明细列（管理/总部工资表）：key = "Q1|2026-01" / "H1|2026-04"
        $detailCols = [];
        if ($withPerfDetail) {
            foreach ($rows as $row) {
                $d = $row['perf_detail'] ?? null;
                if (!is_array($d) || isset($d['error']) || !is_array($d['months'] ?? null)) continue;
                $qTag = substr((string)($d['period'] ?? ''), 5) ?: 'Q';
                foreach ($d['months'] as $m) {
                    $k = $qTag . '|' . $m['ym'];
                    $detailCols[$k] = $qTag . '·' . (int)substr($m['ym'], 5, 2) . '月绩效';
                }
                $h = $d['half_year'] ?? null;
                if (is_array($h) && !isset($h['error']) && is_array($h['months'] ?? null)) {
                    $hTag = substr((string)($h['period'] ?? ''), 5) ?: 'H';
                    foreach ($h['months'] as $m) {
                        $k = $hTag . '|' . $m['ym'];
                        $detailCols[$k] = $hTag . '·' . (int)substr($m['ym'], 5, 2) . '月绩效';
                    }
                }
            }
            // 按月排序；同月内季度列(Q)在前、半年度列(H)在后，与页面子行顺序一致
            uksort($detailCols, fn ($a, $b) => strcmp(
                substr($a, -7) . (str_starts_with($a, 'H') ? '~' : '') . $a,
                substr($b, -7) . (str_starts_with($b, 'H') ? '~' : '') . $b
            ));
        }
        $headers = array_merge(self::COLUMNS, array_values($detailCols));
        $colCount = count($headers);
        $lastLetter = Coordinate::stringFromColumnIndex($colCount);

        // ===== 第1行：大标题 =====
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(28);

        // ===== 第2行：表头（分区配色）=====
        $sheet->fromArray($headers, null, 'A2');
        for ($c = 1; $c <= $colCount; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $style = $sheet->getStyle("{$letter}2");
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->columnBand($c));
            $style->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
            $style->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(34);
        $sheet->freezePane('F3'); // 冻结表头两行 + 前5列（序号/项目/部门/岗位/姓名）

        // ===== 数据行 =====
        $rowNumber = 3;
        $baseColCount = count(self::COLUMNS);
        $detailIdx = array_keys($detailCols);
        $moneyCols = [7, 8, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35];
        for ($c = $baseColCount + 1; $c <= $colCount; $c++) $moneyCols[] = $c;
        foreach ($rows->values() as $index => $row) {
            $values = [$index + 1, $row['project'] ?? '', $row['department'] ?? '', $row['position'] ?? '', $row['name'] ?? '',
                $row['status'] ?? '', $row['fixed'] ?? 0, $row['base'] ?? 0, $row['req_att'] ?? 0, $row['act_att'] ?? 0,
                $row['coef'] ?? 0, $row['base_pay'] ?? 0, $row['perf_pay'] ?? 0, $row['sick_pay'] ?? 0,
                $row['night'] ?? 0, $row['meal'] ?? 0, $row['title_sub'] ?? 0, $row['reward'] ?? 0, $row['welfare'] ?? 0,
                $row['punish'] ?? 0, $row['late_d'] ?? 0, $row['miss_d'] ?? 0, $row['other_d'] ?? 0, $row['uniform_d'] ?? 0,
                $row['gross'] ?? 0, $row['pen'] ?? 0, $row['med'] ?? 0, $row['une'] ?? 0, $row['house'] ?? 0, $row['big'] ?? 0,
                $row['soc_total'] ?? 0, $row['spec_total'] ?? 0, $row['actual_tax'] ?? 0, $row['tax_diff'] ?? 0, $row['net'] ?? 0, $row['remark'] ?? ''];
            // 逐月绩效明细列值（无该月明细 → 空）
            foreach ($detailIdx as $k) {
                [$tag, $ym] = explode('|', $k);
                $amt = '';
                $d = $row['perf_detail'] ?? null;
                if (is_array($d) && !isset($d['error'])) {
                    $src = str_starts_with($tag, 'H') ? ($d['half_year']['months'] ?? []) : ($d['months'] ?? []);
                    foreach ($src as $m) {
                        if (($m['ym'] ?? '') === $ym) { $amt = $m['amount'] ?? 0; break; }
                    }
                }
                $values[] = $amt;
            }
            $sheet->fromArray($values, null, 'A' . $rowNumber);
            // 显式重写数值（fromArray 会跳过 float(0) 单元格，导致 0 值列留空）
            foreach ($values as $ci => $v) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $rowNumber, $v);
            }
            for ($c = 1; $c <= $colCount; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $cellStyle = $sheet->getStyle("{$letter}{$rowNumber}");
                $cellStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                $cellStyle->getFont()->setName('微软雅黑')->setSize(10);
                if (in_array($c, $moneyCols, true)) {
                    $cellStyle->getNumberFormat()->setFormatCode('#,##0.00');
                    $cellStyle->getAlignment()->setHorizontal('right');
                } elseif (in_array($c, [9, 10, 11], true)) {
                    $cellStyle->getAlignment()->setHorizontal('center');
                } elseif ($c === 36) {
                    $cellStyle->getAlignment()->setHorizontal('left');
                } else {
                    $cellStyle->getAlignment()->setHorizontal('center')->setVertical('center');
                }
            }
            // 应发合计(25)、实发(35)加粗
            $sheet->getStyle(Coordinate::stringFromColumnIndex(25) . $rowNumber)->getFont()->setBold(true);
            $sheet->getStyle(Coordinate::stringFromColumnIndex(35) . $rowNumber)->getFont()->setBold(true);
            $sheet->getRowDimension($rowNumber)->setRowHeight(20);
            $rowNumber++;
        }

        // ===== 合计行 =====
        $totalRow = $rowNumber;
        $sumCols = [12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35];
        for ($c = $baseColCount + 1; $c <= $colCount; $c++) $sumCols[] = $c;
        $sheet->setCellValue('A' . $totalRow, '合计（' . $rows->count() . '人）');
        $sheet->mergeCells('A' . $totalRow . ':K' . $totalRow);
        $sheet->getStyle('A' . $totalRow)->getAlignment()->setHorizontal('right');
        foreach ($sumCols as $c) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $sheet->setCellValue($letter . $totalRow, '=SUM(' . $letter . '3:' . $letter . ($totalRow - 1) . ')');
            $sheet->getStyle($letter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($letter . $totalRow)->getAlignment()->setHorizontal('right');
        }
        for ($c = 1; $c <= $colCount; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $st = $sheet->getStyle($letter . $totalRow);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $st->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension($totalRow)->setRowHeight(22);

        // ===== 列宽 =====
        $widths = [6, 14, 12, 12, 10, 9, 10, 10, 8, 8, 8, 11, 11, 10, 12, 9, 10, 10, 10, 10, 11, 10, 10, 10, 12,
            10, 10, 10, 11, 8, 12, 12, 10, 12, 16];
        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($widths[$c - 1] ?? 11);
        }
    }

    private function xlsx(Spreadsheet $book, string $filename)
    {
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
