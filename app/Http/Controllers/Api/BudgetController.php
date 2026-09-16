<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BudgetController extends ApiController
{
    public function save(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year');
        $project = trim((string) $request->input('project'));
        if ($year < 2000 || $year > 2100 || $project === '') {
            return response()->json(['ok' => false, 'error' => '预算参数不完整'], 400);
        }
        if ($this->isProjectScope($account) && $project !== (string) $account->project_name) {
            return response()->json(['ok' => false, 'error' => '无权操作其他项目预算'], 403);
        }
        if (!DB::table('payroll_projects')->where('name', $project)->exists()) {
            return response()->json(['ok' => false, 'error' => '项目不存在'], 400);
        }
        $months = [];
        foreach ((array) $request->input('months', []) as $month => $value) {
            $key = (int) $month;
            if ($key < 1 || $key > 12) {
                return response()->json(['ok' => false, 'error' => "月份键无效：{$month}"], 400);
            }
            $months[(string) $key] = round((float) $value, 2);
        }
        // 年度总预算 = 各月预算之和（与导入/预算页口径一致）
        $data = ['annual' => array_sum($months), 'months' => $months];
        DB::table('payroll_budgets')->updateOrInsert(
            ['year' => $year, 'project_name' => $project],
            ['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['ok' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year');
        if ($year < 2000 || $year > 2100 || !$request->hasFile('file')) {
            return response()->json(['ok' => false, 'error' => '年度或文件无效'], 400);
        }
        try {
            $sheet = IOFactory::load($request->file('file')->getPathname())->getActiveSheet();
            $header = null; $columns = [];
            $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            for ($row = 1; $row <= min(8, $sheet->getHighestRow()); $row++) {
                $values = [];
                for ($col = 1; $col <= min(20, $highestColumn); $col++) {
                    $values[] = trim((string) $this->cellValue($sheet, $col, $row));
                }
                if (in_array('项目', $values, true) && count(array_filter($values, fn ($v) => preg_match('/^\d{1,2}月$/', $v))) > 0) {
                    $header = $row; break;
                }
            }
            if (!$header) return response()->json(['ok' => false, 'error' => '未找到预算表头'], 400);
            for ($col = 1; $col <= $highestColumn; $col++) {
                $value = trim((string) $this->cellValue($sheet, $col, $header));
                if ($value === '项目') $columns['project'] = $col;
                if (preg_match('/^(\d{1,2})月$/', $value, $match)) $columns[(int) $match[1]] = $col;
                if (str_contains($value, '总') || str_contains($value, '合计')) $columns['annual'] = $col;
            }
            if (!isset($columns['project'])) return response()->json(['ok' => false, 'error' => '缺少项目列'], 400);
            $projectNames = DB::table('payroll_projects')->pluck('name')->all();
            $rows = [];
            for ($row = $header + 1; $row <= $sheet->getHighestRow(); $row++) {
                $project = trim((string) $this->cellValue($sheet, $columns['project'], $row));
                if ($project === '' || in_array($project, ['合计', '总计'], true)) continue;
                if (!in_array($project, $projectNames, true)) return response()->json(['ok' => false, 'error' => "项目不存在：{$project}"], 400);
                if ($this->isProjectScope($account) && $project !== (string) $account->project_name) return response()->json(['ok' => false, 'error' => '无权导入其他项目预算'], 403);
                $months = [];
                for ($month = 1; $month <= 12; $month++) {
                    $column = $columns[$month] ?? null;
                    $months[(string) $month] = round((float) ($column ? $this->cellValue($sheet, $column, $row) : 0), 2);
                }
                $annual = isset($columns['annual']) ? round((float) $this->cellValue($sheet, $columns['annual'], $row), 2) : round(array_sum($months), 2);
                // 年度总预算强制 = 各月之和（与线上 save 口径一致，防止 Excel 合计列与月值不一致）
                $annual = round(array_sum($months), 2);
                $rows[] = [$project, ['annual' => $annual, 'months' => $months]];
            }
            DB::transaction(function () use ($rows, $year) {
                foreach ($rows as [$project, $data]) DB::table('payroll_budgets')->updateOrInsert(
                    ['year' => $year, 'project_name' => $project],
                    ['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]
                );
            });
            return response()->json(['ok' => true, 'count' => count($rows)]);
        } catch (\Throwable $error) {
            return response()->json(['ok' => false, 'error' => '预算文件解析失败'], 400);
        }
    }
}
