<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends ApiController
{
    public function calcRules(Request $request): JsonResponse
    {
        return $this->config('calc_rules.json', $request);
    }

    public function symbols(Request $request): JsonResponse
    {
        return $this->config('symbols.json', $request);
    }

    public function symbolFormulas(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $items = $this->config('symbols.json', $request)->getData(true)['items'] ?? [];
        $required = []; $attend = [];
        foreach ($items as $item) {
            $symbol = $item['symbol'] ?? '';
            if ($symbol === '') continue;
            if (!empty($item['in_required'])) $required[] = 'COUNTIF({rng},"' . $symbol . '")';
            if (!empty($item['in_actual'])) $attend[] = ((float) ($item['value'] ?? 1) === 1.0 ? '' : (float) ($item['value'] ?? 1) . '*') . 'COUNTIF({rng},"' . $symbol . '")';
        }
        return response()->json(['ok' => true, 'formulas' => ['required' => '=' . implode('+', $required), 'attend' => '=' . implode('+', $attend), 'categories' => []]]);
    }

    public function symbolExport(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $data = $this->config('symbols.json', $request)->getData(true);
        return response(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="symbols.json"',
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->config('settings.json', $request);
    }

    public function payslipConfig(Request $request): JsonResponse
    {
        return $this->config('payslip_config.json', $request);
    }

    public function config(string $file, Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $allowed = ['calc_rules.json', 'symbols.json', 'settings.json', 'payslip_config.json'];
        if (!in_array($file, $allowed, true)) {
            return response()->json(['ok' => false, 'error' => '配置不存在'], 404);
        }
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', $file)->first();
        return response()->json(['ok' => true] + ($snapshot ? $this->jsonValue($snapshot->payload) : []));
    }

    public function budget(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $year = (int) $request->input('year', now()->year);
        $query = DB::table('payroll_budgets')->where('year', $year);
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        }
        $budgets = $query->get()->mapWithKeys(fn ($row) => [$row->project_name => $this->jsonValue($row->data)])->all();
        return response()->json(['ok' => true, 'year' => (string) $year, 'budgets' => $budgets]);
    }

    public function budgetView(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $year = (int) $request->input('year', now()->year);
        $projects = DB::table('payroll_projects')->where('status', '启用');
        if ($this->isProjectScope($account)) {
            $projects->where('name', $account->project_name);
        }
        $budgets = DB::table('payroll_budgets')->where('year', $year)->get()->keyBy('project_name');
        $out = $projects->get()->map(function ($project) use ($budgets, $year) {
            $budget = isset($budgets[$project->name]) ? ($this->jsonValue($budgets[$project->name]->data) ?: []) : [];
            $monthsBudget = [];
            $monthsActual = [];
            for ($month = 1; $month <= 12; $month++) {
                $monthsBudget[(string) $month] = (float) ($budget['months'][(string) $month] ?? 0);
                $ym = sprintf('%04d-%02d', $year, $month);
                $monthsActual[(string) $month] = (float) DB::table('payroll_results')->where('year_month', $ym)
                    ->where('project_name', $project->name)->get()->sum(fn ($row) => (float) (($this->jsonValue($row->row_data)['gross'] ?? 0)));
            }
            // 年度总预算 = 各月预算之和（与保存逻辑一致）
            $annual = array_sum($monthsBudget);
            $ytd = array_sum($monthsActual);
            // 用循环构造带 1..12 字符串键的执行率（array_map 多数组会重置为索引键，导致前端取值错位）
            $monthRates = [];
            for ($month = 1; $month <= 12; $month++) {
                $key = (string) $month;
                $monthRates[$key] = $monthsBudget[$key] > 0 ? round($monthsActual[$key] / $monthsBudget[$key], 4) : 0;
            }
            return ['project' => $project->name, 'status' => $project->status, 'annual' => $annual,
                'months_budget' => $monthsBudget, 'months_actual' => $monthsActual,
                'month_rates' => $monthRates,
                'ytd' => round($ytd, 2), 'annual_rate' => $annual > 0 ? round($ytd / $annual, 4) : 0];
        });
        return response()->json(['ok' => true, 'year' => (string) $year, 'items' => $out->values()]);
    }
}
