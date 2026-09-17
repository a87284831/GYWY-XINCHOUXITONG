<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends ApiController
{
    public function attendance(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $ym = $request->string('ym')->toString();
        $project = $request->string('project')->toString();
        if ($this->isProjectScope($account)) {
            $project = (string) $account->project_name;
        }
        $record = DB::table('payroll_attendance')
            ->where('year_month', $ym)->where('project_name', $project)->first();
        if (!$record) {
            return response()->json(['ok' => true, 'ym' => $ym, 'project' => $project,
                'rows' => [], 'stats' => []]);
        }
        $symbols = \App\Services\PayrollCalculator::symbols(); $stats = [];
        foreach (($this->jsonValue($record->rows) ?: []) as $name => $attendance) {
            $stats[$name] = \App\Services\PayrollCalculator::attendanceStats($attendance['days'] ?? [], $symbols);
        }
        return response()->json(['ok' => true, 'ym' => $ym, 'project' => $project,
            'rows' => $this->jsonValue($record->rows), 'stats' => $stats,
            'meta' => ['uploaded_at' => $record->updated_at]]);
    }

    public function payroll(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $ym = $request->string('ym')->toString();
        // 管理人员工资表视图：仅总部可查看（type=manager）
        $managerView = $request->input('type') === 'manager';
        if ($managerView && $this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '管理人员工资表仅总部可见'], 403);
        }
        // 案场人员工资表视图：各项目可查看本项目案场人员（type=case）
        $caseView = $request->input('type') === 'case';
        // 总部人员工资表视图：仅总部可查看（type=hq）
        $hqView = $request->input('type') === 'hq';
        if ($hqView && $this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '总部人员工资表仅总部可见'], 403);
        }
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($managerView) {
            $query->where('is_manager_row', true)->where('is_hq_row', false);
        } elseif ($caseView) {
            $query->where('is_case_row', true)->where('is_hq_row', false);
        } elseif ($hqView) {
            $query->where('is_hq_row', true);
        } else {
            // 项目工资表（含项目账号与总部视图）一律不含管理人员行、案场人员行与总部行
            $query->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        }
        if ($this->isProjectScope($account)) {
            // 项目账号：仅当总部核定(归档)完成后才可查看本项目薪资（员工表看员工行归档；案场表看案场行归档）
            $archivedQuery = DB::table('payroll_results')->where('year_month', $ym)
                ->where('project_name', (string) $account->project_name)
                ->where('archived', true);
            if ($caseView) {
                $archivedQuery->where('is_case_row', true);
            } else {
                $archivedQuery->where('is_manager_row', false)->where('is_case_row', false);
            }
            if (!$archivedQuery->exists()) {
                return response()->json(['ok' => false, 'error' => '本月薪资总部尚未核定，核定完成后才能查看'], 403);
            }
            $query->where('project_name', (string) $account->project_name);
        } elseif ($request->filled('project')) {
            $query->where('project_name', $request->string('project'));
        }
        if ($request->filled('department')) {
            $dept = $request->string('department')->toString();
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.department')) = ?", [$dept]);
        }
        $rows = $query->get()->map(fn ($row) => $this->jsonValue($row->row_data))->filter()->values();
        // 统一按 项目→部门→岗位→姓名 排序（兼容历史落库未排序的数据）
        $rows = collect(\App\Services\PayrollCalculator::orderRows($rows->all()))->values();
        // 部门清单（供前端筛选下拉）
        $departments = $rows->pluck('department')->filter(fn ($d) => (string)$d !== '')->unique()->values();
        return response()->json(['ok' => true, 'ym' => $ym, 'rows' => $rows,
            'departments' => $departments,
            // 该月所有项目均已完成归档才视为"已核定锁定"（避免仅部分项目归档时误报已锁定）
            'archived' => $managerView ? $this->allMgrsArchived($ym) : ($caseView ? $this->allCaseArchived($ym) : ($hqView ? $this->allHqArchived($ym) : $this->allArchived($ym)))]);
    }

    /** 该月案场人员工资表是否已全部归档锁定 */
    private function allCaseArchived(string $ym): bool
    {
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_case_row', true)->where('is_hq_row', false)->get();
        return !$rows->isEmpty() && $rows->every(fn ($r) => (int) $r->archived === 1);
    }

    /** 该月管理人员工资表是否已全部归档锁定 */
    private function allMgrsArchived(string $ym): bool
    {
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_manager_row', true)->where('is_hq_row', false)->get();
        return !$rows->isEmpty() && $rows->every(fn ($r) => (int) $r->archived === 1);
    }

    public function summary(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $ym = $request->string('ym')->toString();
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        }
        $year = substr($ym, 0, 4); $month = (int) substr($ym, 5, 2);
        $budgets = DB::table('payroll_budgets')->where('year', (int) $year)->get()->keyBy('project_name');
        // 一次取回当年至当前月全部核算结果（累计预算执行率用），避免逐项目 N+1 查询
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
        $total = ['headcount' => array_sum(array_column($items, 'headcount')),
            'gross' => round(array_sum(array_column($items, 'gross')), 2),
            'net' => round(array_sum(array_column($items, 'net')), 2),
            'month_budget' => round(array_sum(array_column($items, 'month_budget')), 2),
            'month_rate' => 0, 'annual_budget' => round(array_sum(array_column($items, 'annual_budget')), 2),
            'ytd_gross' => round(array_sum(array_column($items, 'ytd_gross')), 2), 'annual_rate' => 0];
        $total['month_rate'] = $total['month_budget'] > 0 ? round($total['gross'] / $total['month_budget'], 4) : 0;
        $total['annual_rate'] = $total['annual_budget'] > 0 ? round($total['ytd_gross'] / $total['annual_budget'], 4) : 0;
        return response()->json(['ok' => true, 'ym' => $ym, 'items' => $items, 'total' => $total,
            'archived' => false]);
    }

    /** 该月总部人员工资表是否已全部归档锁定 */
    private function allHqArchived(string $ym): bool
    {
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_hq_row', true)->get();
        return !$rows->isEmpty() && $rows->every(fn ($r) => (int) $r->archived === 1);
    }
    /** 该月所有项目是否均已归档（部分归档不算整体锁定；仅按项目表行判定，管理人员表独立锁定） */
    private function allArchived(string $ym): bool
    {
        $rows = DB::table('payroll_results')->where('year_month', $ym)
            ->where('is_manager_row', false)->where('is_hq_row', false)
            ->select('project_name', DB::raw('MAX(archived) AS archived'))->groupBy('project_name')->get();
        return !$rows->isEmpty() && $rows->every(fn ($r) => (int) $r->archived === 1);
    }
}
