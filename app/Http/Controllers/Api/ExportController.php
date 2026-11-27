<?php

namespace App\Http\Controllers\Api;

use App\Services\CalcRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Color, Alignment, Border, Fill, Font, Style, Conditional};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use ZipArchive;

class ExportController extends ApiController
{
    private const COLUMNS = ['序号', '项目', '部门', '岗位', '姓名', '人员状态', '入职日期', '固定月薪', '基本工资', '应出勤', '实际出勤',
        '绩效系数', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴', '月度奖励',
        '补发工资', '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款', '应发工资合计', '养老保险',
        '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计', '专项附加扣除', '本月个税', '个税补差', '实发工资', '备注'];

    /** 表头分区配色（列序号从 1 开始），与考勤模板风格一致 */
    private const COL_BASE = 'DDEBF7';   // 基本信息：浅蓝
    private const COL_ATT  = 'F2F2F2';   // 出勤统计：浅灰
    private const COL_PAY  = 'E2EFDA';   // 奖励/补贴：浅绿
    private const COL_DED  = 'F8D7DA';   // 扣罚/扣款：浅红
    private const COL_SOC  = 'FFF2CC';   // 五险一金：浅黄
    private const COL_NET  = 'FCE4D6';   // 个税/实发：浅橙

    /** 每列归属的分区（与 COLUMNS 一一对应） */
    private function columnBand(int $idx): string
    {
        return match (true) {
            $idx <= 9                       => self::COL_BASE,  // 序号..基本工资（含入职日期）
            $idx <= 12                      => self::COL_ATT,   // 应出勤..绩效系数
            $idx <= 21                      => self::COL_PAY,   // 应发基本..已发福利（奖励/补贴）
            $idx <= 26                      => self::COL_DED,   // 月度扣罚..工装扣款（扣罚/扣款）
            $idx <= 27                      => self::COL_PAY,   // 应发工资合计
            $idx <= 33                      => self::COL_SOC,   // 五险..五险一金合计
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

    /** 导出模式：显式参数优先，缺省/非法值回退 config('payroll.export_mode')。 */
    private function resolveExportMode(Request $request): string
    {
        $mode = strtolower(trim($request->string('mode')->toString()));
        if (in_array($mode, ['plain', 'formula'], true)) return $mode;
        $configured = (string) config('payroll.export_mode', 'formula');
        return in_array($configured, ['plain', 'formula'], true) ? $configured : 'formula';
    }

    /** 导出模式选项（前端读取默认值用，避免前后端各写一份默认配置）。 */
    public function exportMode(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $default = $this->resolveExportMode($request);
        return response()->json([
            'ok' => true,
            'default' => $default,
            'options' => [
                ['value' => 'formula', 'label' => '带公式（合计自动重算，可核对）'],
                ['value' => 'plain', 'label' => '纯数值（固定数值，兼容旧版）'],
            ],
        ]);
    }

    public function project(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;
        $project = $this->isProjectScope($account) ? (string) $account->project_name : trim($request->string('project')->toString());
        // 项目留空 = 导出"全部项目"明细（页面项目筛选为"全部项目"时的默认行为）
        $rows = $this->rows($ym, $project === '' ? null : $project);
        if ($rows->isEmpty()) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);
        $label = $project !== '' ? $project : '全部项目';
        return $this->singleSheetResponse($request, $rows, $label . ' ' . $ym . '工资表', $ym, $label . '_' . $ym . '工资表.xlsx');
    }

    public function summary(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $deny = $this->denyProjectNotArchived($account, $ym);
        if ($deny) return $deny;
        $formula = $this->resolveExportMode($request) === 'formula';
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
            ->filter(fn ($p) => $p !== \App\Services\PayrollCalculator::HQ_PROJECT)->values()->all();
        $r = 3;
        $projRowIndex = [];
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
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sumB['cnt'] += $b['cnt']; $sumB['gross'] += $b['gross'];
            $sumM['cnt'] += $m['cnt']; $sumM['gross'] += $m['gross'];
            $sumBudget += $budget; $sumAnnual += $annualBudget; $sumPrev += $prev; $sumYtd += ($ytdBase[$p]['gross'] ?? 0);
            $projRowIndex[$p] = $r;
            $r++;
        }
        $last = $r - 1;
        $lastProjRow = $r - 1;

        // 合计行（基层员工合计，不含物业总部）
        $bGross = round($sumB['gross'], 2);
        $prevBaseScope = $isProjScope ? array_intersect_key($prevBase, $curBase) : array_diff_key($prevBase, [\App\Services\PayrollCalculator::HQ_PROJECT => true]);
        $bPrev = round(array_sum(array_column($prevBaseScope, 'gross')), 2);
        $bYtd = round(array_sum(array_column($isProjScope ? array_intersect_key($ytdBase, $curBase) : array_diff_key($ytdBase, [\App\Services\PayrollCalculator::HQ_PROJECT => true]), 'gross')), 2);
        $bDiff = $bPrev > 0 ? round($bGross - $bPrev, 2) : null;
        $totalVals = ['合计', null, null, null, null, $sumB['cnt'], $bGross,
            $sumBudget > 0 ? round($sumBudget, 2) : null,
            $sumBudget > 0 ? round($bGross / $sumBudget, 4) : null,
            $sumAnnual > 0 ? round($bYtd / $sumAnnual, 4) : null,
            $bPrev > 0 ? $bPrev : null, $bDiff,
            ($bPrev > 0 && $bDiff !== null) ? round($bDiff / $bPrev, 4) : null];
        foreach ($totalVals as $ci => $v) {
            if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
        $baseTotalRow = $r;
        $r++;

        $mYtd = round(array_sum(array_column(array_diff_key($ytdMgr, [\App\Services\PayrollCalculator::HQ_PROJECT => true]), 'gross')), 2);
        $cYtd = round(array_sum(array_column($ytdCase, 'gross')), 2);

        // 管理人员合计行（仅总部；管理数据项目账号完全不可见；无数据也显示固定结构，便于核对遗漏）
        if (!$isProjScope) {
            $mGross = round($sumM['gross'], 2);
            $prevMgrNoHq = array_diff_key($prevMgr, [\App\Services\PayrollCalculator::HQ_PROJECT => true]);
            $mPrev = round(array_sum(array_column($prevMgrNoHq, 'gross')), 2);
            $mDiff = $mPrev > 0 ? round($mGross - $mPrev, 2) : null;
            $mgrVals = ['管理人员合计', null, null, null, null, $sumM['cnt'], $mGross,
                null, null, null, $mPrev > 0 ? $mPrev : null, $mDiff,
                ($mPrev > 0 && $mDiff !== null) ? round($mDiff / $mPrev, 4) : null];
            foreach ($mgrVals as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            $mgrTotalRow = $r;
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
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $caseTotalRow = $r;
            $r++;
        }

        // 物业总部合计行（固定置底、总计之前；仅总部；后续架构如何调整物业总部都在此）
        if (!$isProjScope) {
            // 总部人员一套标签（不分基层/管理），在汇总表单列一行：仅人数合计与应发合计
            $hqAgg = $curHq[\App\Services\PayrollCalculator::HQ_PROJECT] ?? ['cnt' => 0, 'gross' => 0.0];
            $hCnt = (int) $hqAgg['cnt'];
            $hGross = round((float) $hqAgg['gross'], 2);
            $hBudget = (float) ($budgetMap[\App\Services\PayrollCalculator::HQ_PROJECT]['months'][$monthKey] ?? 0);
            $hAnnual = (float) ($budgetMap[\App\Services\PayrollCalculator::HQ_PROJECT]['annual'] ?? 0);
            $hYtd = round((float) ($ytdHq[\App\Services\PayrollCalculator::HQ_PROJECT]['gross'] ?? 0), 2);
            $hPrev = round((float) ($prevHq[\App\Services\PayrollCalculator::HQ_PROJECT]['gross'] ?? 0), 2);
            $hDiff = $hPrev > 0 ? round($hGross - $hPrev, 2) : null;
            $hVals = ['物业总部合计', null, null, null, null, $hCnt, $hGross,
                $hBudget > 0 ? $hBudget : null,
                $hBudget > 0 ? round($hGross / $hBudget, 4) : null,
                $hAnnual > 0 ? round($hYtd / $hAnnual, 4) : null,
                $hPrev > 0 ? $hPrev : null, $hDiff,
                ($hPrev > 0 && $hDiff !== null) ? round($hDiff / $hPrev, 4) : null];
            foreach ($hVals as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
            $sumBudget += $hBudget; $sumAnnual += $hAnnual; $sumPrev += $hPrev; $sumYtd += $hYtd;
            $hqTotalRow = $r;
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
            if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:M{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:M{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $last = $r;
        $grandTotalRow = $r;

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
        if ($allMgrs->isNotEmpty()) {
            $ms = $book->createSheet();
            $ms->setTitle('管理人员汇总');
            $mgrSummarySheet = $ms;
            $mgrSummaryInfo = $this->fillMgrSummarySheet($ms, $allMgrs, $ym);
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
                    if ($v !== null && !$this->isZeroNumber($v)) $cs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $cr, $v);
                }
                $csGross += $gross; $csSoc += $soc; $csTax += $tax; $csBonus += $bonus; $csNet += $net; $csDiff += $diff; $csCnt += $cnt;
                $cr++;
            }
            $cTotal = ['合计', $csCnt, round($csGross, 2), round($csSoc, 2), round($csTax, 2), round($csBonus, 2), round($csNet, 2), round($csDiff, 2)];
            foreach ($cTotal as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $cs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $cr, $v);
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
            $caseSummarySheet = $cs;
            $caseSummaryInfo = ['first' => 3, 'last' => $cLast - 1, 'total' => $cLast];
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
                    if ($v !== null && !$this->isZeroNumber($v)) $hs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $hr, $v);
                }
                $hGross2 += $gross; $hSoc += $soc; $hTax += $tax; $hBonus += $bonus; $hNet += $net; $hDiff += $diff; $hCnt2 += $cnt;
                $hr++;
            }
            $hTotal = ['合计', $hCnt2, round($hGross2, 2), round($hSoc, 2), round($hTax, 2), round($hBonus, 2), round($hNet, 2), round($hDiff, 2)];
            foreach ($hTotal as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $hs->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $hr, $v);
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
            $hqSummarySheet = $hs;
            $hqSummaryInfo = ['first' => 3, 'last' => $hLast - 1, 'total' => $hLast];
        }

        // ===== 各项目明细 Sheet（不含管理人员） =====
        $projectDetails = [];
        $detailSheetsForCheck = [];
        foreach ($all->groupBy(fn ($x) => $x['project'] ?? '') as $project => $rows) {
            $detail = $book->createSheet();
            $detail->setTitle(mb_substr((string) $project, 0, 28));
            $list = $rows->values();
            $count = $list->count();
            $grossSum = round($list->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
            if ($formula) {
                $totalRow = $this->fillSheetFormula($detail, $list, $project . ' ' . $ym . '工资表', $ym);
            } else {
                $this->fillSheet($detail, $list, $project . ' ' . $ym . '工资表');
                $totalRow = $count + 3;
            }
            $projectDetails[$project] = ['title' => $detail->getTitle(), 'first' => 3, 'last' => $count + 2,
                'total' => $totalRow, 'count' => $count, 'gross' => $grossSum];
            $detailSheetsForCheck[] = ['title' => $detail->getTitle(), 'total' => $totalRow, 'gross' => $grossSum];
        }
        // ===== 管理人员 Sheet（所有项目管理人员明细） =====
        if ($allMgrs->isNotEmpty()) {
            $mgrSheet = $book->createSheet();
            $mgrSheet->setTitle('管理人员');
            $ordered = collect(\App\Services\PayrollCalculator::orderRows($allMgrs->values()->all()))->values();
            $count = $ordered->count();
            $grossSum = round($ordered->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
            if ($formula) {
                $totalRow = $this->fillSheetFormula($mgrSheet, $ordered, $ym . '管理人员工资表', $ym);
            } else {
                $this->fillSheet($mgrSheet, $ordered, $ym . '管理人员工资表');
                $totalRow = $count + 3;
            }
            $mgrDetailInfo = ['title' => $mgrSheet->getTitle(), 'first' => 3, 'last' => $count + 2,
                'total' => $totalRow, 'count' => $count, 'gross' => $grossSum];
            $detailSheetsForCheck[] = ['title' => $mgrSheet->getTitle(), 'total' => $totalRow, 'gross' => $grossSum];
        }
        // ===== 案场人员 Sheet（所有项目案场人员明细） =====
        if ($allCases->isNotEmpty()) {
            $caseSheet = $book->createSheet();
            $caseSheet->setTitle('案场人员');
            $ordered = collect(\App\Services\PayrollCalculator::orderRows($allCases->values()->all()))->values();
            $count = $ordered->count();
            $grossSum = round($ordered->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
            if ($formula) {
                $totalRow = $this->fillSheetFormula($caseSheet, $ordered, $ym . '案场人员工资表', $ym);
            } else {
                $this->fillSheet($caseSheet, $ordered, $ym . '案场人员工资表');
                $totalRow = $count + 3;
            }
            $caseDetailInfo = ['title' => $caseSheet->getTitle(), 'first' => 3, 'last' => $count + 2,
                'total' => $totalRow, 'count' => $count, 'gross' => $grossSum];
            $detailSheetsForCheck[] = ['title' => $caseSheet->getTitle(), 'total' => $totalRow, 'gross' => $grossSum];
        }
        // ===== 总部人员 Sheet（物业总部所有人员明细，仅总部导出时生成） =====
        if ($allHq->isNotEmpty()) {
            $hqSheet = $book->createSheet();
            $hqSheet->setTitle('总部人员');
            $ordered = collect(\App\Services\PayrollCalculator::orderRows($allHq->values()->all()))->values();
            $count = $ordered->count();
            $grossSum = round($ordered->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
            if ($formula) {
                $totalRow = $this->fillSheetFormula($hqSheet, $ordered, $ym . '总部人员工资表', $ym);
            } else {
                $this->fillSheet($hqSheet, $ordered, $ym . '总部人员工资表');
                $totalRow = $count + 3;
            }
            $hqDetailInfo = ['title' => $hqSheet->getTitle(), 'first' => 3, 'last' => $count + 2,
                'total' => $totalRow, 'count' => $count, 'gross' => $grossSum];
            $detailSheetsForCheck[] = ['title' => $hqSheet->getTitle(), 'total' => $totalRow, 'gross' => $grossSum];
        }

        if ($formula) {
            $this->applyMainSummaryFormulas($sheet, [
                'projRows' => $projRowIndex,
                'firstProj' => 3,
                'lastProj' => $lastProjRow,
                'baseTotal' => $baseTotalRow,
                'mgrTotal' => $mgrTotalRow ?? null,
                'caseTotal' => $caseTotalRow,
                'hqTotal' => $hqTotalRow ?? null,
                'grand' => $grandTotalRow,
                'projectDetails' => $projectDetails,
                'mgrDetail' => $mgrDetailInfo ?? null,
                'caseDetail' => $caseDetailInfo ?? null,
                'hqDetail' => $hqDetailInfo ?? null,
            ]);
            if (isset($mgrSummarySheet, $mgrSummaryInfo, $mgrDetailInfo)) {
                $this->applyRollupFormulas($mgrSummarySheet, $mgrSummaryInfo, $mgrDetailInfo);
            }
            if (isset($caseSummarySheet, $caseSummaryInfo, $caseDetailInfo)) {
                $this->applyRollupFormulas($caseSummarySheet, $caseSummaryInfo, $caseDetailInfo);
            }
            if (isset($hqSummarySheet, $hqSummaryInfo, $hqDetailInfo)) {
                $this->applyRollupFormulas($hqSummarySheet, $hqSummaryInfo, $hqDetailInfo);
            }
            $this->fillNotesSheet($book, $ym, $detailSheetsForCheck, $grandTotalRow, $gGross);
        }
        $book->setActiveSheetIndex(0);
        return $this->xlsx($book, '工资全量数据包_' . $ym . '.xlsx', $formula);
    }

    /** 汇总展示按行类型拆分：基层 / 管理 / 案场 / 总部（总部不进项目汇总）。 */
    private function summarySplitRows($records): array
    {
        $out = ['base' => collect(), 'manager' => collect(), 'case' => collect(), 'hq' => collect()];
        foreach ($records as $record) {
            $row = $this->jsonValue($record->row_data);
            if ((int) ($record->is_hq_row ?? 0) === 1) $out['hq']->push($row);
            elseif ((int) ($record->is_manager_row ?? 0) === 1) $out['manager']->push($row);
            elseif ((int) ($record->is_case_row ?? 0) === 1) $out['case']->push($row);
            else $out['base']->push($row);
        }
        return $out;
    }

    private function summarySum($rows, string $field): float
    {
        return round($rows->sum(fn ($row) => (float) ($row[$field] ?? 0)), 2);
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
            $split = $this->summarySplitRows($records);
            $budget = isset($budgets[$project]) ? ($this->jsonValue($budgets[$project]->data) ?: []) : [];
            $monthBudget = (float) ($budget['months'][(string) $month] ?? 0);
            $annualBudget = (float) ($budget['annual'] ?? 0);
            $ytdSplit = $this->summarySplitRows($allYtd[$project] ?? collect());
            $baseGross = $this->summarySum($split['base'], 'gross');
            $managerGross = $this->summarySum($split['manager'], 'gross');
            $hqGross = $this->summarySum($split['hq'], 'gross');
            $caseGross = $this->summarySum($split['case'], 'gross');
            // 展示口径：基层 + 管理 + 总部合并；案场仍单独列示
            $gross = round($baseGross + $managerGross + $hqGross, 2);
            $baseNet = $this->summarySum($split['base'], 'net');
            $managerNet = $this->summarySum($split['manager'], 'net');
            $hqNet = $this->summarySum($split['hq'], 'net');
            $caseNet = $this->summarySum($split['case'], 'net');
            $ytdBase = $this->summarySum($ytdSplit['base'], 'gross');
            $ytdManager = $this->summarySum($ytdSplit['manager'], 'gross');
            $ytdHq = $this->summarySum($ytdSplit['hq'], 'gross');
            $ytdCase = $this->summarySum($ytdSplit['case'], 'gross');
            $ytdGross = round($ytdBase + $ytdManager + $ytdHq, 2);
            $items[] = [
                'project' => $project,
                'headcount' => $split['base']->count() + $split['manager']->count() + $split['hq']->count(),
                'base_headcount' => $split['base']->count(),
                'manager_headcount' => $split['manager']->count(),
                'case_headcount' => $split['case']->count(),
                'base_gross' => $baseGross, 'manager_gross' => $managerGross, 'case_gross' => $caseGross,
                'base_net' => $baseNet, 'manager_net' => $managerNet, 'case_net' => $caseNet,
                'gross' => $gross, 'net' => round($baseNet + $managerNet + $hqNet, 2),
                'month_budget' => $monthBudget,
                'month_rate' => $monthBudget > 0 ? round($gross / $monthBudget, 4) : 0,
                'annual_budget' => $annualBudget,
                'ytd_base_gross' => $ytdBase, 'ytd_manager_gross' => $ytdManager, 'ytd_case_gross' => $ytdCase,
                'ytd_gross' => $ytdGross,
                'annual_rate' => $annualBudget > 0 ? round($ytdGross / $annualBudget, 4) : 0,
                'calculated' => true,
            ];
        }
        // 项目筛选（支持多选，逗号分隔）
        $projectFilter = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        $selected = array_values(array_filter(array_map('trim', explode(',', $projectFilter)), fn ($v) => $v !== ''));
        if ($selected) {
            $items = array_values(array_filter($items, fn ($it) => in_array($it['project'], $selected, true)));
        }
        if (!$items) return response()->json(['ok' => false, 'error' => '无核算数据'], 404);

        $tHead = ['headcount' => 0, 'base_headcount' => 0, 'manager_headcount' => 0, 'case_headcount' => 0,
            'base_gross' => 0.0, 'manager_gross' => 0.0, 'case_gross' => 0.0,
            'base_net' => 0.0, 'manager_net' => 0.0, 'case_net' => 0.0,
            'gross' => 0.0, 'net' => 0.0, 'month_budget' => 0.0, 'annual_budget' => 0.0,
            'ytd_base_gross' => 0.0, 'ytd_manager_gross' => 0.0, 'ytd_case_gross' => 0.0, 'ytd_gross' => 0.0];
        foreach ($items as $it) {
            foreach (array_keys($tHead) as $k) $tHead[$k] += $it[$k];
        }
        foreach (array_keys($tHead) as $k) {
            if (!in_array($k, ['headcount', 'base_headcount', 'manager_headcount', 'case_headcount'], true)) {
                $tHead[$k] = round($tHead[$k], 2);
            }
        }
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

        $head = ['项目', '人数', '应发', '实发', '当月预算', '当月执行率', '年度预算', '年度累计应发', '年度执行率', '核算状态'];
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
            $vals = [$it['project'], $it['headcount'], $it['gross'], $it['net'], $it['month_budget'], $it['month_rate'],
                $it['annual_budget'], $it['ytd_gross'], $it['annual_rate'], $it['calculated'] ? '已核算' : '未核算'];
            foreach ($vals as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $r++;
        }
        $tVals = ['总计', $tHead['headcount'], $tHead['gross'], $tHead['net'], $tHead['month_budget'],
            $tHead['month_rate'], $tHead['annual_budget'], $tHead['ytd_gross'], $tHead['annual_rate'], ''];
        foreach ($tVals as $ci => $v) {
            if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $mainLast = $r;

        // 案场人员单独一段，不计入上面的项目合计
        $r += 2;
        $sheet->mergeCells("A{$r}:J{$r}");
        $sheet->setCellValue("A{$r}", '案场人员（单独核算，不计入项目合计）');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
        $r++;
        $caseHead = ['项目', '案场人数', '案场应发', '案场实发'];
        foreach ($caseHead as $ci => $v) {
            $col = Coordinate::stringFromColumnIndex($ci + 1) . $r;
            $sheet->setCellValue($col, $v);
            $sheet->getStyle($col)->getFont()->setBold(true);
            $sheet->getStyle($col)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
            $sheet->getStyle($col)->getAlignment()->setHorizontal('center');
        }
        $r++;
        foreach ($items as $it) {
            if ($it['case_headcount'] <= 0) continue;
            $vals = [$it['project'], $it['case_headcount'], $it['case_gross'], $it['case_net']];
            foreach ($vals as $ci => $v) {
                if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
            }
            $r++;
        }
        $caseVals = ['案场合计（不计入项目合计）', $tHead['case_headcount'], $tHead['case_gross'], $tHead['case_net']];
        foreach ($caseVals as $ci => $v) {
            if ($v !== null && !$this->isZeroNumber($v)) $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $r, $v);
        }
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $last = $r;
        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(10);
        foreach (['C', 'D', 'E', 'G', 'H'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(15);
        }
        foreach (['F', 'I'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(12);
        }
        $sheet->getColumnDimension('J')->setWidth(10);
        foreach (range(2, $last) as $rr) {
            foreach (range('A', 'J') as $cc) {
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
        $formula = $this->resolveExportMode($request) === 'formula';
        foreach ($groups as $project => $rows) {
            $book = new Spreadsheet();
            if ($formula) {
                $this->fillSheetFormula($book->getActiveSheet(), $rows, $project . ' ' . $ym . '工资表', $ym);
                $bytes = $this->xlsxBinary($book, true);
            } else {
                $this->fillSheet($book->getActiveSheet(), $rows, $project . ' ' . $ym . '工资表');
                $tmp = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($tmp); rewind($tmp);
                $bytes = stream_get_contents($tmp); fclose($tmp);
            }
            $zip->addFromString($project . '_' . $ym . '工资表.xlsx', $bytes);
        }
        $zip->close();
        return response()->download($zipPath, '全部项目工资表_' . $ym . '.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
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

    /**
     * 考勤导出（数据导出模块）：GET /api/export/attendance?ym=&project=&person_type=&mode=&dry_run=1
     *
     * - project=all（留空同义）= 全部项目；否则为单个项目
     * - person_type=all|manager|case（管理人员/案场人员按月 categoryMap 分类，与工资核算同源）
     * - mode=merged 全部项目合并一张表（含项目列）；mode=bundle 全量数据包（每项目一张 Sheet，
     *   项目维度固定为全部项目，人员类型筛选同样生效）
     * - 只导出已锁定月份；未锁定项目不静默跳过：dry_run=1 返回 skipped_unlocked 供前端提示，
     *   正式导出时同样跳过这些项目
     */
    public function attendance(Request $request, \App\Services\AttendanceExportService $service)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份格式错误'], 400);
        }
        $mode = $request->string('mode')->toString() ?: 'merged';
        if (!in_array($mode, ['merged', 'bundle'], true)) {
            return response()->json(['ok' => false, 'error' => '打包方式参数错误'], 400);
        }
        $personType = $request->string('person_type')->toString() ?: 'all';
        $typeMap = ['all' => '', 'manager' => 'manager', 'case' => 'case'];
        if (!array_key_exists($personType, $typeMap)) {
            return response()->json(['ok' => false, 'error' => '人员类型参数错误'], 400);
        }
        $category = $typeMap[$personType];
        $typeLabel = ['all' => '全部人员', 'manager' => '管理人员', 'case' => '案场人员'][$personType];

        // 项目范围：项目账号强制本项目；总部账号可选全部项目或单项目。
        // 全量数据包固定为全部项目（项目账号仍限本项目）。
        $enabled = DB::table('payroll_projects')->where('status', '启用')->orderBy('id')->pluck('name')->all();
        if ($this->isProjectScope($account)) {
            $projects = [(string) $account->project_name];
            $projectLabel = $projects[0];
        } elseif ($mode === 'bundle') {
            $projects = $enabled;
            $projectLabel = '全部项目';
        } else {
            $project = trim($request->string('project')->toString());
            if ($project === '' || $project === 'all') {
                $projects = $enabled;
                $projectLabel = '全部项目';
            } else {
                if (!in_array($project, $enabled, true)) {
                    return response()->json(['ok' => false, 'error' => '项目不存在或未启用'], 422);
                }
                $projects = [$project];
                $projectLabel = $project;
            }
        }
        if (!$projects) return response()->json(['ok' => false, 'error' => '暂无启用项目'], 404);

        $prepared = $service->prepare($ym, $projects, $category, [
            'use_month_category' => true, // 管理人员/案场人员按核算月生效分类，与工资核算同源
            'skip_unlocked' => true,      // 未锁定项目不导出，由前端据 dry_run 结果提示
        ]);

        $peopleByProject = [];
        $total = 0;
        foreach ($projects as $p) {
            $rows = $prepared['projects'][$p]['people'] ?? [];
            if (!$rows) continue;
            $peopleByProject[$p] = $rows;
            $total += count($rows);
        }

        if ($request->boolean('dry_run')) {
            return response()->json([
                'ok' => true, 'ym' => $ym, 'mode' => $mode, 'person_type' => $personType,
                'exported' => array_keys($peopleByProject),
                'rows' => $total,
                'skipped_unlocked' => $prepared['skipped_unlocked'],
                'skipped_no_data' => $prepared['skipped_no_data'],
                'skipped_no_type_rows' => $prepared['skipped_no_type_rows'],
            ]);
        }

        if (!$total) {
            return response()->json([
                'ok' => false, 'error' => '没有符合条件的已锁定考勤数据',
                'skipped_unlocked' => $prepared['skipped_unlocked'],
            ], 404);
        }

        $book = new Spreadsheet();
        if ($mode === 'bundle') {
            $titles = [];
            $first = true;
            foreach ($peopleByProject as $p => $rows) {
                $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
                $first = false;
                $title = $service->sheetTitle($p, $titles);
                $sheet->setTitle($title);
                $titles[] = $title;
                // 每项目一张表：单项目口径，不输出「项目」列
                $service->writeSheet($sheet, $rows, $ym, $p, false, $typeLabel);
            }
            $book->setActiveSheetIndex(0);
            $filename = "考勤全量数据包_{$projectLabel}_{$typeLabel}_{$ym}.xlsx";
        } else {
            $people = [];
            foreach ($peopleByProject as $rows) {
                foreach ($rows as $person) $people[] = $person;
            }
            $service->writeSheet($book->getActiveSheet(), $people, $ym, $projectLabel, $projectLabel === '全部项目', $typeLabel);
            $filename = "考勤导出_{$projectLabel}_{$typeLabel}_{$ym}.xlsx";
        }

        return $this->xlsx($book, $filename);
    }

    private function rows(string $ym, ?string $project = null)
    {
        $query = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        // 项目为空 = 总部导出"全部项目"（项目账号已在入口强制成自己的项目）
        if ($project !== null && $project !== '') $query->where('project_name', $project);
        $rows = $query->get()->map(fn ($r) => $this->jsonValue($r->row_data) ?: [])->all();
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
        return $this->singleSheetResponse($request, $rows, $ym . '案场人员工资表', $ym, '案场人员工资表_' . $ym . '.xlsx');
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
        return $this->singleSheetResponse($request, $rows, $project . ' ' . $ym . '案场人员工资表', $ym, $project . '_案场人员工资表_' . $ym . '.xlsx');
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
        return $this->singleSheetResponse($request, $rows, $ym . '管理人员工资表', $ym, '管理人员工资表_' . $ym . '.xlsx');
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
        return $this->singleSheetResponse($request, $rows, $ym . '总部人员工资表', $ym, '总部人员工资表_' . $ym . '.xlsx');
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
        return $this->singleSheetResponse($request, $rows, $project . ' ' . $ym . '管理人员工资表', $ym, $project . '_管理人员工资表_' . $ym . '.xlsx');
    }

    /**
     * 管理人员数据包（仅总部）：管理人员工资表 + 管理人员汇总 两个 Sheet
     * 与"工资全量数据包"里的同名两张表完全同口径，供只需管理人员报表时单独导出
     */
    public function managersBundle(Request $request)
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

        $formula = $this->resolveExportMode($request) === 'formula';
        $book = new Spreadsheet();
        $mgrSheet = $book->getActiveSheet();
        $ordered = collect(\App\Services\PayrollCalculator::orderRows($rows->values()->all()))->values();
        $count = $ordered->count();
        $grossSum = round($ordered->sum(fn ($x) => (float) ($x['gross'] ?? 0)), 2);
        if ($formula) {
            $totalRow = $this->fillSheetFormula($mgrSheet, $ordered, $ym . '管理人员工资表', $ym);
        } else {
            $this->fillSheet($mgrSheet, $ordered, $ym . '管理人员工资表');
            $totalRow = $count + 3;
        }
        $mgrSheet->setTitle('管理人员工资表');

        $sumSheet = $book->createSheet();
        $sumSheet->setTitle('管理人员汇总');
        $summaryRange = $this->fillMgrSummarySheet($sumSheet, $rows, $ym);

        if ($formula) {
            $detail = ['title' => $mgrSheet->getTitle(), 'first' => 3, 'last' => $count + 2,
                'total' => $totalRow, 'count' => $count, 'gross' => $grossSum];
            $this->applyRollupFormulas($sumSheet, $summaryRange, $detail);
            $this->fillNotesSheet($book, $ym, [['title' => $detail['title'], 'total' => $totalRow, 'gross' => $grossSum]], null, null);
        }

        $book->setActiveSheetIndex(0);
        return $this->xlsx($book, '管理人员工资表及汇总_' . $ym . '.xlsx', $formula);
    }

    /** 管理人员汇总 Sheet 内容（全量数据包与管理人数据包共用）：按项目汇总人数/应发/五险一金/个税/实发 */
    private function fillMgrSummarySheet($ms, $mgrSummaryRows, string $ym): array
    {
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
                if ($v !== null && !$this->isZeroNumber($v)) $ms->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $mr, $v);
            }
            $msGross += $gross; $msSoc += $soc; $msTax += $tax; $msBonus += $bonus; $msNet += $net; $msDiff += $diff; $msCnt += $cnt;
            $mr++;
        }
        // 合计行
        $mTotal = ['合计', $msCnt, round($msGross, 2), round($msSoc, 2), round($msTax, 2), round($msBonus, 2), round($msNet, 2), round($msDiff, 2)];
        foreach ($mTotal as $ci => $v) {
            if ($v !== null && !$this->isZeroNumber($v)) $ms->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $mr, $v);
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
        return ['first' => 3, 'last' => $mLast - 1, 'total' => $mLast];
    }

    /**
     * 第 39 列「季度绩效说明」（文本）：季末月输出
     * `2026Q4｜10-12月｜出勤62/63｜系数1.0`；非季末月/非季度绩效法/错误行留空。
     */
    private function quarterPerfNote(array $row): string
    {
        $q = $row['perf_quarter'] ?? null;
        if (!is_array($q) || !empty($q['error'])) return '';
        $months = is_array($q['months'] ?? null) ? $q['months'] : [];
        if (!$months) return '';
        $period = str_replace('-Q', 'Q', (string) ($q['period'] ?? ''));
        $first = (int) substr((string) ($months[0]['ym'] ?? ''), 5, 2);
        $last = (int) substr((string) (end($months)['ym'] ?? ''), 5, 2);
        $range = count($months) === 1 ? "{$first}月" : "{$first}-{$last}月";
        $att = $this->numText((float) ($q['att'] ?? 0));
        $req = $this->numText((float) ($q['req'] ?? 0));
        $coef = $this->numText((float) ($q['coef'] ?? 0), 1);
        return implode('｜', [$period, $range, "出勤{$att}/{$req}", "系数{$coef}"]);
    }

    /** 数字文本：整数不带小数；非整保留最多 2 位（$minDecimals 用于系数保留 1 位） */
    private function numText(float $v, int $minDecimals = 0): string
    {
        $v = round($v, 2);
        if (abs($v - round($v)) < 0.00001) return number_format($v, $minDecimals, '.', '');
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function fillSheet($sheet, $rows, string $title): void
    {
        $sheet->setTitle(preg_replace('#[\[\]:*?/\\\\]#u', '_', mb_substr($title, 0, 31)));
        // 季度绩效不再逐月展开：固定 38 列主表 + 第 39 列「季度绩效说明」（文本，非金额）
        $headers = array_merge(self::COLUMNS, ['季度绩效说明']);
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
        $moneyCols = [8, 9, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37];
        foreach ($rows->values() as $index => $row) {
            $values = [$index + 1, $row['project'] ?? '', $row['department'] ?? '', $row['position'] ?? '', $row['name'] ?? '',
                $row['status'] ?? '', $row['hire_date'] ?? '', $row['fixed'] ?? 0, $row['base'] ?? 0, $row['req_att'] ?? 0, $row['act_att'] ?? 0,
                $row['coef'] ?? 0, $row['base_pay'] ?? 0, $row['perf_pay'] ?? 0, $row['sick_pay'] ?? 0,
                $row['night'] ?? 0, $row['meal'] ?? 0, $row['title_sub'] ?? 0, $row['reward'] ?? 0, $row['backpay'] ?? 0, $row['welfare'] ?? 0,
                $row['punish'] ?? 0, $row['late_d'] ?? 0, $row['miss_d'] ?? 0, $row['other_d'] ?? 0, $row['uniform_d'] ?? 0,
                $row['gross'] ?? 0, $row['pen'] ?? 0, $row['med'] ?? 0, $row['une'] ?? 0, $row['house'] ?? 0, $row['big'] ?? 0,
                $row['soc_total'] ?? 0, $row['spec_total'] ?? 0, $row['actual_tax'] ?? 0, $row['tax_diff'] ?? 0, $row['net'] ?? 0, $row['remark'] ?? '',
                $this->quarterPerfNote($row)];
            // 0 值不写：所有数值 0 一律导出为空白单元格（文本列照常写）
            foreach ($values as $ci => $v) {
                if ($this->isZeroNumber($v)) continue;
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $rowNumber, $v);
            }
            for ($c = 1; $c <= $colCount; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $cellStyle = $sheet->getStyle("{$letter}{$rowNumber}");
                $cellStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                $cellStyle->getFont()->setName('微软雅黑')->setSize(10);
                if (in_array($c, $moneyCols, true)) {
                    $cellStyle->getNumberFormat()->setFormatCode('#,##0.00;-#,##0.00;');
                    $cellStyle->getAlignment()->setHorizontal('right');
                } elseif (in_array($c, [10, 11, 12], true)) {
                    $cellStyle->getAlignment()->setHorizontal('center');
                } elseif ($c === 38 || $c === 39) {
                    $cellStyle->getAlignment()->setHorizontal('left')->setVertical('center'); // 备注/季度绩效说明左对齐
                } else {
                    $cellStyle->getAlignment()->setHorizontal('center')->setVertical('center');
                }
            }
            // 应发合计(27)、实发(37)加粗（插入补发工资列后各后移 1 列）
            $sheet->getStyle(Coordinate::stringFromColumnIndex(27) . $rowNumber)->getFont()->setBold(true);
            $sheet->getStyle(Coordinate::stringFromColumnIndex(37) . $rowNumber)->getFont()->setBold(true);
            $sheet->getRowDimension($rowNumber)->setRowHeight(20);
            $rowNumber++;
        }

        // ===== 合计行 =====
        $totalRow = $rowNumber;
        $sumCols = [13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37];
        $sheet->setCellValue('A' . $totalRow, '合计（' . $rows->count() . '人）');
        $sheet->mergeCells('A' . $totalRow . ':K' . $totalRow);
        $sheet->getStyle('A' . $totalRow)->getAlignment()->setHorizontal('right');
        foreach ($sumCols as $c) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $colSum = 0.0;
            for ($rr = 3; $rr < $totalRow; $rr++) {
                $cellVal = $sheet->getCell($letter . $rr)->getValue();
                if (is_numeric($cellVal)) $colSum += (float) $cellVal;
            }
            if ($this->isZeroNumber(round($colSum, 2))) continue; // 合计为 0 留空
            $sheet->setCellValue($letter . $totalRow, '=SUM(' . $letter . '3:' . $letter . ($totalRow - 1) . ')');
            $sheet->getStyle($letter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00;-#,##0.00;');
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

        // ===== 部门合并单元格（C 列，数据行 3..totalRow-1，居中显示）=====
        // rows 已按 project→department→position→name 排序（orderRows），部门连续可合并
        $deptColLetter = 'C';
        $dataStart = 3;
        $dataEnd = $totalRow - 1;
        if ($dataEnd >= $dataStart) {
            $mergeStart = $dataStart;
            $prevDept = $sheet->getCell($deptColLetter . $dataStart)->getValue();
            for ($r = $dataStart + 1; $r <= $dataEnd + 1; $r++) {
                $curDept = $r <= $dataEnd ? $sheet->getCell($deptColLetter . $r)->getValue() : null;
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

        // ===== 列宽 =====
        // 39 列（含补发工资；备注 16 宽；第 39 列季度绩效说明 34 宽）
        $widths = [6, 14, 12, 14, 10, 8, 12, 10, 10, 8, 8, 8, 11, 11, 10, 12, 9, 10, 10, 10, 10, 11, 10, 10, 10, 12,
            10, 10, 10, 10, 11, 8, 12, 12, 10, 12, 16, 16, 34];
        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($widths[$c - 1] ?? 11);
        }
    }

    /** 带公式版明细页列头（与纯数值版完全一致，共 39 列，不新增扣款合计列） */
    private function formulaLayout(): array
    {
        $headers = ['序号', '项目', '部门', '岗位', '姓名', '人员状态', '入职日期', '固定月薪', '基本工资', '应出勤', '实际出勤',
            '绩效系数', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴', '月度奖励',
            '补发工资', '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款', '应发工资合计',
            '养老保险', '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计', '专项附加扣除', '本月个税',
            '个税补差', '实发工资', '备注', '季度绩效说明'];
        $map = [];
        foreach ($headers as $i => $h) {
            $map[$h] = Coordinate::stringFromColumnIndex($i + 1);
        }
        return [$headers, $map];
    }

    /** 数值 0 判定：仅对 int/float 生效，文本 "0" 不在此列 */
    private function isZeroNumber($v): bool
    {
        return (is_int($v) || is_float($v)) && abs((float) $v) < 0.0000001;
    }

    /** 公式里的数字字面量：去掉无意义的小数 0 */
    private function excelNumber(float $v, int $precision = 6): string
    {
        $s = rtrim(rtrim(number_format($v, $precision, '.', ''), '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    /** 出勤分母（与核算一致）：应出勤>0 用应出勤；否则实际出勤>0 用当月日历天数，否则 1 */
    private function denomFormula(string $reqCell, float $req, float $act, string $ym): string
    {
        if ($req > 0) return $reqCell;
        $days = $ym !== '' ? (int) date('t', strtotime($ym . '-01')) : 0;
        $fallback = $act > 0 ? max(1, $days) : 1.0;
        return "IF({$reqCell}>0,{$reqCell}," . $this->excelNumber($fallback) . ")";
    }

    /** 应发基本工资公式：基本工资 × 实际出勤 ÷ 应出勤；与系统值不一致（分段薪资等）时用核算结果常量 */
    private function basePayFormula(array $col, int $r, array $row, float $denom, string $ym): string
    {
        $base = (float) ($row['base'] ?? 0);
        $req = (float) ($row['req_att'] ?? 0);
        $act = (float) ($row['act_att'] ?? 0);
        $sys = round((float) ($row['base_pay'] ?? 0), 2);
        $calc = round($base * $act / $denom, 2);
        if (abs($calc - $sys) <= 0.004) {
            $div = $this->denomFormula($col['应出勤'] . $r, $req, $act, $ym);
            return "=ROUND({$col['基本工资']}{$r}*{$col['实际出勤']}{$r}/{$div},2)";
        }
        return '=ROUND(' . $this->excelNumber($sys) . ',2)';
    }

    /** 应发绩效工资公式：月度按 (固定月薪-基本工资)×系数×绩效出勤÷应出勤；季度按季度基数×出勤率×季度系数 */
    private function perfPayFormula(array $col, int $r, array $row, float $denom, string $ym): string
    {
        $fixed = (float) ($row['fixed'] ?? 0);
        $base = (float) ($row['base'] ?? 0);
        $coef = (float) ($row['coef'] ?? 0);
        $req = (float) ($row['req_att'] ?? 0);
        $act = (float) ($row['act_att'] ?? 0);
        $perfAtt = array_key_exists('perf_att', $row) ? (float) $row['perf_att'] : $act;
        $sys = round((float) ($row['perf_pay'] ?? 0), 2);

        $q = $row['perf_quarter'] ?? null;
        if (is_array($q) && empty($q['error']) && isset($q['base'])) {
            $qBase = (float) $q['base'];
            $qAtt = (float) ($q['att'] ?? 0);
            $qReq = (float) ($q['req'] ?? 0);
            $qCoef = (float) ($q['coef'] ?? 0);
            $calc = $qReq > 0
                ? round($qBase * $qAtt / $qReq * $qCoef, 2)
                : round($qBase * (float) ($q['ratio'] ?? 0) * $qCoef, 2);
            if (abs($calc - $sys) <= 0.004) {
                $attPart = $qReq > 0
                    ? $this->excelNumber($qAtt) . '/' . $this->excelNumber($qReq)
                    : $this->excelNumber((float) ($q['ratio'] ?? 0));
                return '=ROUND(' . $this->excelNumber($qBase) . '*' . $attPart . '*' . $this->excelNumber($qCoef) . ',2)';
            }
            return '=ROUND(' . $this->excelNumber($sys) . ',2)';
        }

        $attExpr = abs($perfAtt - $act) <= 0.004 ? $col['实际出勤'] . $r : $this->excelNumber($perfAtt);
        $calc = round(($fixed - $base) * $coef * $perfAtt / $denom, 2);
        if (abs($calc - $sys) <= 0.004) {
            $div = $this->denomFormula($col['应出勤'] . $r, $req, $act, $ym);
            return "=ROUND(({$col['固定月薪']}{$r}-{$col['基本工资']}{$r})*{$col['绩效系数']}{$r}*{$attExpr}/{$div},2)";
        }
        return '=ROUND(' . $this->excelNumber($sys) . ',2)';
    }

    /** 病假工资公式：病假天数 × 病假基数 × 系数A × 系数B ÷ 应出勤（系数取系统设置） */
    private function sickPayFormula(array $col, int $r, array $row, float $denom, string $ym, CalcRules $rules): string
    {
        $base = (float) ($row['base'] ?? 0);
        $fixed = (float) ($row['fixed'] ?? 0);
        $req = (float) ($row['req_att'] ?? 0);
        $act = (float) ($row['act_att'] ?? 0);
        $days = (float) ($row['sick_days'] ?? 0);
        $sys = round((float) ($row['sick_pay'] ?? 0), 2);
        $factorA = $rules->num('sick_pay.params.factor_a', 0.7);
        $factorB = $rules->num('sick_pay.params.factor_b', 0.6);
        $byFixed = $rules->str('sick_pay.params.sick_base', 'base') === 'fixed';
        $baseVal = $byFixed ? $fixed : $base;
        $calc = round($days * $baseVal * $factorA * $factorB / $denom, 2);
        if ($days > 0 && abs($calc - $sys) <= 0.004) {
            $div = $this->denomFormula($col['应出勤'] . $r, $req, $act, $ym);
            $baseCell = ($byFixed ? $col['固定月薪'] : $col['基本工资']) . $r;
            return '=ROUND(' . $this->excelNumber($days) . '*' . $baseCell . '*'
                . $this->excelNumber($factorA) . '*' . $this->excelNumber($factorB) . '/' . $div . ',2)';
        }
        return '=ROUND(' . $this->excelNumber($sys) . ',2)';
    }

    /** 写入公式并同时写入缓存值（writeCellFormula 会计算并输出 <v>） */
    private function putFormula($sheet, string $coord, string $formula, $cached = null): void
    {
        $cell = $sheet->getCell($coord);
        $cell->setValue($formula);
        if ($cached !== null) $cell->setCalculatedValue($cached);
    }

    /** 跨表引用页签：统一加单引号并转义内部单引号 */
    private function sheetRef(string $title): string
    {
        return "'" . str_replace("'", "''", $title) . "'";
    }

    /**
     * 带公式版明细页：40 列；四条派生列写公式；合计行 ROUND(SUM)；底部校验区。
     * @return int 合计行行号
     */
    private function fillSheetFormula($sheet, $rows, string $title, string $ym = ''): int
    {
        [$headers, $col] = $this->formulaLayout();
        $colCount = count($headers);
        $lastLetter = Coordinate::stringFromColumnIndex($colCount);
        $sheet->setTitle(preg_replace('#[\[\]:*?/\\\\]#u', '_', mb_substr($title, 0, 31)));

        // ===== 第1行：大标题 =====
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(28);

        // ===== 第2行：表头（分区配色）=====
        foreach ($headers as $ci => $h) {
            $letter = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue("{$letter}2", $h);
            $style = $sheet->getStyle("{$letter}2");
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->columnBand($ci + 1));
            $style->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
            $style->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(34);
        $sheet->freezePane('F3');

        $moneyHeaders = ['固定月薪', '基本工资', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴',
            '月度奖励', '补发工资', '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款',
            '应发工资合计', '养老保险', '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计',
            '专项附加扣除', '本月个税', '个税补差', '实发工资'];
        $moneySet = array_flip($moneyHeaders);
        // 合计行只汇总「应发基本工资」及其后的金额列，与纯数值版一致
        $sumHeaders = array_slice($moneyHeaders, 2);
        $textHeaders = ['项目', '部门', '岗位', '姓名', '人员状态', '入职日期', '备注', '季度绩效说明'];
        $sums = array_fill_keys($moneyHeaders, 0.0);
        $rules = app(CalcRules::class);
        $daysInMonth = $ym !== '' ? (int) date('t', strtotime($ym . '-01')) : 0;

        // ===== 数据行 =====
        $rowNumber = 3;
        foreach ($rows->values() as $index => $row) {
            $values = [
                '序号' => $index + 1,
                '项目' => (string) ($row['project'] ?? ''),
                '部门' => (string) ($row['department'] ?? ''),
                '岗位' => (string) ($row['position'] ?? ''),
                '姓名' => (string) ($row['name'] ?? ''),
                '人员状态' => (string) ($row['status'] ?? ''),
                '入职日期' => (string) ($row['hire_date'] ?? ''),
                '固定月薪' => (float) ($row['fixed'] ?? 0),
                '基本工资' => (float) ($row['base'] ?? 0),
                '应出勤' => (float) ($row['req_att'] ?? 0),
                '实际出勤' => (float) ($row['act_att'] ?? 0),
                '绩效系数' => (float) ($row['coef'] ?? 0),
                '应发基本工资' => (float) ($row['base_pay'] ?? 0),
                '应发绩效工资' => (float) ($row['perf_pay'] ?? 0),
                '病假工资' => (float) ($row['sick_pay'] ?? 0),
                '夜班/话费补贴' => (float) ($row['night'] ?? 0),
                '餐补' => (float) ($row['meal'] ?? 0),
                '其他补贴' => (float) ($row['title_sub'] ?? 0),
                '月度奖励' => (float) ($row['reward'] ?? 0),
                '补发工资' => (float) ($row['backpay'] ?? 0),
                '已发福利' => (float) ($row['welfare'] ?? 0),
                '月度扣罚' => (float) ($row['punish'] ?? 0),
                '迟到早退扣款' => (float) ($row['late_d'] ?? 0),
                '缺卡扣款' => (float) ($row['miss_d'] ?? 0),
                '其他扣款' => (float) ($row['other_d'] ?? 0),
                '工装扣款' => (float) ($row['uniform_d'] ?? 0),
                '应发工资合计' => round((float) ($row['gross'] ?? 0), 2),
                '养老保险' => (float) ($row['pen'] ?? 0),
                '医疗保险' => (float) ($row['med'] ?? 0),
                '失业保险' => (float) ($row['une'] ?? 0),
                '住房公积金' => (float) ($row['house'] ?? 0),
                '大病' => (float) ($row['big'] ?? 0),
                '五险一金合计' => round((float) ($row['soc_total'] ?? 0), 2),
                '专项附加扣除' => (float) ($row['spec_total'] ?? 0),
                '本月个税' => (float) ($row['actual_tax'] ?? 0),
                '个税补差' => (float) ($row['tax_diff'] ?? 0),
                '实发工资' => round((float) ($row['net'] ?? 0), 2),
                '备注' => (string) ($row['remark'] ?? ''),
                '季度绩效说明' => $this->quarterPerfNote($row),
            ];

            $reqAtt = (float) ($row['req_att'] ?? 0);
            $actAtt = (float) ($row['act_att'] ?? 0);
            $denom = $reqAtt > 0 ? $reqAtt : ($actAtt > 0 ? max(1, $daysInMonth) : 1.0);

            foreach ($headers as $h) {
                $coord = $col[$h] . $rowNumber;
                $v = $values[$h];
                if (isset($sums[$h])) $sums[$h] += (float) $v;
                $isText = in_array($h, $textHeaders, true);
                // 0 值不写：所有数值 0 一律导出为空白单元格（含公式列结果为 0 的情况）
                if (!$isText && $this->isZeroNumber($v)) continue;
                if ($h === '应发基本工资') {
                    $this->putFormula($sheet, $coord,
                        $this->basePayFormula($col, $rowNumber, $row, $denom, $ym), $v);
                } elseif ($h === '应发绩效工资') {
                    $this->putFormula($sheet, $coord,
                        $this->perfPayFormula($col, $rowNumber, $row, $denom, $ym), $v);
                } elseif ($h === '病假工资') {
                    $this->putFormula($sheet, $coord,
                        $this->sickPayFormula($col, $rowNumber, $row, $denom, $ym, $rules), $v);
                } elseif ($h === '应发工资合计') {
                    // 扣款合计列已删除：扣款项直接在公式里内联求和
                    $this->putFormula($sheet, $coord,
                        "=ROUND(SUM({$col['应发基本工资']}{$rowNumber}:{$col['已发福利']}{$rowNumber})"
                        . "-SUM({$col['月度扣罚']}{$rowNumber}:{$col['工装扣款']}{$rowNumber}),2)", $v);
                } elseif ($h === '五险一金合计') {
                    $this->putFormula($sheet, $coord,
                        "=ROUND(SUM({$col['养老保险']}{$rowNumber}:{$col['大病']}{$rowNumber}),2)", $v);
                } elseif ($h === '实发工资') {
                    $this->putFormula($sheet, $coord,
                        "=ROUND({$col['应发工资合计']}{$rowNumber}-{$col['五险一金合计']}{$rowNumber}"
                        . "-{$col['本月个税']}{$rowNumber}-{$col['已发福利']}{$rowNumber}+{$col['个税补差']}{$rowNumber},2)", $v);
                } elseif ($isText) {
                    // 显式文本：避免 = + - @ 开头的内容被 Excel 当公式执行
                    $sheet->setCellValueExplicit($coord, $v, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coord, $v);
                }
            }

            for ($c = 1; $c <= $colCount; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $h = $headers[$c - 1];
                $cellStyle = $sheet->getStyle("{$letter}{$rowNumber}");
                $cellStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                $cellStyle->getFont()->setName('微软雅黑')->setSize(10);
                if (isset($moneySet[$h])) {
                    $cellStyle->getNumberFormat()->setFormatCode('#,##0.00;-#,##0.00;');
                    $cellStyle->getAlignment()->setHorizontal('right');
                } elseif (in_array($h, ['应出勤', '实际出勤', '绩效系数'], true)) {
                    $cellStyle->getAlignment()->setHorizontal('center');
                } elseif (in_array($h, ['备注', '季度绩效说明'], true)) {
                    $cellStyle->getAlignment()->setHorizontal('left')->setVertical('center');
                } else {
                    $cellStyle->getAlignment()->setHorizontal('center')->setVertical('center');
                }
            }
            $sheet->getStyle($col['应发工资合计'] . $rowNumber)->getFont()->setBold(true);
            $sheet->getStyle($col['实发工资'] . $rowNumber)->getFont()->setBold(true);
            $sheet->getRowDimension($rowNumber)->setRowHeight(20);
            $rowNumber++;
        }

        // ===== 合计行：全部金额列 ROUND(SUM(...),2) + 缓存值 =====
        $totalRow = $rowNumber;
        $sheet->setCellValue('A' . $totalRow, '合计（' . $rows->count() . '人）');
        $sheet->mergeCells('A' . $totalRow . ':K' . $totalRow);
        $sheet->getStyle('A' . $totalRow)->getAlignment()->setHorizontal('right');
        foreach ($sumHeaders as $h) {
            $letter = $col[$h];
            $sum = round($sums[$h], 2);
            if ($this->isZeroNumber($sum)) continue; // 合计为 0 同样留空
            $this->putFormula($sheet, $letter . $totalRow,
                "=ROUND(SUM({$letter}3:{$letter}" . ($totalRow - 1) . "),2)", $sum);
            $sheet->getStyle($letter . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00;-#,##0.00;');
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

        // ===== 部门合并（C 列）=====
        $dataStart = 3;
        $dataEnd = $totalRow - 1;
        if ($dataEnd >= $dataStart) {
            $mergeStart = $dataStart;
            $prevDept = $sheet->getCell('C' . $dataStart)->getValue();
            for ($r = $dataStart + 1; $r <= $dataEnd + 1; $r++) {
                $curDept = $r <= $dataEnd ? $sheet->getCell('C' . $r)->getValue() : null;
                if ($curDept !== $prevDept) {
                    if ($r - 1 > $mergeStart) {
                        $sheet->mergeCells('C' . $mergeStart . ':C' . ($r - 1));
                    }
                    $sheet->getStyle('C' . $mergeStart . ':C' . ($r - 1))
                        ->getAlignment()->setHorizontal('center')->setVertical('center');
                    $mergeStart = $r;
                    $prevDept = $curDept;
                }
            }
        }

        // ===== 列宽（与纯数值版 39 列一致）=====
        $widths = [6, 14, 12, 14, 10, 8, 12, 10, 10, 8, 8, 8, 11, 11, 10, 12, 9, 10, 10, 10, 10, 11, 10, 10, 10, 12,
            10, 10, 10, 10, 11, 8, 12, 12, 10, 12, 16, 16, 34];
        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($widths[$c - 1] ?? 11);
        }

        // ===== 校验区 =====
        $this->fillCheckArea($sheet, $col, $totalRow, $sums, $rows);

        return $totalRow;
    }

    /** 明细页底部校验区：系统值 / 公式值 / 差异；差异非 0 红底加粗 */
    private function fillCheckArea($sheet, array $col, int $totalRow, array $sums, $rows = null): void
    {
        $start = $totalRow + 2;
        $sheet->setCellValue("A{$start}", '校验区（系统值 vs 公式值）');
        $sheet->mergeCells("A{$start}:E{$start}");
        $sheet->getStyle("A{$start}")->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle("A{$start}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $sheet->getStyle("A{$start}")->getAlignment()->setHorizontal('left')->setVertical('center');

        $headRow = $start + 1;
        foreach (['校验项', '系统值', '公式值', '差异', '结论'] as $i => $v) {
            $coord = Coordinate::stringFromColumnIndex($i + 1) . $headRow;
            $sheet->setCellValue($coord, $v);
            $st = $sheet->getStyle($coord);
            $st->getFont()->setBold(true)->setSize(10);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $st->getAlignment()->setHorizontal('center')->setVertical('center');
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }

        $r = $headRow + 1;
        $firstDiff = $r;
        foreach (['应发工资合计', '五险一金合计', '实发工资', '应发基本工资合计', '应发绩效工资合计', '病假工资合计'] as $name) {
            $sumKey = str_ends_with($name, '合计') && !isset($sums[$name]) ? mb_substr($name, 0, -2) : $name;
            $systemTotal = round((float) ($sums[$sumKey] ?? 0), 2);
            $sheet->setCellValue("A{$r}", $name);
            $sheet->setCellValue("B{$r}", $systemTotal);
            $this->putFormula($sheet, "C{$r}", "={$col[$sumKey]}{$totalRow}", $systemTotal);
            $this->putFormula($sheet, "D{$r}", "=ROUND(B{$r}-C{$r},2)", 0);
            $this->putFormula($sheet, "E{$r}", "=IF(D{$r}=0,\"一致\",\"不一致\")", '一致');
            $sheet->getStyle("B{$r}:D{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal('center');
            foreach (['A', 'B', 'C', 'D', 'E'] as $cc) {
                $st = $sheet->getStyle("{$cc}{$r}");
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                $st->getFont()->setName('微软雅黑')->setSize(10);
            }
            $r++;
        }
        $lastDiff = $r - 1;

        // 差异不为 0：红底 + 红字加粗
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_NOTEQUAL)
            ->addCondition('0');
        $style = new Style();
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
        $style->getFont()->setBold(true)->getColor()->setRGB('9C0006');
        $conditional->setStyle($style);
        $sheet->getStyle("D{$firstDiff}:D{$lastDiff}")->setConditionalStyles([$conditional]);

        // ===== 逐行校验（不只比合计）：系统值 - 本表公式值 =====
        if ($rows === null || count($rows) === 0) return;
        $rowStart = $lastDiff + 3;
        $sheet->setCellValue("A{$rowStart}", '逐行校验（系统值 - 本表公式值，逐人逐列）');
        $sheet->mergeCells("A{$rowStart}:I{$rowStart}");
        $sheet->getStyle("A{$rowStart}")->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle("A{$rowStart}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');

        $rowHead = $rowStart + 1;
        $heads = ['序号', '姓名', '应发基本工资差异', '应发绩效工资差异', '病假工资差异',
            '应发合计差异', '五险合计差异', '实发差异', '结论'];
        foreach ($heads as $i => $v) {
            $coord = Coordinate::stringFromColumnIndex($i + 1) . $rowHead;
            $sheet->setCellValue($coord, $v);
            $st = $sheet->getStyle($coord);
            $st->getFont()->setBold(true)->setSize(10);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
            $st->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }

        $map = [
            'C' => ['base_pay', $col['应发基本工资']],
            'D' => ['perf_pay', $col['应发绩效工资']],
            'E' => ['sick_pay', $col['病假工资']],
            'F' => ['gross', $col['应发工资合计']],
            'G' => ['soc_total', $col['五险一金合计']],
            'H' => ['net', $col['实发工资']],
        ];
        $r = $rowHead + 1;
        $firstRowCheck = $r;
        $i = 0;
        foreach ($rows as $row) {
            $detailRow = 3 + $i;
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", (string) ($row['name'] ?? ''), DataType::TYPE_STRING);
            foreach ($map as $target => [$field, $letter]) {
                $system = round((float) ($row[$field] ?? 0), 2);
                $this->putFormula($sheet, "{$target}{$r}",
                    "=ROUND({$system}-{$letter}{$detailRow},2)", 0);
                $sheet->getStyle("{$target}{$r}")->getNumberFormat()->setFormatCode('#,##0.00;-#,##0.00;');
            }
            $this->putFormula($sheet, "I{$r}",
                "=IF(AND(C{$r}=0,D{$r}=0,E{$r}=0,F{$r}=0,G{$r}=0,H{$r}=0),\"一致\",\"不一致\")", '一致');
            $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal('center');
            foreach (range('A', 'I') as $cc) {
                $st = $sheet->getStyle("{$cc}{$r}");
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                $st->getFont()->setName('微软雅黑')->setSize(10);
            }
            $i++;
            $r++;
        }
        $lastRowCheck = $r - 1;
        $rowConditional = new Conditional();
        $rowConditional->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_NOTEQUAL)
            ->addCondition('0');
        $rowStyle = new Style();
        $rowStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
        $rowStyle->getFont()->setBold(true)->getColor()->setRGB('9C0006');
        $rowConditional->setStyle($rowStyle);
        $sheet->getStyle("C{$firstRowCheck}:H{$lastRowCheck}")->setConditionalStyles([$rowConditional]);
    }

    /** 用公式替换已写入的系统值，并把原值作为缓存值保留 */
    private function putFormulaFromCell($sheet, string $coord, string $formula): void
    {
        $cell = $sheet->getCell($coord);
        $cached = $cell->getValue();
        $cell->setValue($formula);
        if ($cached !== null && $cached !== '') $cell->setCalculatedValue($cached);
    }

    /** 主「汇总」页：把金额/人数改为跨表引用明细页合计行 */
    private function applyMainSummaryFormulas($sheet, array $ctx): void
    {
        [, $col] = $this->formulaLayout();
        $nameCol = $col['姓名'];
        $grossCol = $col['应发工资合计'];
        $mgr = $ctx['mgrDetail'];
        $mgrRef = $mgr ? $this->sheetRef($mgr['title']) : null;
        $mgrRange = $mgr ? "B3:B{$mgr['last']}" : null;
        $mgrGrossRange = $mgr ? "{$grossCol}3:{$grossCol}{$mgr['last']}" : null;

        foreach ($ctx['projRows'] as $p => $r) {
            $d = $ctx['projectDetails'][$p] ?? null;
            if ($d) {
                $ref = $this->sheetRef($d['title']);
                $this->putFormulaFromCell($sheet, "B{$r}", "=COUNTA({$ref}!{$nameCol}{$d['first']}:{$nameCol}{$d['last']})");
                $this->putFormulaFromCell($sheet, "C{$r}", "={$ref}!{$grossCol}{$d['total']}");
            } else {
                $this->putFormulaFromCell($sheet, "B{$r}", '=0');
                $this->putFormulaFromCell($sheet, "C{$r}", '=0');
            }
            if ($mgr) {
                $this->putFormulaFromCell($sheet, "D{$r}", "=COUNTIF({$mgrRef}!{$mgrRange},A{$r})");
                $this->putFormulaFromCell($sheet, "E{$r}", "=ROUND(SUMIF({$mgrRef}!{$mgrRange},A{$r},{$mgrRef}!{$mgrGrossRange}),2)");
            } else {
                $this->putFormulaFromCell($sheet, "D{$r}", '=0');
                $this->putFormulaFromCell($sheet, "E{$r}", '=0');
            }
            $this->putFormulaFromCell($sheet, "F{$r}", "=B{$r}+D{$r}");
            $this->putFormulaFromCell($sheet, "G{$r}", "=ROUND(C{$r}+E{$r},2)");
            $this->putFormulaFromCell($sheet, "I{$r}", "=IF(H{$r}>0,G{$r}/H{$r},\"\")");
            $this->putFormulaFromCell($sheet, "L{$r}", "=IF(K{$r}>0,G{$r}-K{$r},\"\")");
            $this->putFormulaFromCell($sheet, "M{$r}", "=IF(K{$r}>0,L{$r}/K{$r},\"\")");
        }

        // 基层合计行
        $br = $ctx['baseTotal'];
        $this->putFormulaFromCell($sheet, "F{$br}", "=SUM(B{$ctx['firstProj']}:B{$ctx['lastProj']})");
        $this->putFormulaFromCell($sheet, "G{$br}", "=ROUND(SUM(C{$ctx['firstProj']}:C{$ctx['lastProj']}),2)");
        $this->putFormulaFromCell($sheet, "I{$br}", "=IF(H{$br}>0,G{$br}/H{$br},\"\")");
        $this->putFormulaFromCell($sheet, "L{$br}", "=IF(K{$br}>0,G{$br}-K{$br},\"\")");
        $this->putFormulaFromCell($sheet, "M{$br}", "=IF(K{$br}>0,L{$br}/K{$br},\"\")");

        // 管理人员合计行
        if ($ctx['mgrTotal']) {
            $mr = $ctx['mgrTotal'];
            if ($mgr) {
                $this->putFormulaFromCell($sheet, "F{$mr}", "=COUNTA({$mgrRef}!{$nameCol}3:{$nameCol}{$mgr['last']})");
                $this->putFormulaFromCell($sheet, "G{$mr}", "={$mgrRef}!{$grossCol}{$mgr['total']}");
            }
            $this->putFormulaFromCell($sheet, "I{$mr}", "=IF(H{$mr}>0,G{$mr}/H{$mr},\"\")");
            $this->putFormulaFromCell($sheet, "L{$mr}", "=IF(K{$mr}>0,G{$mr}-K{$mr},\"\")");
            $this->putFormulaFromCell($sheet, "M{$mr}", "=IF(K{$mr}>0,L{$mr}/K{$mr},\"\")");
        }

        // 案场人员 / 物业总部合计行
        foreach ([['row' => $ctx['caseTotal'], 'detail' => $ctx['caseDetail'] ?? null],
                  ['row' => $ctx['hqTotal'], 'detail' => $ctx['hqDetail'] ?? null]] as $item) {
            if (!$item['row'] || !$item['detail']) continue;
            $rr = $item['row'];
            $ref = $this->sheetRef($item['detail']['title']);
            $this->putFormulaFromCell($sheet, "F{$rr}", "=COUNTA({$ref}!{$nameCol}3:{$nameCol}{$item['detail']['last']})");
            $this->putFormulaFromCell($sheet, "G{$rr}", "={$ref}!{$grossCol}{$item['detail']['total']}");
            $this->putFormulaFromCell($sheet, "I{$rr}", "=IF(H{$rr}>0,G{$rr}/H{$rr},\"\")");
            $this->putFormulaFromCell($sheet, "L{$rr}", "=IF(K{$rr}>0,G{$rr}-K{$rr},\"\")");
            $this->putFormulaFromCell($sheet, "M{$rr}", "=IF(K{$rr}>0,L{$rr}/K{$rr},\"\")");
        }

        // 总计行
        $gr = $ctx['grand'];
        $this->putFormulaFromCell($sheet, "B{$gr}", "=F{$br}");
        $this->putFormulaFromCell($sheet, "C{$gr}", "=G{$br}");
        $this->putFormulaFromCell($sheet, "D{$gr}", $ctx['mgrTotal'] ? "=F{$ctx['mgrTotal']}" : '=0');
        $this->putFormulaFromCell($sheet, "E{$gr}", $ctx['mgrTotal'] ? "=G{$ctx['mgrTotal']}" : '=0');
        $partsF = ["F{$br}"]; $partsG = ["G{$br}"];
        if ($ctx['mgrTotal']) { $partsF[] = "F{$ctx['mgrTotal']}"; $partsG[] = "G{$ctx['mgrTotal']}"; }
        $partsF[] = "F{$ctx['caseTotal']}"; $partsG[] = "G{$ctx['caseTotal']}";
        if ($ctx['hqTotal']) { $partsF[] = "F{$ctx['hqTotal']}"; $partsG[] = "G{$ctx['hqTotal']}"; }
        $this->putFormulaFromCell($sheet, "F{$gr}", '=' . implode('+', $partsF));
        $this->putFormulaFromCell($sheet, "G{$gr}", '=ROUND(' . implode('+', $partsG) . ',2)');
        $this->putFormulaFromCell($sheet, "I{$gr}", "=IF(H{$gr}>0,G{$gr}/H{$gr},\"\")");
        $this->putFormulaFromCell($sheet, "L{$gr}", "=IF(K{$gr}>0,G{$gr}-K{$gr},\"\")");
        $this->putFormulaFromCell($sheet, "M{$gr}", "=IF(K{$gr}>0,L{$gr}/K{$gr},\"\")");
    }

    /** 辅助汇总页（管理人员汇总/案场人员汇总/总部人员汇总）：逐项目 SUMIF + 合计行引用明细合计行 */
    private function applyRollupFormulas($sheet, array $range, array $detail): void
    {
        [, $col] = $this->formulaLayout();
        $ref = $this->sheetRef($detail['title']);
        $nameCol = $col['姓名'];
        $rngB = "B3:B{$detail['last']}";
        $sumIf = fn (string $letter, string $crit) =>
            "SUMIF({$ref}!{$rngB},{$crit},{$ref}!{$letter}3:{$letter}{$detail['last']})";

        for ($r = $range['first']; $r <= $range['last']; $r++) {
            $crit = "A{$r}";
            $this->putFormulaFromCell($sheet, "B{$r}", "=COUNTIF({$ref}!{$rngB},{$crit})");
            foreach (['C' => $col['应发工资合计'], 'D' => $col['五险一金合计'], 'E' => $col['本月个税'],
                       'G' => $col['实发工资'], 'H' => $col['个税补差']] as $target => $letter) {
                $this->putFormulaFromCell($sheet, "{$target}{$r}", '=ROUND(' . $sumIf($letter, $crit) . ',2)');
            }
            $this->putFormulaFromCell($sheet, "F{$r}",
                '=ROUND(' . $sumIf($col['月度奖励'], $crit) . '+' . $sumIf($col['已发福利'], $crit) . ',2)');
        }

        $t = $range['total'];
        $this->putFormulaFromCell($sheet, "B{$t}", "=COUNTA({$ref}!{$nameCol}3:{$nameCol}{$detail['last']})");
        $this->putFormulaFromCell($sheet, "C{$t}", "={$ref}!{$col['应发工资合计']}{$detail['total']}");
        $this->putFormulaFromCell($sheet, "D{$t}", "={$ref}!{$col['五险一金合计']}{$detail['total']}");
        $this->putFormulaFromCell($sheet, "E{$t}", "={$ref}!{$col['本月个税']}{$detail['total']}");
        $this->putFormulaFromCell($sheet, "F{$t}",
            "=ROUND({$ref}!{$col['月度奖励']}{$detail['total']}+{$ref}!{$col['已发福利']}{$detail['total']},2)");
        $this->putFormulaFromCell($sheet, "G{$t}", "={$ref}!{$col['实发工资']}{$detail['total']}");
        $this->putFormulaFromCell($sheet, "H{$t}", "={$ref}!{$col['个税补差']}{$detail['total']}");
    }

    /** 说明页（仅公式版，最后一张）：公式列/快照列/校验区用法 + 全表校验 */
    private function fillNotesSheet(Spreadsheet $book, string $ym, array $details, ?int $summaryGrandRow, ?float $summaryGross): void
    {
        [, $col] = $this->formulaLayout();
        $sheet = $book->createSheet();
        $sheet->setTitle('说明');
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', $ym . ' 工资表导出说明（带公式版）');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(15);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $lines = [
            ['除本说明页外，带公式版与纯数值版的列结构完全一致（均为 39 列，列名、顺序、位置逐列相同）。', false],
            ['', false],
            ['一、公式列（修改明细后会自动重算）', true],
            ['应发基本工资 = 基本工资 × 实际出勤 ÷ 应出勤（应出勤为 0 时按核算口径取当月日历天数或 1）。', false],
            ['应发绩效工资 = (固定月薪 − 基本工资) × 绩效系数 × 绩效出勤 ÷ 应出勤；季度绩效人员按「季度基数 × 季度出勤率 × 季度系数」核算结果写入公式。', false],
            ['病假工资 = 病假天数 × 病假基数 × 病假系数A × 病假系数B ÷ 应出勤；系数取系统设置（默认 0.7 / 0.6）。', false],
            ['应发工资合计 = 应发基本工资 + 应发绩效工资 + 病假工资 + 夜班/话费补贴 + 餐补 + 其他补贴 + 月度奖励 + 补发工资 + 已发福利 - (月度扣罚 + 迟到早退扣款 + 缺卡扣款 + 其他扣款 + 工装扣款)', false],
            ['五险一金合计 = 养老保险 + 医疗保险 + 失业保险 + 住房公积金 + 大病', false],
            ['实发工资 = 应发工资合计 - 五险一金合计 - 本月个税 - 已发福利 + 个税补差', false],
            ['明细页合计行 = ROUND(SUM(列首:列尾),2)；汇总页各项引用明细页合计行。', false],
            ['个别存在月中薪资调整/分段核算、或采用季度累计口径的人员，其非本表列可推导的核算输入（分段基数、绩效出勤、季度累计等）以核算结果常量写入公式；校验收敛以「系统值 vs 公式值」差异为 0 为准。', false],
            ['', false],
            ['二、系统核算快照值（保留系统结果，不写公式）', true],
            ['固定月薪、基本工资、应出勤、实际出勤、绩效系数、夜班/话费补贴、餐补、其他补贴、月度奖励、补发工资、已发福利、月度扣罚、迟到早退扣款、缺卡扣款、其他扣款、工装扣款、养老保险、医疗保险、失业保险、住房公积金、大病、专项附加扣除、本月个税、个税补差。', false],
            ['其中：本月个税直接采用系统个税结果，不另设税率表。若系统配置了自定义应发/实发公式，以校验区差异为准，并以系统值为准。', false],
            ['', false],
            ['三、0 值显示与表头配色', true],
            ['所有数值单元格（含绩效系数、应出勤等）核算结果为 0 时，一律导出为空白单元格，不写 0；合计、公式与校验区计算不受影响（空白按 0 参与求和）。', false],
            ['第二行表头按分区着色：奖励/补贴类（应发基本工资至已发福利）为浅绿，扣罚/扣款类（月度扣罚至工装扣款）为浅红，便于区分；应发工资合计仍为浅绿。', false],
            ['', false],
            ['四、校验区怎么用', true],
            ['每张明细页合计行下方有「系统值 / 公式值 / 差异」校验区：系统值来自系统核算结果，公式值引用该页合计行；差异 = ROUND(系统值 - 公式值,2)。差异为 0 表示一致；不为 0 时差异单元格红底加粗，说明系统口径与公式口径存在差异，请以系统值为准并反馈。', false],
            ['校验区下方还有「逐行校验」：按人逐列比较系统值与本表公式值，差异列红底；合计一致但逐行有差异时也会暴露，不再只看总数。', false],
            ['常量列说明：应发基本工资、应发绩效工资、病假工资在月中调薪分段、季度累计绩效、试用段等场景，无法仅用本表列复现，会以 =ROUND(系统值,2) 的常量公式写入，这三个单元格不保证随本表输入变化重算；其余金额列均由本表列公式计算。', false],
            ['', false],
        ];
        $r = 3;
        foreach ($lines as [$text, $bold]) {
            $sheet->setCellValue("A{$r}", $text);
            $sheet->mergeCells("A{$r}:F{$r}");
            $st = $sheet->getStyle("A{$r}");
            $st->getAlignment()->setWrapText(true)->setVertical('center')->setHorizontal('left');
            if ($bold) $st->getFont()->setBold(true)->setSize(11);
            else $st->getFont()->setSize(10);
            $sheet->getRowDimension($r)->setRowHeight($bold ? 22 : 34);
            $r++;
        }

        if ($summaryGrandRow !== null && $details) {
            $refs = [];
            foreach ($details as $d) {
                $refs[] = $this->sheetRef($d['title']) . '!' . $col['应发工资合计'] . $d['total'];
            }
            $r++;
            $sheet->setCellValue("A{$r}", '五、全表校验（明细页合计之和 vs 汇总页总计）');
            $sheet->mergeCells("A{$r}:F{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11);
            $sheet->getRowDimension($r)->setRowHeight(22);
            $r++;
            foreach (['校验项', '金额', '说明'] as $i => $h) {
                $coord = Coordinate::stringFromColumnIndex($i + 1) . $r;
                $sheet->setCellValue($coord, $h);
                $st = $sheet->getStyle($coord);
                $st->getFont()->setBold(true)->setSize(10);
                $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
                $st->getAlignment()->setHorizontal('center');
                $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
            }
            $r++;

            $sumRow = $r;
            $sheet->setCellValue("A{$sumRow}", '明细页应发合计之和');
            $this->putFormula($sheet, "B{$sumRow}", '=ROUND(SUM(' . implode(',', $refs) . '),2)',
                round(array_sum(array_column($details, 'gross')), 2));
            $sheet->setCellValue("C{$sumRow}", '各明细页「应发工资合计」列合计行之和');
            $r++;

            $grandRow = $r;
            $sheet->setCellValue("A{$grandRow}", '汇总页总计应发');
            $this->putFormula($sheet, "B{$grandRow}", "='汇总'!G{$summaryGrandRow}", $summaryGross);
            $sheet->setCellValue("C{$grandRow}", '汇总页总计行「应发合计」');
            $r++;

            $diffRow = $r;
            $sheet->setCellValue("A{$diffRow}", '差异');
            $this->putFormula($sheet, "B{$diffRow}", "=ROUND(B{$sumRow}-B{$grandRow},2)", 0);
            $sheet->setCellValue("C{$diffRow}", '应为 0');
            $r++;

            $conclRow = $r;
            $sheet->setCellValue("A{$conclRow}", '结论');
            $this->putFormula($sheet, "B{$conclRow}", "=IF(B{$diffRow}=0,\"一致\",\"不一致\")", '一致');
            $sheet->setCellValue("C{$conclRow}", '差异为 0 表示明细与汇总一致');
            $r++;

            foreach ([$sumRow, $grandRow, $diffRow] as $rr) {
                $sheet->getStyle("B{$rr}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            foreach (range($sumRow, $conclRow) as $rr) {
                foreach (['A', 'B', 'C'] as $cc) {
                    $st = $sheet->getStyle("{$cc}{$rr}");
                    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
                    $st->getFont()->setName('微软雅黑')->setSize(10);
                }
                $sheet->getRowDimension($rr)->setRowHeight(20);
            }

            $conditional = new Conditional();
            $conditional->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_NOTEQUAL)
                ->addCondition('0');
            $style = new Style();
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
            $style->getFont()->setBold(true)->getColor()->setRGB('9C0006');
            $conditional->setStyle($style);
            $sheet->getStyle("B{$diffRow}")->setConditionalStyles([$conditional]);
        } else {
            $sheet->setCellValue("A{$r}", '本文件为单项目导出，不含汇总页；全表校验见各明细页底部校验区。');
            $sheet->mergeCells("A{$r}:F{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setSize(10);
        }

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(56);
        foreach (['D', 'E', 'F'] as $c) $sheet->getColumnDimension($c)->setWidth(10);
    }

    /** 单张明细表导出：按模式选择纯数值/带公式，并在公式版追加说明页 */
    private function singleSheetResponse(Request $request, $rows, string $title, string $ym, string $filename)
    {
        $formula = $this->resolveExportMode($request) === 'formula';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        if ($formula) {
            $total = $this->fillSheetFormula($sheet, $rows, $title, $ym);
            $this->fillNotesSheet($book, $ym, [['title' => $sheet->getTitle(), 'total' => $total, 'gross' => 0.0]], null, null);
        } else {
            $this->fillSheet($sheet, $rows, $title);
        }
        $book->setActiveSheetIndex(0);
        return $this->xlsx($book, $filename, $formula);
    }

    private function xlsx(Spreadsheet $book, string $filename, bool $forceRecalc = false)
    {
        // 纯数值版保持原内存输出路径，不做任何后处理（逐字节保持）
        if (!$forceRecalc) {
            $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
            return response()->streamDownload(fn () => fpassthru($stream), $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        $bytes = $this->xlsxBinary($book, true);
        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** 生成 xlsx 二进制；公式版追加 fullCalcOnLoad 标记（打开时重算） */
    private function xlsxBinary(Spreadsheet $book, bool $forceRecalc): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'payroll_xlsx_');
        (new Xlsx($book))->save($tmp);
        if ($forceRecalc) $this->enableFullCalcOnLoad($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** 改写 xl/workbook.xml 的 calcPr，让 Excel 打开时强制重算 */
    private function enableFullCalcOnLoad(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return;
        $xml = $zip->getFromName('xl/workbook.xml');
        if ($xml !== false) {
            $xml = preg_replace_callback('/<calcPr\b[^>]*\/>/', function ($m) {
                $tag = $m[0];
                $tag = preg_replace('/\bfullCalcOnLoad="[^"]*"/', 'fullCalcOnLoad="1"', $tag);
                $tag = preg_replace('/\bforceFullCalc="[^"]*"/', 'forceFullCalc="1"', $tag);
                $tag = preg_replace('/\bcalcCompleted="[^"]*"/', 'calcCompleted="0"', $tag);
                return $tag;
            }, $xml, 1);
            $zip->addFromString('xl/workbook.xml', $xml);
        }
        $zip->close();
    }
}
