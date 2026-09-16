<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AttendanceController extends ApiController
{
    private const FIELD_MAP = [
        '姓名' => 'name', '员工工号' => 'emp_no', '人员状态' => 'status', '岗位' => 'position',
        '应出勤(天)' => 'req_attend', '应出勤(手填)' => 'req_attend', '实际出勤' => 'act_attend', '实际出勤(天)' => 'act_attend',
        '月度奖励金额' => 'reward', '月度扣罚金额' => 'punish', '餐补' => 'meal_sub',
        '夜班/话餐补贴' => 'night_sub', '夜班/话费补贴' => 'night_sub', '职称/证书补贴' => 'title_sub', '养老保险' => 'pen',
        '医疗保险' => 'med', '失业保险' => 'une', '住房公积金' => 'house', '大病' => 'big',
        '缺卡扣款' => 'miss_deduct', '迟到早退扣款' => 'late_deduct', '其他扣款' => 'other_deduct',
        '工装扣款' => 'uniform_deduct', '月度绩效' => 'coef', '月度绩效系数' => 'coef', '备注' => 'remark',
        '已发福利/奖励' => 'welfare',
    ];

    public function status(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $ym = $request->string('ym')->toString();
        $projects = DB::table('payroll_projects')->where('status', '启用');
        if ($this->isProjectScope($account)) {
            $projects->where('name', $account->project_name);
        }
        $records = DB::table('payroll_attendance')->where('year_month', $ym)->get()->keyBy('project_name');
        $result = $projects->get()->map(fn ($project) => [
            'project' => $project->name,
            'uploaded' => isset($records[$project->name]),
            'count' => isset($records[$project->name]) ? count($this->jsonValue($records[$project->name]->rows) ?: []) : 0,
            'uploaded_at' => isset($records[$project->name]) ? $records[$project->name]->updated_at : '',
        ])->values();
        return response()->json(['ok' => true, 'ym' => $ym, 'projects' => $result]);
    }

    public function upload(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $request->validate(['ym' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'project' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200']]);
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if (!DB::table('payroll_projects')->where('name', $project)->exists()) {
            return response()->json(['ok' => false, 'error' => '项目不存在'], 400);
        }
        if ($this->isArchived($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '该月工资已归档，不能重传考勤'], 400);
        }
        [$rows, $errors] = $this->parseWorkbook($request->file('file')->getPathname());
        if ($errors) {
            return response()->json(['ok' => false, 'error' => implode('；', array_slice($errors, 0, 30))], 400);
        }
        $staffNames = DB::table('payroll_staff')->where('project_name', $project)->where('deleted', false)->pluck('name')->all();
        $unknown = array_values(array_diff(array_keys($rows), $staffNames));
        if ($unknown) {
            return response()->json(['ok' => false, 'error' => '以下人员不在本项目人员档案中：' . implode('、', array_slice($unknown, 0, 10))], 400);
        }
        $key = $ym . '|' . $project;
        $existing = DB::table('payroll_attendance')->where('record_key', $key)->first();
        $dryRun = in_array(strtolower((string) $request->input('dry_run')), ['1', 'true', 'yes'], true);
        $response = ['preview' => $dryRun, 'count' => count($rows), 'overwrite' => (bool) $existing,
            'names' => array_slice(array_keys($rows), 0, 50),
            'has_calc' => DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)->exists()];
        if (!$dryRun) {
            DB::table('payroll_attendance')->updateOrInsert(
                ['record_key' => $key],
                ['year_month' => $ym, 'project_name' => $project,
                 'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
                 'data' => json_encode(['ym' => $ym, 'project' => $project, 'rows' => $rows], JSON_UNESCAPED_UNICODE),
                 'updated_at' => now(), 'created_at' => now()]
            );
        }
        return response()->json(['ok' => true] + $response);
    }

    public function delete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if ($this->isArchived($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '已归档月份不能删除考勤'], 400);
        }
        DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->delete();
        return response()->json(['ok' => true]);
    }

    private function isArchived(string $ym, string $project): bool
    {
        return DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)->where('archived', true)->exists();
    }

    private function parseWorkbook(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $headerRow = null; $columns = []; $days = [];
        $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($row = 1; $row <= min(8, $sheet->getHighestRow()); $row++) {
            for ($col = 1; $col <= min(20, $highestColumn); $col++) {
                if (trim((string) $this->cellValue($sheet, $col, $row)) === '姓名') {
                    $headerRow = $row; break 2;
                }
            }
        }
        if (!$headerRow) return [[], ['未找到“姓名”表头']];
        for ($col = 1; $col <= $highestColumn; $col++) {
            $value = str_replace(["\n", "\r"], '', trim((string) $this->cellValue($sheet, $col, $headerRow)));
            if (preg_match('/^\d{1,2}$/', $value) && (int) $value >= 1 && (int) $value <= 31) $days[(int) $value] = $col;
            if (isset(self::FIELD_MAP[$value])) $columns[self::FIELD_MAP[$value]] = $col;
        }
        if (!isset($columns['name']) || count($days) < 28) return [[], ['模板列结构不完整']];
        $symbols = \App\Services\PayrollCalculator::symbols(); $rows = []; $errors = [];
        for ($row = $headerRow + 1; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $this->cellValue($sheet, $columns['name'], $row));
            if ($name === '' || $name === '星期') continue;
            if (in_array($name, ['合计', '小计'], true)) break;
            if (isset($rows[$name])) { $errors[] = "第{$row}行姓名重复：{$name}"; continue; }
            $daysData = [];
            for ($day = 1; $day <= 31; $day++) {
                $value = $days[$day] ?? null;
                $symbol = $value ? trim((string) $this->cellValue($sheet, $value, $row)) : '';
                if ($symbol !== '' && !isset($symbols[$symbol])) $errors[] = "第{$row}行第{$day}日符号无效：{$symbol}";
                $daysData[] = $symbol;
            }
            $record = ['days' => $daysData];
            foreach (self::FIELD_MAP as $key) {
                if (!isset($columns[$key]) || $key === 'name') continue;
                $value = $this->cellValue($sheet, $columns[$key], $row);
                if ($key === 'coef' && ($value === null || trim((string) $value) === '')) {
                    // 绩效系数留空 = 默认 1.0（全额绩效），避免被当作 0 导致绩效工资为 0
                    $record[$key] = null;
                    continue;
                }
                $record[$key] = in_array($key, ['status', 'position', 'emp_no', 'remark'], true) ? trim((string) ($value ?? '')) : (($value === null || $value === '') ? 0 : round((float) $value, 2));
            }
            $rows[$name] = $record;
        }
        return [$rows, $errors];
    }
}
