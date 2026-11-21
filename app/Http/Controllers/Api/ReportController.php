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
        $project = $this->reportProject($request, $account); $category = $this->reportCategory($request);
        $records = $this->scopedResultRecords($account, $ym, $project, $category);
        $split = $this->splitResultRecords($records);
        $projectRows = $this->reportRows($split, $category);
        $gross = $this->sumFieldRows($projectRows, 'gross'); $net = $this->sumFieldRows($projectRows, 'net');
        $headcount = $projectRows->count();
        // 项目口径 = 基层 + 管理；案场/总部单独列出，不并入项目合计
        $projects = $records->groupBy(fn ($r) => (string) ($r->project_name ?: '未分配'))->map(function ($items, $project) use ($category) {
            $s = $this->splitResultRecords($items); $p = $this->reportRows($s, $category);
            return ['project' => $project, 'headcount' => $p->count(),
                'gross' => $this->sumFieldRows($p, 'gross'), 'net' => $this->sumFieldRows($p, 'net'),
                'base_gross' => $this->sumFieldRows($s['base'], 'gross'),
                'manager_gross' => $this->sumFieldRows($s['manager'], 'gross'),
                'case_gross' => $this->sumFieldRows($s['case'], 'gross'),
                'hq_gross' => $this->sumFieldRows($s['hq'], 'gross'),
                'case_headcount' => $s['case']->count(), 'month_budget' => 0, 'month_rate' => 0];
        })->values();
        $staffQuery = DB::table('payroll_staff');
        $this->applyStaffScope($staffQuery, $project, $category, $account);
        $staff = $staffQuery->get(); $status = $staff->groupBy('status')->map->count();
        // 部门人数分布（按 dept_path 末级部门聚合；未挂部门归"未分配"）
        $deptHead = $staff->groupBy(fn ($s) => $this->deptName($s))->map->count();
        // 部门人工成本（项目口径：基层 + 管理）
        $deptCost = [];
        $staffDeptQuery = DB::table('payroll_staff');
        if ($project !== null && $project !== '') $staffDeptQuery->where('project_name', $project);
        $staffDept = $staffDeptQuery->pluck('dept_path', 'legacy_id');
        foreach ($records as $dbRow) {
            if ((int) ($dbRow->is_case_row ?? 0) === 1 || (int) ($dbRow->is_hq_row ?? 0) === 1) continue;
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
        $monthKeys = collect(range(1, 12))->map(fn ($m) => sprintf('%04d-%02d', $year, $m))->all();
        $yearQ = DB::table('payroll_results')->whereIn('year_month', $monthKeys);
        $this->applyResultScope($yearQ, $project, $category, $account);
        $yearRecords = $yearQ->get()->groupBy('year_month');
        $months = collect(range(1, 12))->map(function ($month) use ($year, $yearRecords, $category) {
            $recs = $yearRecords->get(sprintf('%04d-%02d', $year, $month), collect());
            $split = $this->splitResultRecords($recs); $rows = $this->reportRows($split, $category);
            return ['month' => $month, 'gross' => $this->sumFieldRows($rows, 'gross'),
                'net' => $this->sumFieldRows($rows, 'net'), 'headcount' => $rows->count(),
                'case_gross' => $this->sumFieldRows($split['case'], 'gross'),
                'hq_gross' => $this->sumFieldRows($split['hq'], 'gross')];
        })->values();
        return response()->json(['ok' => true, 'year' => $year, 'ym' => $ym, 'months' => $months,
            'projects' => $projects, 'proj_cost' => $projects, 'status_dist' => $status,
            'dept_headcount' => $deptHead, 'dept_cost' => $deptCost,
            'active_staff' => $staff->count(), 'month_headcount' => $headcount, 'month_gross' => $gross,
            'month_net' => $net, 'avg_gross' => $headcount ? round($gross / $headcount, 2) : 0,
            'month_breakdown' => $this->categoryBreakdown($split),
            'ytd_gross' => round($months->where('month', '<=', (int) substr($ym, 5, 2))->sum('gross'), 2),
            'ytd_net' => round($months->where('month', '<=', (int) substr($ym, 5, 2))->sum('net'), 2),
            'filters' => ['project' => $project, 'category' => $category],
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
        $project = $this->reportProject($request, $account); $category = $this->reportCategory($request);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $annual ? sprintf('%04d-12', $year) : sprintf('%04d-%02d', $year, (int) date('m'));
        if ($annual) $ym = sprintf('%04d-12', $year);
        $prevYm = $this->prevMonth($ym);

        // —— KPI（当前月 + 上月）——
        $cur = $this->hrKpis($account, $ym, $annual, $project, $category);
        $prev = $this->hrKpis($account, $prevYm, $annual, $project, $category);
        $deltas = [];
        foreach (['active', 'in', 'out', 'regular', 'avg_pay'] as $k) {
            $cv = $cur[$k]; $pv = $prev[$k];
            $deltas[$k] = $pv != 0 ? round(($cv - $pv) / abs($pv) * 100, 1) : ($cv != 0 ? 100 : 0);
        }

        // —— 图表数据 ——
        $staff = $this->scopedStaff($account, $project, $category);
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

        // 人力成本（按项目，当月应发；项目口径 = 基层 + 管理，案场单列）
        $costRecords = $this->scopedResultRecords($account, $ym, $project, $category);
        $costSplit = $this->splitResultRecords($costRecords);
        $costByProject = $costRecords->groupBy(fn ($r) => (string) ($r->project_name ?: '未分配'))->map(function ($items, $p) use ($category) {
            $s = $this->splitResultRecords($items); $rows = $this->reportRows($s, $category);
            return ['label' => $p, 'headcount' => $rows->count(), 'gross' => $this->sumFieldRows($rows, 'gross'),
                'base_gross' => $this->sumFieldRows($s['base'], 'gross'),
                'manager_gross' => $this->sumFieldRows($s['manager'], 'gross'),
                'case_gross' => $this->sumFieldRows($s['case'], 'gross'),
                'case_headcount' => $s['case']->count()];
        })->filter(fn ($r) => $r['headcount'] > 0 || $r['case_headcount'] > 0)->sortByDesc('gross')->values();

        return response()->json(['ok' => true, 'year' => $year, 'ym' => $ym, 'annual' => $annual,
            'kpis' => $cur, 'deltas' => $deltas,
            'structure' => $structure, 'trend' => $trend, 'age_dist' => $ageDist,
            'tenure_dist' => $tenureDist, 'edu_dist' => $eduDist, 'hometown_top' => $hometownTop,
            'status_dist' => $statusDist, 'cost_by_project' => $costByProject,
            'cost_breakdown' => $this->categoryBreakdown($costSplit),
            'filters' => ['project' => $project, 'category' => $category]]);
    }

    private function hrKpis($account, string $ym, bool $annual, ?string $project = null, string $category = 'all'): array
    {
        $monthEnd = date('Y-m-t', strtotime($ym . '-01'));
        $staff = $this->scopedStaff($account, $project, $category);
        $active = $this->activeStaffOf($staff, $monthEnd);
        $m = substr($ym, 0, 7);
        $year = (int) substr($ym, 0, 4);
        $in = $staff->filter(fn ($s) => $s->hire_date && ($annual ? str_starts_with((string) $s->hire_date, (string) $year) : str_starts_with((string) $s->hire_date, $m)));
        $out = $staff->filter(fn ($s) => $s->resign_date && ($annual ? str_starts_with((string) $s->resign_date, (string) $year) : str_starts_with((string) $s->resign_date, $m)));
        $regular = $staff->filter(fn ($s) => $s->regular_date && ($annual ? str_starts_with((string) $s->regular_date, (string) $year) : str_starts_with((string) $s->regular_date, $m)));
        $costRecords = $this->scopedResultRecords($account, $ym, $project, $category);
        $costRows = $this->reportRows($this->splitResultRecords($costRecords), $category);
        $gross = $this->sumFieldRows($costRows, 'gross');
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

    private function scopedStaff($account, ?string $project = null, string $category = 'all')
    {
        $q = DB::table('payroll_staff')->where('deleted', false);
        $this->applyStaffScope($q, $project, $category, $account);
        return $q->get();
    }

    private function scopedResultRecords($account, string $ym, ?string $project = null, string $category = 'all')
    {
        $q = DB::table('payroll_results')->where('year_month', $ym);
        $this->applyResultScope($q, $project, $category, $account);
        return $q->get();
    }

    /** 报表项目筛选：项目账号强制本项目；管理员可按项目名筛选（空=全部） */
    private function reportProject(Request $request, object $account): ?string
    {
        if ($this->isProjectScope($account)) return (string) $account->project_name;
        $project = trim((string) $request->input('project', ''));
        return $project !== '' ? $project : null;
    }

    /** 报表分类筛选：all/base/manager/case/hq（默认 all，保持原口径） */
    private function reportCategory(Request $request): string
    {
        $category = strtolower(trim((string) $request->input('category', 'all')));
        return in_array($category, ['base', 'manager', 'case', 'hq'], true) ? $category : 'all';
    }

    /** 工资结果行按项目 + 行类型过滤（与 /api/summary 的 is_*_row 口径一致） */
    private function applyResultScope($q, ?string $project, string $category, object $account): void
    {
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $project;
        if ($project !== null && $project !== '') $q->where('project_name', $project);
        if ($category === 'base') {
            $q->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        } elseif ($category === 'manager') {
            $q->where('is_manager_row', true)->where('is_hq_row', false);
        } elseif ($category === 'case') {
            $q->where('is_case_row', true);
        } elseif ($category === 'hq') {
            $q->where('is_hq_row', true);
        }
    }

    /** 人员档案按项目 + 人员分类过滤（base=staff，兼容 person_type 为空的历史数据） */
    private function applyStaffScope($q, ?string $project, string $category, object $account): void
    {
        $q->where('deleted', false);
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $project;
        if ($project !== null && $project !== '') $q->where('project_name', $project);
        if ($category === 'all') return;
        $type = ['base' => 'staff', 'manager' => 'manager', 'case' => 'case', 'hq' => 'hq'][$category] ?? null;
        if ($type === null) return;
        if ($type === 'staff') {
            $q->where(function ($w) {
                $w->where('person_type', 'staff')->orWhereNull('person_type')->orWhere('person_type', '');
            });
        } else {
            $q->where('person_type', $type);
        }
    }

    /** 当前筛选口径下的报表行：all=基层+管理（原口径），其余=所选分类 */
    private function reportRows(array $split, string $category)
    {
        if ($category === 'all') return $this->projectRows($split);
        return $split[$category === 'base' ? 'base' : $category];
    }

    private function categoryOfType(?string $type): string
    {
        return ['manager' => 'manager', 'case' => 'case', 'hq' => 'hq'][$type ?? ''] ?? 'base';
    }

    /**
     * 考勤报表的人员分类索引：优先按月分类（categoryMap），回退档案 person_type。
     * 返回 [legacy_id => person_type, "项目|姓名" => person_type]。
     */
    private function attendanceTypeIndex(?string $project, string $ym): array
    {
        $catMap = \App\Services\PayrollCalculator::categoryMap($ym);
        $q = DB::table('payroll_staff')->where('deleted', false);
        if ($project !== null && $project !== '') $q->where('project_name', $project);
        $byId = []; $byKey = [];
        foreach ($q->get(['legacy_id', 'name', 'project_name', 'person_type']) as $s) {
            $type = $catMap[$s->legacy_id] ?? ($s->person_type ?: 'staff');
            $byId[(int) $s->legacy_id] = $type;
            $byKey[$s->project_name . '|' . $s->name] = $type;
        }
        return [$byId, $byKey];
    }

    private function attendanceTypeOf(array $p, ?string $project, string $name, array $byId, array $byKey): string
    {
        if (isset($p['staff_id']) && $p['staff_id'] !== null && isset($byId[(int) $p['staff_id']])) {
            return $byId[(int) $p['staff_id']];
        }
        return $byKey[($project ?: '') . '|' . $name] ?? 'staff';
    }

    /** 按行类型拆分核算结果：基层 / 管理 / 案场 / 总部（与 /api/summary 口径一致） */
    private function splitResultRecords($records): array
    {
        $out = ['base' => collect(), 'manager' => collect(), 'case' => collect(), 'hq' => collect()];
        foreach ($records as $rec) {
            $row = $this->jsonValue($rec->row_data) ?: [];
            if ((int) ($rec->is_hq_row ?? 0) === 1) $out['hq']->push($row);
            elseif ((int) ($rec->is_manager_row ?? 0) === 1) $out['manager']->push($row);
            elseif ((int) ($rec->is_case_row ?? 0) === 1) $out['case']->push($row);
            else $out['base']->push($row);
        }
        return $out;
    }

    /** 项目口径行：基层 + 管理（案场与总部单独展示，不并入项目合计） */
    private function projectRows(array $split)
    {
        return $split['base']->concat($split['manager'])->values();
    }

    private function sumFieldRows($rows, string $field): float
    {
        return round($rows->sum(fn ($r) => (float) ($r[$field] ?? 0)), 2);
    }

    private function categoryBreakdown(array $split): array
    {
        $out = [];
        foreach (['base' => '基层', 'manager' => '管理', 'case' => '案场', 'hq' => '总部'] as $key => $label) {
            $rows = $split[$key];
            $out[$key] = ['label' => $label, 'headcount' => $rows->count(),
                'gross' => $this->sumFieldRows($rows, 'gross'), 'net' => $this->sumFieldRows($rows, 'net')];
        }
        return $out;
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
        $project = $this->reportProject($request, $account); $category = $this->reportCategory($request);

        $records = $this->scopedResultRecords($account, $ym, $project, $category);
        $split = $this->splitResultRecords($records);
        $rows = $this->reportRows($split, $category);
        $prevRows = $this->reportRows($this->splitResultRecords($this->scopedResultRecords($account, $prevYm, $project, $category)), $category);
        $gross = $this->sumFieldRows($rows, 'gross');
        $net = $this->sumFieldRows($rows, 'net');
        $cnt = $rows->count();
        $tax = $this->sumFieldRows($rows, 'actual_tax');
        $soc = $this->sumFieldRows($rows, 'soc_total');
        $prevGross = $this->sumFieldRows($prevRows, 'gross');
        $prevCnt = $prevRows->count();

        // 本年累计应发/实发/个税/五险一金（year_month 为 'YYYY-MM' varchar，用 like + PHP 侧过滤，兼容 SQLite/MySQL）
        $cumQ = DB::table('payroll_results')->where('year_month', 'like', $year . '-%');
        $this->applyResultScope($cumQ, $project, $category, $account);
        $cumRecords = $cumQ->get()
            ->filter(fn ($row) => (int) substr((string) $row->year_month, 5, 2) <= $month);
        $cumRows = $this->reportRows($this->splitResultRecords($cumRecords), $category);
        $ytdGross = $this->sumFieldRows($cumRows, 'gross');
        $ytdNet = $this->sumFieldRows($cumRows, 'net');
        $ytdTax = $this->sumFieldRows($cumRows, 'actual_tax');
        $ytdSoc = $this->sumFieldRows($cumRows, 'soc_total');

        // 预算：年度总预算 + 执行率（当月应发/当月预算）
        $budgetQ = DB::table('payroll_budgets')->where('year', $year);
        if ($project !== null && $project !== '') $budgetQ->where('project_name', $project);
        $annualBudget = 0; $monthBudget = 0; $budgetRows = $budgetQ->get();
        foreach ($budgetRows as $b) {
            $d = $this->jsonValue($b->data) ?: [];
            $annualBudget += (float) ($d['annual'] ?? 0);
            $monthBudget += (float) ($d['months'][(string) $month] ?? 0);
        }
        $budgetRate = $monthBudget > 0 ? round($gross / $monthBudget * 100, 1) : 0;
        $ytdBudgetRate = $annualBudget > 0 ? round($ytdGross / $annualBudget * 100, 1) : 0;

        $active = $this->activeStaffOf($this->scopedStaff($account, $project, $category), date('Y-m-t', strtotime($ym . '-01')))->count();

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

        // 月度趋势 + 预算执行率趋势（近12月；一次取回按月分组，避免逐月查询）
        $monthlyTrend = []; $budgetTrend = [];
        $cumGross = 0; $cumNet = 0;
        $trendMonths = $this->lastMonths($ym, 12);
        $trendQ = DB::table('payroll_results')->whereIn('year_month', $trendMonths);
        $this->applyResultScope($trendQ, $project, $category, $account);
        $trendRecords = $trendQ->get()->groupBy('year_month');
        foreach ($trendMonths as $m) {
            $mSplit = $this->splitResultRecords($trendRecords->get($m, collect()));
            $mr = $this->reportRows($mSplit, $category);
            $mg = $this->sumFieldRows($mr, 'gross');
            $mn = $this->sumFieldRows($mr, 'net');
            $mMonth = (int) substr($m, 5, 2);
            // 当年累计：1 月起重新累计
            if ($mMonth === 1) { $cumGross = 0; $cumNet = 0; }
            $cumGross += $mg; $cumNet += $mn;
            $monthlyTrend[] = [
                'month' => $m, 'gross' => $mg, 'net' => $mn, 'cnt' => $mr->count(),
                'avg' => $mr->count() ? round($mg / $mr->count(), 2) : 0,
                'soc' => $this->sumFieldRows($mr, 'soc_total'),
                'tax' => $this->sumFieldRows($mr, 'actual_tax'),
                'case_gross' => $this->sumFieldRows($mSplit['case'], 'gross'),
                'cum_gross' => round($cumGross, 2), 'cum_net' => round($cumNet, 2),
            ];
            $mb = 0;
            foreach ($budgetRows as $b) { $d = $this->jsonValue($b->data) ?: []; $mb += (float) ($d['months'][(string) $mMonth] ?? 0); }
            $budgetTrend[] = ['month' => $m, 'gross' => $mg, 'budget' => round($mb, 2), 'rate' => $mb > 0 ? round($mg / $mb * 100, 1) : 0];
        }

        // 各项目薪酬对比（项目口径 = 基层 + 管理，案场单列）
        $projectCompare = [];
        foreach ($records->groupBy(fn ($r) => (string) ($r->project_name ?: '未分配')) as $p => $items) {
            $s = $this->splitResultRecords($items); $pr = $this->reportRows($s, $category);
            if ($category === 'all') {
                if ($pr->isEmpty() && $s['case']->isEmpty()) continue; // 纯总部载体项目不展示
            } elseif ($pr->isEmpty()) {
                continue;
            }
            $projectCompare[$p] = ['label' => $p, 'headcount' => $pr->count(), 'gross' => $this->sumFieldRows($pr, 'gross'),
                'base_gross' => $this->sumFieldRows($s['base'], 'gross'),
                'manager_gross' => $this->sumFieldRows($s['manager'], 'gross'),
                'case_gross' => $this->sumFieldRows($s['case'], 'gross'),
                'case_headcount' => $s['case']->count()];
        }
        $projectCompare = array_values($projectCompare);

        // 薪资构成（当月应发明细汇总）
        $composition = $this->sumField($rows, [
            'base_pay' => '基本工资', 'perf_pay' => '绩效工资', 'sick_pay' => '病假工资',
            'meal' => '餐补', 'night' => '夜班/话补', 'reward' => '奖励', 'backpay' => '补发工资', 'welfare' => '福利',
            'late_d' => '迟到扣款', 'miss_d' => '缺卡扣款', 'other_d' => '其他扣款', 'uniform_d' => '工装扣款',
        ]);

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
            'filters' => ['project' => $project, 'category' => $category],
            'monthly_trend' => $monthlyTrend, 'project_compare' => $projectCompare, 'composition' => $composition,
            'budget_trend' => $budgetTrend, 'perf_dist' => $this->assocToChart($perfBuckets),
            'breakdown' => $this->categoryBreakdown($split),
            'case_total' => ['headcount' => $split['case']->count(), 'gross' => $this->sumFieldRows($split['case'], 'gross'),
                'net' => $this->sumFieldRows($split['case'], 'net')],
            'hq_total' => ['headcount' => $split['hq']->count(), 'gross' => $this->sumFieldRows($split['hq'], 'gross'),
                'net' => $this->sumFieldRows($split['hq'], 'net')],
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
        $project = $this->reportProject($request, $account); $category = $this->reportCategory($request);
        $q = DB::table('payroll_attendance')->where('year_month', $ym);
        if ($project !== null && $project !== '') $q->where('project_name', $project);
        $records = $q->get();
        [$typeById, $typeByKey] = $this->attendanceTypeIndex($project, $ym);

        // 从符号库动态生成分类（替代硬编码，符号库修改后报表自动跟随）
        $symbols = \App\Services\PayrollCalculator::symbols();
        $ANOMALY = [];
        // 异常类别集合（业务概念，不随符号文本变化）
        $anomalyCats = ['迟到', '早退', '缺卡', '旷工', '事假', '病假', '产假'];
        foreach ($symbols as $sym => $meta) {
            $cat = (string) ($meta['category'] ?? '');
            $name = (string) ($meta['name'] ?? $sym);
            if (in_array($cat, $anomalyCats, true)) {
                $ANOMALY[$sym] = $name;
            }
        }

        $projSummary = []; $anomalyTotals = array_fill_keys(array_values($ANOMALY), 0);
        $personStats = [];
        foreach ($records as $rec) {
            $project = $rec->project_name;
            $rows = json_decode((string) $rec->rows, true) ?: [];
            if (!is_array($rows)) $rows = [];
            $proj = ['project' => $project, 'headcount' => 0, 'required' => 0, 'actual' => 0];
            foreach ($rows as $name => $p) {
                if (!is_array($p)) continue;
                $type = $this->attendanceTypeOf($p, $project, $name, $typeById, $typeByKey);
                if ($category !== 'all' && $this->categoryOfType($type) !== $category) continue;
                $days = is_array($p['days'] ?? null) ? $p['days'] : [];
                $proj['headcount']++;
                // 与核算/考勤页同一规则：统计列有存值优先用存值，只有缺失（null）才数符号
                $grid = \App\Services\PayrollCalculator::attendanceStats($days, $symbols);
                $required = (float) \App\Services\PayrollCalculator::storedOrFallback($p, 'req_attend', $grid['required']);
                $actual = (float) \App\Services\PayrollCalculator::storedOrFallback($p, 'act_attend', $grid['attend']);
                $proj['required'] += $required; $proj['actual'] += $actual;
                $person = ['name' => $name, 'project' => $project, 'required' => $required, 'actual' => $actual, 'anomalies' => []];
                foreach ($days as $sym) {
                    $sym = trim((string) $sym);
                    if ($sym === '') continue;
                    $item = \App\Services\PayrollCalculator::symbolLookup($sym, $symbols);
                    // 用符号库中的标准符号做数组 key 查找，避免别名不匹配
                    $stdSym = $item ? (string) ($item['symbol'] ?? $sym) : $sym;
                    if (isset($ANOMALY[$stdSym])) {
                        $anomalyTotals[$ANOMALY[$stdSym]]++;
                        $person['anomalies'][] = $ANOMALY[$stdSym];
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

        // 异常趋势（近6月；一次取回按月分组）
        $anomalyTrend = [];
        $trendMonths = $this->lastMonths($ym, 6);
        $trendQ = DB::table('payroll_attendance')->whereIn('year_month', $trendMonths);
        if ($project !== null && $project !== '') $trendQ->where('project_name', $project);
        $trendRecords = $trendQ->get()->groupBy('year_month');
        foreach ($trendMonths as $m) {
            $t = ['month' => $m, 'total' => 0]; foreach ($ANOMALY as $label) $t[$label] = 0;
            [$mTypeById, $mTypeByKey] = $this->attendanceTypeIndex($project, $m);
            foreach ($trendRecords->get($m, collect()) as $rec) {
                $rows = json_decode((string) $rec->rows, true) ?: [];
                foreach ($rows as $name => $p) {
                    if (!is_array($p)) continue;
                    $type = $this->attendanceTypeOf($p, $project, $name, $mTypeById, $mTypeByKey);
                    if ($category !== 'all' && $this->categoryOfType($type) !== $category) continue;
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
            foreach ($rows as $name => $p) {
                if (!is_array($p)) continue;
                $type = $this->attendanceTypeOf($p, $project, $name, $typeById, $typeByKey);
                if ($category !== 'all' && $this->categoryOfType($type) !== $category) continue;
                foreach (($p['days'] ?? []) as $sym) {
                    $sym = trim((string) $sym); if ($sym === '') continue;
                    $item = \App\Services\PayrollCalculator::symbolLookup($sym, $symbols);
                    $label = $item ? ((string) ($item['name'] ?? $sym)) : $sym;
                    $symbolCount[$label] = ($symbolCount[$label] ?? 0) + 1;
                }
            }
        }
        arsort($symbolCount);
        $symbolDist = array_map(fn ($v, $k) => ['label' => $k, 'value' => $v], array_values($symbolCount), array_keys($symbolCount));

        return response()->json(['ok' => true, 'ym' => $ym,
            'filters' => ['project' => $project, 'category' => $category],
            'projects' => $projSummary, 'anomaly_totals' => $anomalyTotals, 'anomaly_trend' => $anomalyTrend,
            'top' => $top, 'symbol_dist' => $symbolDist]);
    }

    public function staffExport(Request $request)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $includeDeleted = filter_var($request->input('include_deleted', false), FILTER_VALIDATE_BOOLEAN);
        $project = $this->reportProject($request, $account);
        $category = $this->reportCategory($request);
        $query = DB::table('payroll_staff');
        if (!$includeDeleted) $query->where('deleted', false);
        if ($project !== null && $project !== '') $query->where('project_name', $project);
        if ($category !== 'all') {
            $type = ['base' => 'staff', 'manager' => 'manager', 'case' => 'case', 'hq' => 'hq'][$category] ?? null;
            if ($type === 'staff') {
                $query->where(function ($w) { $w->where('person_type', 'staff')->orWhereNull('person_type')->orWhere('person_type', ''); });
            } elseif ($type !== null) {
                $query->where('person_type', $type);
            }
        }
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
        if ($includeDeleted) $headers[] = '是否已删除';
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->getFont()->setBold(true);
        foreach ($rows as $index => $row) {
            $data = $this->jsonValue($row->data) ?: [];
            $birth = $data['birth_date'] ?? '';
            $line = [\App\Services\StaffCategory::derive((array) $row, $today), $row->name, $row->project_name,
                $row->dept_path ?: '未分配', $row->position, $leaderName[(int) $row->leader_id] ?? '', $row->status,
                $row->fixed_monthly, $row->base_salary,
                $data['gender'] ?? '', $data['phone'] ?? '', $data['id_card'] ?? '', $birth, $data['nation'] ?? '', $data['marital'] ?? '',
                $data['school'] ?? '', $data['major'] ?? '', $data['education'] ?? '', $data['grad_date'] ?? '', $data['certificate'] ?? '',
                $data['politics'] ?? '', $data['home_addr'] ?? '', $data['emergency_contact'] ?? '', $data['emergency_phone'] ?? '',
                $data['bank_card'] ?? '', $data['recruit_channel'] ?? '', $data['hometown'] ?? '',
                $row->hire_date, $row->regular_date, $row->resign_date];
            if ($includeDeleted) $line[] = ((int) ($row->deleted ?? 0) === 1) ? '是' : '否';
            $sheet->fromArray($line, null, 'A' . ($index + 2));
        }
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), '人员档案.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
