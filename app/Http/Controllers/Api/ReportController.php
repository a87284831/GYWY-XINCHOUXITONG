<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends ApiController
{
    public function dashboard(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year); $ym = $request->input('ym') ?: sprintf('%04d-12', $year);
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get()->map(fn ($row) => $this->jsonValue($row->row_data) ?: []);
        $gross = $rows->sum(fn ($row) => (float) ($row['gross'] ?? 0)); $net = $rows->sum(fn ($row) => (float) ($row['net'] ?? 0));
        $projects = $rows->groupBy(fn ($row) => $row['project'] ?? '')->map(fn ($items, $project) => [
            'project' => $project, 'headcount' => $items->count(), 'gross' => round($items->sum(fn ($row) => (float) ($row['gross'] ?? 0)), 2),
            'net' => round($items->sum(fn ($row) => (float) ($row['net'] ?? 0)), 2), 'month_budget' => 0, 'month_rate' => 0,
        ])->values();
        $staffQuery = DB::table('payroll_staff')->where('deleted', false);
        if ($this->isProjectScope($account)) $staffQuery->where('project_name', $account->project_name);
        $staff = $staffQuery->get(); $status = $staff->groupBy('status')->map->count();
        // 部门人数分布（按 dept_path 末级部门聚合；未挂部门归"未分配"）
        $deptHead = $staff->groupBy(fn ($s) => $this->deptName($s))->map->count();
        // 部门人工成本（当月核算结果按人员所属部门聚合）
        $deptCost = [];
        $staffDept = DB::table('payroll_staff')->pluck('dept_path', 'legacy_id');
        $resultRows = $query->get();
        foreach ($resultRows as $dbRow) {
            $j = $this->jsonValue($dbRow->row_data) ?: [];
            $staffId = $dbRow->staff_legacy_id ?? ($j['id'] ?? null);
            $dept = $this->deptNameFromPath($staffId ? ($staffDept[$staffId] ?? null) : null);
            $deptCost[$dept] = [
                'dept' => $dept, 'headcount' => ($deptCost[$dept]['headcount'] ?? 0) + 1,
                'gross' => round(($deptCost[$dept]['gross'] ?? 0) + (float) ($j['gross'] ?? 0), 2),
                'net' => round(($deptCost[$dept]['net'] ?? 0) + (float) ($j['net'] ?? 0), 2),
            ];
        }
        $deptCost = array_values($deptCost);
        $months = collect(range(1, 12))->map(function ($month) use ($year, $account) {
            $query = DB::table('payroll_results')->where('year_month', sprintf('%04d-%02d', $year, $month));
            if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
            $rows = $query->get(); return ['month' => $month, 'gross' => round($rows->sum(fn ($row) => (float) (($this->jsonValue($row->row_data)['gross'] ?? 0))), 2),
                'net' => round($rows->sum(fn ($row) => (float) (($this->jsonValue($row->row_data)['net'] ?? 0))), 2), 'headcount' => $rows->count()];
        })->values();
        return response()->json(['ok' => true, 'year' => $year, 'ym' => $ym, 'months' => $months,
            'projects' => $projects, 'proj_cost' => $projects, 'status_dist' => $status,
            'dept_headcount' => $deptHead, 'dept_cost' => $deptCost,
            'active_staff' => $staff->count(), 'month_headcount' => $rows->count(), 'month_gross' => round($gross, 2),
            'month_net' => round($net, 2), 'avg_gross' => $rows->count() ? round($gross / $rows->count(), 2) : 0,
            'ytd_gross' => round($months->where('month', '<=', (int) substr($ym, 5, 2))->sum('gross'), 2),
            'ytd_net' => round($months->where('month', '<=', (int) substr($ym, 5, 2))->sum('net'), 2),
            'composition' => [], 'staff_trend' => [], 'month_budgets' => [], 'proj_year' => [], 'attendance_anomaly' => []]);
    }

    /** 部门显示名：取 dept_path 末级（如 "项目/客服部" → 客服部）；空 → 未分配 */
    private function deptName(object $staff): string
    {
        return $this->deptNameFromPath($staff->dept_path);
    }

    private function deptNameFromPath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') return '未分配';
        $parts = explode('/', $path);
        return end($parts) ?: '未分配';
    }

    /** 人力资源报表：KPI6 + 人员结构/趋势/年龄/司龄/学历/籍贯/入离职/成本/状态分布 */
    public function hrReport(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        $ym = (string) $request->input('ym');
        $annual = (bool) $request->input('annual', false);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $annual ? sprintf('%04d-12', $year) : sprintf('%04d-%02d', $year, (int) date('m'));
        if ($annual) $ym = sprintf('%04d-12', $year);
        $prevYm = $this->prevMonth($ym);

        // —— KPI（当前月 + 上月）——
        $cur = $this->hrKpis($account, $ym, $annual);
        $prev = $this->hrKpis($account, $prevYm, $annual);
        $deltas = [];
        foreach (['active', 'in', 'out', 'regular', 'avg_pay'] as $k) {
            $cv = $cur[$k]; $pv = $prev[$k];
            $deltas[$k] = $pv != 0 ? round(($cv - $pv) / abs($pv) * 100, 1) : ($cv != 0 ? 100 : 0);
        }

        // —— 图表数据 ——
        $staff = $this->scopedStaff($account);
        $activeNow = $this->activeStaffOf($staff, $cur['monthEnd']);
        $structure = $activeNow->groupBy('project_name')->map->count()->sortDesc()->map(fn ($c, $p) => ['label' => $p, 'value' => $c])->values();

        $trend = [];
        $ymDates = $this->lastMonths($ym, 6);
        foreach ($ymDates as $i => $m) {
            $end = date('Y-m-t', strtotime($m . '-01'));
            $trend[] = [
                'month' => $m,
                'active' => $this->activeStaffOf($staff, $end)->count(),
                'in' => $staff->filter(fn ($s) => $s->hire_date && str_starts_with((string) $s->hire_date, $m))->count(),
                'out' => $staff->filter(fn ($s) => $s->resign_date && str_starts_with((string) $s->resign_date, $m))->count(),
            ];
        }

        $ageDist = $this->ageDistribution($activeNow, $cur['monthEnd']);
        $tenureDist = $this->tenureDistribution($activeNow, $cur['monthEnd']);
        $eduDist = $this->distribution($activeNow, 'education');
        $hometownTop = array_slice($this->distribution($activeNow, 'hometown'), 0, 10);
        $statusDist = $this->statusDistribution($staff, $cur['monthEnd']);

        // 人力成本（按项目，当月应发）
        $costRows = $this->scopedResults($account, $ym);
        $costByProject = $costRows->groupBy(fn ($j) => $j['project'] ?? '')->map(function ($items, $p) {
            return ['label' => $p, 'headcount' => $items->count(), 'gross' => round($items->sum(fn ($j) => (float) ($j['gross'] ?? 0)), 2)];
        })->sortByDesc('gross')->values();

        return response()->json(['ok' => true, 'year' => $year, 'ym' => $ym, 'annual' => $annual,
            'kpis' => $cur, 'deltas' => $deltas,
            'structure' => $structure, 'trend' => $trend, 'age_dist' => $ageDist,
            'tenure_dist' => $tenureDist, 'edu_dist' => $eduDist, 'hometown_top' => $hometownTop,
            'status_dist' => $statusDist, 'cost_by_project' => $costByProject]);
    }

    private function hrKpis($account, string $ym, bool $annual): array
    {
        $monthEnd = date('Y-m-t', strtotime($ym . '-01'));
        $staff = $this->scopedStaff($account);
        $active = $this->activeStaffOf($staff, $monthEnd);
        $m = substr($ym, 0, 7);
        $year = (int) substr($ym, 0, 4);
        $in = $staff->filter(fn ($s) => $s->hire_date && ($annual ? str_starts_with((string) $s->hire_date, (string) $year) : str_starts_with((string) $s->hire_date, $m)));
        $out = $staff->filter(fn ($s) => $s->resign_date && ($annual ? str_starts_with((string) $s->resign_date, (string) $year) : str_starts_with((string) $s->resign_date, $m)));
        $regular = $staff->filter(fn ($s) => $s->regular_date && ($annual ? str_starts_with((string) $s->regular_date, (string) $year) : str_starts_with((string) $s->regular_date, $m)));
        $costRows = $this->scopedResults($account, $ym);
        $gross = $costRows->sum(fn ($j) => (float) ($j['gross'] ?? 0));
        $cnt = $costRows->count();
        $avgPay = $cnt ? round($gross / $cnt, 2) : 0;
        $totalTenure = 0; $tenureCnt = 0;
        foreach ($active as $s) {
            if (!$s->hire_date) continue;
            $months = ((int) substr($monthEnd, 0, 4) - (int) substr((string) $s->hire_date, 0, 4)) * 12 + ((int) substr($monthEnd, 5, 2) - (int) substr((string) $s->hire_date, 5, 2));
            if ($months >= 0) { $totalTenure += $months; $tenureCnt++; }
        }
        return [
            'active' => $active->count(), 'in' => $in->count(), 'out' => $out->count(), 'regular' => $regular->count(),
            'avg_pay' => $avgPay, 'avg_tenure' => $tenureCnt ? round($totalTenure / $tenureCnt / 12, 1) : 0,
            'monthEnd' => $monthEnd,
        ];
    }

    private function scopedStaff($account)
    {
        $q = DB::table('payroll_staff')->where('deleted', false);
        if ($this->isProjectScope($account)) $q->where('project_name', $account->project_name);
        return $q->get();
    }

    private function scopedResults($account, string $ym)
    {
        $q = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $q->where('project_name', $account->project_name);
        return $q->get()->map(fn ($row) => $this->jsonValue($row->row_data) ?: []);
    }

    /** 截至基准日"在职"（含试用；不含离职/黑名单） */
    private function activeStaffOf($staff, string $date)
    {
        return $staff->filter(fn ($s) => \App\Services\StaffCategory::derive((array) $s, $date) === '在职')->values();
    }

    private function prevMonth(string $ym): string
    {
        return date('Y-m', strtotime($ym . '-01 -1 month'));
    }

    private function lastMonths(string $ym, int $n): array
    {
        $out = []; $t = strtotime($ym . '-01');
        for ($i = $n - 1; $i >= 0; $i--) $out[] = date('Y-m', strtotime("-$i month", $t));
        return $out;
    }

    /** 年龄结构：20以下/20-30/31-40/41-50/51+（按出生日期/身份证推算） */
    private function ageDistribution($staff, string $refDate): array
    {
        $buckets = ['20以下' => 0, '20-30岁' => 0, '31-40岁' => 0, '41-50岁' => 0, '51岁以上' => 0];
        $refYear = (int) substr($refDate, 0, 4);
        foreach ($staff as $s) {
            $sd = $this->jsonValue($s->data) ?: [];
            $d = $sd['birth_date'] ?? '';
            if (!$d) $d = \App\Services\StaffProfile::birthFromIdCard((string) ($sd['id_card'] ?? '')) ?? '';
            if (!$d || !preg_match('/^\d{4}/', (string) $d)) continue;
            $age = $refYear - (int) substr((string) $d, 0, 4);
            if ($age < 20) $buckets['20以下']++;
            elseif ($age <= 30) $buckets['20-30岁']++;
            elseif ($age <= 40) $buckets['31-40岁']++;
            elseif ($age <= 50) $buckets['41-50岁']++;
            else $buckets['51岁以上']++;
        }
        return $this->assocToChart($buckets);
    }

    private function tenureDistribution($staff, string $refDate): array
    {
        $buckets = ['1年以下' => 0, '1-3年' => 0, '3-5年' => 0, '5-10年' => 0, '10年以上' => 0];
        $ry = (int) substr($refDate, 0, 4); $rm = (int) substr($refDate, 5, 2);
        foreach ($staff as $s) {
            if (!$s->hire_date) continue;
            $years = $ry - (int) substr((string) $s->hire_date, 0, 4);
            $months = $rm - (int) substr((string) $s->hire_date, 5, 2);
            $age = $years * 12 + $months;
            if ($age < 12) $buckets['1年以下']++;
            elseif ($age < 36) $buckets['1-3年']++;
            elseif ($age < 60) $buckets['3-5年']++;
            elseif ($age < 120) $buckets['5-10年']++;
            else $buckets['10年以上']++;
        }
        return $this->assocToChart($buckets);
    }

    private function distribution($staff, string $key): array
    {
        $map = [];
        foreach ($staff as $s) {
            $dd = $this->jsonValue($s->data) ?: [];
            $v = trim((string) ($dd[$key] ?? ''));
            if ($v === '') continue;
            $map[$v] = ($map[$v] ?? 0) + 1;
        }
        arsort($map);
        return array_map(fn ($v, $k) => ['label' => $k, 'value' => $v], array_values($map), array_keys($map));
    }

    private function statusDistribution($staff, string $refDate): array
    {
        $map = ['在职' => 0, '离职' => 0, '黑名单' => 0];
        foreach ($staff as $s) {
            $cat = \App\Services\StaffCategory::derive((array) $s, $refDate);
            $map[$cat] = ($map[$cat] ?? 0) + 1;
        }
        return $this->assocToChart($map);
    }

    private function assocToChart(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) $out[] = ['label' => (string) $k, 'value' => $v];
        return $out;
    }

    /** 薪酬报表：12 KPI（当月类带环比、累计类带累计）+ 趋势/构成/五险一金个税/预算执行/绩效工资分布 */
    public function salaryReport(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $ym = (string) $request->input('ym');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
        $year = (int) substr($ym, 0, 4);
        $month = (int) substr($ym, 5, 2);
        $prevYm = $this->prevMonth($ym);

        $rows = $this->scopedResults($account, $ym);
        $prevRows = $this->scopedResults($account, $prevYm);
        $gross = $rows->sum(fn ($j) => (float) ($j['gross'] ?? 0));
        $net = $rows->sum(fn ($j) => (float) ($j['net'] ?? 0));
        $cnt = $rows->count();
        $tax = $rows->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0));
        $soc = $rows->sum(fn ($j) => (float) ($j['soc_total'] ?? 0));
        $prevGross = $prevRows->sum(fn ($j) => (float) ($j['gross'] ?? 0));
        $prevCnt = $prevRows->count();

        // 本年累计应发/实发/个税/五险一金（year_month 为 'YYYY-MM' varchar，用 like + PHP 侧过滤，兼容 SQLite/MySQL）
        $cumQ = DB::table('payroll_results')->where('year_month', 'like', $year . '-%');
        if ($this->isProjectScope($account)) $cumQ->where('project_name', $account->project_name);
        $cumRows = $cumQ->get()
            ->filter(fn ($row) => (int) substr((string) $row->year_month, 5, 2) <= $month)
            ->map(fn ($row) => $this->jsonValue($row->row_data) ?: []);
        $ytdGross = $cumRows->sum(fn ($j) => (float) ($j['gross'] ?? 0));
        $ytdNet = $cumRows->sum(fn ($j) => (float) ($j['net'] ?? 0));
        $ytdTax = $cumRows->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0));
        $ytdSoc = $cumRows->sum(fn ($j) => (float) ($j['soc_total'] ?? 0));

        // 预算：年度总预算 + 执行率（当月应发/当月预算）
        $budgetQ = DB::table('payroll_budgets')->where('year', $year);
        if ($this->isProjectScope($account)) $budgetQ->where('project_name', $account->project_name);
        $annualBudget = 0; $monthBudget = 0;
        foreach ($budgetQ->get() as $b) {
            $d = $this->jsonValue($b->data) ?: [];
            $annualBudget += (float) ($d['annual'] ?? 0);
            $monthBudget += (float) ($d['months'][(string) $month] ?? 0);
        }
        $budgetRate = $monthBudget > 0 ? round($gross / $monthBudget * 100, 1) : 0;
        $ytdBudgetRate = $annualBudget > 0 ? round($ytdGross / $annualBudget * 100, 1) : 0;

        $active = $this->activeStaffOf($this->scopedStaff($account), date('Y-m-t', strtotime($ym . '-01')))->count();

        $kpis = [
            ['key' => 'active', 'label' => '在职人员', 'value' => $active, 'delta' => null],
            ['key' => 'count', 'label' => '当月发放人数', 'value' => $cnt, 'delta' => $prevCnt ? round(($cnt - $prevCnt) / $prevCnt * 100, 1) : 0],
            ['key' => 'gross', 'label' => '当月应发', 'value' => round($gross, 2), 'delta' => $prevGross ? round(($gross - $prevGross) / $prevGross * 100, 1) : 0, 'money' => true],
            ['key' => 'net', 'label' => '当月实发', 'value' => round($net, 2), 'delta' => $prevGross ? round(($net - $prevRows->sum(fn ($j) => (float) ($j['net'] ?? 0))) / max(1, $prevRows->sum(fn ($j) => (float) ($j['net'] ?? 0))) * 100, 1) : 0, 'money' => true],
            ['key' => 'avg', 'label' => '人均应发', 'value' => $cnt ? round($gross / $cnt, 2) : 0, 'delta' => ($cnt && $prevCnt && $prevGross) ? round(($gross / $cnt - $prevGross / $prevCnt) / ($prevGross / $prevCnt) * 100, 1) : 0, 'money' => true],
            ['key' => 'ytd_gross', 'label' => '本年累计应发', 'value' => round($ytdGross, 2), 'delta' => null, 'money' => true],
            ['key' => 'ytd_net', 'label' => '本年累计实发', 'value' => round($ytdNet, 2), 'delta' => null, 'money' => true],
            ['key' => 'tax', 'label' => '当月个税', 'value' => round($tax, 2), 'delta' => $prevRows->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0)) ? round(($tax - $prevRows->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0))) / $prevRows->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0)) * 100, 1) : 0, 'money' => true],
            ['key' => 'soc', 'label' => '当月五险一金', 'value' => round($soc, 2), 'delta' => $prevRows->sum(fn ($j) => (float) ($j['soc_total'] ?? 0)) ? round(($soc - $prevRows->sum(fn ($j) => (float) ($j['soc_total'] ?? 0))) / $prevRows->sum(fn ($j) => (float) ($j['soc_total'] ?? 0)) * 100, 1) : 0, 'money' => true],
            ['key' => 'ytd_tax', 'label' => '本年累计个税', 'value' => round($ytdTax, 2), 'delta' => null, 'money' => true],
            ['key' => 'ytd_soc', 'label' => '本年累计五险一金', 'value' => round($ytdSoc, 2), 'delta' => null, 'money' => true],
            ['key' => 'budget', 'label' => '年度总预算', 'value' => round($annualBudget, 2), 'delta' => null, 'money' => true],
            ['key' => 'budget_rate', 'label' => '年度预算执行率', 'value' => $ytdBudgetRate, 'unit' => '%', 'delta' => null],
        ];

        // 月度趋势（近12月，含当月发放人数与当年累计应发）
        $monthlyTrend = [];
        $cumGross = 0; $cumNet = 0;
        foreach ($this->lastMonths($ym, 12) as $m) {
            $mr = $this->scopedResults($account, $m);
            $mg = round($mr->sum(fn ($j) => (float) ($j['gross'] ?? 0)), 2);
            $mn = round($mr->sum(fn ($j) => (float) ($j['net'] ?? 0)), 2);
            $mMonth = (int) substr($m, 5, 2);
            // 当年累计：1 月起重新累计
            if ($mMonth === 1) { $cumGross = 0; $cumNet = 0; }
            $cumGross += $mg; $cumNet += $mn;
            $monthlyTrend[] = [
                'month' => $m, 'gross' => $mg, 'net' => $mn, 'cnt' => $mr->count(),
                'avg' => $mr->count() ? round($mg / $mr->count(), 2) : 0,
                'soc' => round($mr->sum(fn ($j) => (float) ($j['soc_total'] ?? 0)), 2),
                'tax' => round($mr->sum(fn ($j) => (float) ($j['actual_tax'] ?? 0)), 2),
                'cum_gross' => round($cumGross, 2), 'cum_net' => round($cumNet, 2),
            ];
        }

        // 各项目薪酬对比（当月应发，按 payroll_results.project_name 聚合）
        $projQ = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $projQ->where('project_name', $account->project_name);
        $projectCompare = [];
        foreach ($projQ->get() as $pr) {
            $rd = $this->jsonValue($pr->row_data) ?: [];
            $p = (string) ($pr->project_name ?: '未分配');
            $projectCompare[$p] = ['label' => $p, 'headcount' => ($projectCompare[$p]['headcount'] ?? 0) + 1,
                'gross' => round(($projectCompare[$p]['gross'] ?? 0) + (float) ($rd['gross'] ?? 0), 2)];
        }
        $projectCompare = array_values($projectCompare);

        // 薪资构成（当月应发明细汇总）
        $composition = $this->sumField($rows, [
            'base_pay' => '基本工资', 'perf_pay' => '绩效工资', 'sick_pay' => '病假工资',
            'meal' => '餐补', 'night' => '夜班/话补', 'reward' => '奖励', 'welfare' => '福利',
            'late_d' => '迟到扣款', 'miss_d' => '缺卡扣款', 'other_d' => '其他扣款', 'uniform_d' => '工装扣款',
        ]);

        // 预算执行率趋势（近12月）
        $budgetTrend = [];
        foreach ($this->lastMonths($ym, 12) as $m) {
            $mr = $this->scopedResults($account, $m);
            $mg = round($mr->sum(fn ($j) => (float) ($j['gross'] ?? 0)), 2);
            $mb = 0;
            foreach ($budgetQ->get() as $b) { $d = $this->jsonValue($b->data) ?: []; $mb += (float) ($d['months'][(string) (int) substr($m, 5, 2)] ?? 0); }
            $budgetTrend[] = ['month' => $m, 'gross' => $mg, 'budget' => round($mb, 2), 'rate' => $mb > 0 ? round($mg / $mb * 100, 1) : 0];
        }

        // 绩效工资分布（= 固定 − 基本，区间直方图）
        $perfBuckets = ['0-500' => 0, '500-1000' => 0, '1000-2000' => 0, '2000-3000' => 0, '3000以上' => 0];
        foreach ($rows as $j) {
            $perf = (float) ($j['fixed'] ?? 0) - (float) ($j['base'] ?? 0);
            if ($perf <= 500) $perfBuckets['0-500']++;
            elseif ($perf <= 1000) $perfBuckets['500-1000']++;
            elseif ($perf <= 2000) $perfBuckets['1000-2000']++;
            elseif ($perf <= 3000) $perfBuckets['2000-3000']++;
            else $perfBuckets['3000以上']++;
        }

        return response()->json(['ok' => true, 'ym' => $ym, 'year' => $year, 'kpis' => $kpis,
            'monthly_trend' => $monthlyTrend, 'project_compare' => $projectCompare, 'composition' => $composition,
            'budget_trend' => $budgetTrend, 'perf_dist' => $this->assocToChart($perfBuckets),
            'budget' => ['annual' => round($annualBudget, 2), 'month' => round($monthBudget, 2), 'rate' => $budgetRate]]);
    }

    private function sumField($rows, array $map): array
    {
        $sums = [];
        foreach ($map as $k => $label) $sums[$k] = 0;
        foreach ($rows as $j) foreach ($map as $k => $label) $sums[$k] += (float) ($j[$k] ?? 0);
        $out = [];
        foreach ($map as $k => $label) $out[] = ['label' => $label, 'value' => round($sums[$k], 2)];
        return $out;
    }

    /** 考勤报表：出勤率汇总/异常统计/异常趋势/项目对比/Top10/符号构成 */
    public function attendanceReport(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $ym = (string) $request->input('ym');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
        $q = DB::table('payroll_attendance')->where('year_month', $ym);
        if ($this->isProjectScope($account)) $q->where('project_name', $account->project_name);
        $records = $q->get();

        // 异常符号分类
        $ANOMALY = ['迟' => '迟到', '早' => '早退', '缺' => '缺卡', '旷' => '旷工', '事' => '事假', '病' => '病假', '产' => '产假'];
        $normalAttend = ['√', '半', '值', '假'];
        $requiredSymbols = ['√', '半', '假', '缺', '迟', '早', '事', '病', '产', '旷'];

        $projSummary = []; $anomalyTotals = array_fill_keys(array_values($ANOMALY), 0);
        $personStats = [];
        foreach ($records as $rec) {
            $project = $rec->project_name;
            $rows = json_decode((string) $rec->rows, true) ?: [];
            if (!is_array($rows)) $rows = [];
            $proj = ['project' => $project, 'headcount' => 0, 'required' => 0, 'actual' => 0];
            foreach ($rows as $name => $p) {
                if (!is_array($p)) continue;
                $days = is_array($p['days'] ?? null) ? $p['days'] : [];
                $proj['headcount']++;
                $person = ['name' => $name, 'project' => $project, 'required' => 0, 'actual' => 0, 'anomalies' => []];
                foreach ($days as $sym) {
                    $sym = trim((string) $sym);
                    if ($sym === '' || $sym === '休') continue;
                    $v = ($sym === '半') ? 0.5 : 1;
                    $proj['required'] += $v; $person['required'] += $v;
                    if (in_array($sym, $normalAttend, true)) { $proj['actual'] += ($sym === '半' ? 0.5 : 1); $person['actual'] += ($sym === '半' ? 0.5 : 1); }
                    elseif (isset($ANOMALY[$sym])) {
                        $anomalyTotals[$ANOMALY[$sym]]++;
                        $person['anomalies'][] = $ANOMALY[$sym];
                    }
                }
                if ($person['required'] > 0) {
                    $person['rate'] = round($person['actual'] / $person['required'] * 100, 1);
                } else { $person['rate'] = 100; }
                $person['anomalyCount'] = count($person['anomalies']);
                $personStats[] = $person;
            }
            $proj['rate'] = $proj['required'] > 0 ? round($proj['actual'] / $proj['required'] * 100, 1) : 100;
            $projSummary[] = $proj;
        }

        // 异常趋势（近6月）
        $anomalyTrend = [];
        foreach ($this->lastMonths($ym, 6) as $m) {
            $tq = DB::table('payroll_attendance')->where('year_month', $m);
            if ($this->isProjectScope($account)) $tq->where('project_name', $account->project_name);
            $t = ['month' => $m, 'total' => 0]; foreach ($ANOMALY as $label) $t[$label] = 0;
            foreach ($tq->get() as $rec) {
                $rows = json_decode((string) $rec->rows, true) ?: [];
                foreach ($rows as $p) {
                    if (!is_array($p)) continue;
                    foreach (($p['days'] ?? []) as $sym) {
                        $sym = trim((string) $sym);
                        if (isset($ANOMALY[$sym])) { $t[$ANOMALY[$sym]]++; $t['total']++; }
                    }
                }
            }
            $anomalyTrend[] = $t;
        }

        // Top10 异常人员
        usort($personStats, fn ($a, $b) => $b['anomalyCount'] <=> $a['anomalyCount']);
        $top = array_slice(array_values(array_filter($personStats, fn ($p) => $p['anomalyCount'] > 0)), 0, 10);

        // 符号构成（占比）
        $symbolCount = [];
        foreach ($records as $rec) {
            $rows = json_decode((string) $rec->rows, true) ?: [];
            foreach ($rows as $p) {
                if (!is_array($p)) continue;
                foreach (($p['days'] ?? []) as $sym) {
                    $sym = trim((string) $sym); if ($sym === '') continue;
                    $label = $sym === '√' ? '出勤' : ($ANOMALY[$sym] ?? ($sym === '休' ? '休息' : $sym));
                    $symbolCount[$label] = ($symbolCount[$label] ?? 0) + 1;
                }
            }
        }
        arsort($symbolCount);
        $symbolDist = array_map(fn ($v, $k) => ['label' => $k, 'value' => $v], array_values($symbolCount), array_keys($symbolCount));

        return response()->json(['ok' => true, 'ym' => $ym,
            'projects' => $projSummary, 'anomaly_totals' => $anomalyTotals, 'anomaly_trend' => $anomalyTrend,
            'top' => $top, 'symbol_dist' => $symbolDist]);
    }

    public function staffExport(Request $request)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $query = DB::table('payroll_staff'); if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        if (!$this->isProjectScope($account) && $request->filled('project')) $query->where('project_name', $request->string('project'));
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('kw')) {
            $kw = $request->string('kw');
            $query->where(function ($q) use ($kw) { $q->where('name', 'like', '%' . $kw . '%')->orWhere('position', 'like', '%' . $kw . '%'); });
        }
        if ($request->filled('org_id')) {
            $ids = (new \App\Services\OrgService())->descendants((int) $request->input('org_id'));
            $query->whereIn('org_id', $ids);
        }
        $rows = $query->orderBy('project_name')->orderBy('name')->get();
        $today = date('Y-m-d');
        $cat = (string) $request->input('cat', '');
        if ($cat !== '') $rows = $rows->filter(fn ($row) => \App\Services\StaffCategory::derive((array) $row, $today) === $cat)->values();
        $leaderName = [];
        foreach (DB::table('payroll_staff')->select('legacy_id', 'name')->get() as $s) $leaderName[(int) $s->legacy_id] = $s->name;
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('人员档案');
        $headers = ['人员分类', '姓名', '所属项目', '所属部门', '岗位', '直属上级', '人员状态', '固定月薪', '基本工资',
            '性别', '本人联系方式', '身份证号', '出生日期', '民族', '婚姻状况', '毕业院校', '所学专业', '学历', '毕业时间', '资格证书',
            '政治面貌', '家庭住址', '紧急联系人', '紧急联系人电话', '银行卡号', '招聘渠道', '籍贯',
            '入职日期', '转正日期', '离职日期'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->getFont()->setBold(true);
        foreach ($rows as $index => $row) {
            $data = $this->jsonValue($row->data) ?: [];
            $birth = $data['birth_date'] ?? '';
            $sheet->fromArray([\App\Services\StaffCategory::derive((array) $row, $today), $row->name, $row->project_name,
                $row->dept_path ?: '未分配', $row->position, $leaderName[(int) $row->leader_id] ?? '', $row->status,
                $row->fixed_monthly, $row->base_salary,
                $data['gender'] ?? '', $data['phone'] ?? '', $data['id_card'] ?? '', $birth, $data['nation'] ?? '', $data['marital'] ?? '',
                $data['school'] ?? '', $data['major'] ?? '', $data['education'] ?? '', $data['grad_date'] ?? '', $data['certificate'] ?? '',
                $data['politics'] ?? '', $data['home_addr'] ?? '', $data['emergency_contact'] ?? '', $data['emergency_phone'] ?? '',
                $data['bank_card'] ?? '', $data['recruit_channel'] ?? '', $data['hometown'] ?? '',
                $row->hire_date, $row->regular_date, $row->resign_date], null, 'A' . ($index + 2));
        }
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), '人员档案.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
