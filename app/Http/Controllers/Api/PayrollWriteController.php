<?php

namespace App\Http\Controllers\Api;

use App\Services\CalcRules;
use App\Services\PayrollCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PayrollWriteController extends ApiController
{
    /**
     * POST /api/payroll/calc
     * 参数：ym=YYYY-MM, projects=[string,...]
     */
    public function calculate(Request $request, PayrollCalculator $calc): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $projects = array_values(array_filter((array)$request->input('projects', []), 'is_string'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || !$projects) {
            return response()->json(['ok' => false, 'error' => '核算月份或项目无效'], 400);
        }
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '仅总部可核定薪资，项目账号无核定权限'], 403);
        }
        // 考勤必须锁定才能核算
        $notLocked = [];
        foreach ($projects as $p) {
            $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
            if (!$row || !(bool) $row->locked) $notLocked[] = $p;
        }
        if ($notLocked) {
            return response()->json(['ok' => false, 'error' => '以下项目考勤尚未锁定为最终版本，不能核算：' . implode('、', $notLocked) . '。请通知项目人力上传并锁定考勤后再核算。'], 400);
        }
        try {
            $result = $calc->calculate($ym, $projects);
            if (!empty($result['skipped'])) {
                \Illuminate\Support\Facades\Log::info('payroll.calc.skipped', ['ym' => $ym, 'projects' => $projects, 'reason' => $result['skipped']]);
            }
            return response()->json(['ok' => true, 'count' => $result['count'], 'skipped' => $result['skipped'] ?? [], 'missing' => $result['missing'] ?? []]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => '核算失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/payroll/calc-managers
     * 管理人员核算：参数 ym=YYYY-MM，一次汇总核算所有项目管理人员，生成管理人员工资表。
     * 仅总部可调用（项目账号无核定权限）。
     */
    public function calcManagers(Request $request, PayrollCalculator $calc): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '核算月份无效'], 400);
        }
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '管理人员工资表仅总部可核算，项目账号无权限'], 403);
        }
        try {
            $result = $calc->calculateManagers($ym);
            return response()->json(['ok' => true, 'count' => $result['count'], 'skipped' => $result['skipped'], 'missing' => $result['missing'] ?? []]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => '管理人员核算失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/payroll/calc-case
     * 案场人员核算：参数 ym=YYYY-MM，一次汇总核算所有项目案场人员，生成案场人员工资表。
     * 仅总部可调用（项目账号无核定权限）。
     */
    public function calcCaseStaff(Request $request, PayrollCalculator $calc): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '核算月份无效'], 400);
        }
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '案场人员工资表仅总部可核算，项目账号无权限'], 403);
        }
        try {
            $result = $calc->calculateCaseStaff($ym);
            return response()->json(['ok' => true, 'count' => $result['count'], 'skipped' => $result['skipped'], 'missing' => $result['missing'] ?? []]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => '案场人员核算失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/payroll/calc-hq
     * 总部人员核算：参数 ym=YYYY-MM，物业总部所有人员单独核算，生成总部人员工资表。
     * 仅总部可调用（项目账号无核定权限）。
     */
    public function calcHq(Request $request, PayrollCalculator $calc): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '核算月份无效'], 400);
        }
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '总部人员工资表仅总部可核算，项目账号无权限'], 403);
        }
        try {
            $result = $calc->calculateHq($ym);
            return response()->json(['ok' => true, 'count' => $result['count'], 'skipped' => $result['skipped'], 'missing' => $result['missing'] ?? []]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => '总部人员核算失败：' . $e->getMessage()], 500);
        }
    }
    public function archive(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可归档'], 403);
        $ym = $request->string('ym')->toString();
        $locked = (bool)$request->input('locked');
        // 分层归档：项目工资表/管理人员工资表/案场人员工资表 各自独立锁定/解锁
        // type=manager → 仅锁管理人员行；type=case → 仅锁案场人员行；默认 → 仅锁项目员工行
        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($request->input('type') === 'manager') {
            $query->where('is_manager_row', true)->where('is_hq_row', false);
        } elseif ($request->input('type') === 'case') {
            $query->where('is_case_row', true);
        } elseif ($request->input('type') === 'hq') {
            $query->where('is_hq_row', true);
        } else {
            $query->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        }
        $query->update(['archived' => $locked, 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * 手动微调某一行 → 用当前"工资计算规则"的公式重算派生列（gross/soc/tax/net）。
     * 若用户在 $changes 里显式给了 actual_tax 就以其为准，不再套公式。
     */
    public function adjust(Request $request, PayrollCalculator $calc, CalcRules $rules): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        // 项目账号无薪资核定权限（含微调），仅总部可核定
        if ($this->isProjectScope($account)) {
            return response()->json(['ok' => false, 'error' => '仅总部可核定薪资，项目账号无核定权限'], 403);
        }
        $ym = $request->string('ym')->toString();
        $staffId = (int)$request->input('staff_id');
        $changes = $request->input('fields');
        if (!is_array($changes)) $changes = [$request->input('field') => $request->input('value')];

        $editable = ['night', 'meal', 'title_sub', 'reward', 'welfare', 'punish', 'late_d', 'miss_d', 'other_d',
            'uniform_d', 'pen', 'med', 'une', 'house', 'big', 'coef', 'req_att', 'act_att', 'perf_att',
            'actual_tax', 'spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby', 'remark'];
        foreach ($changes as $field => $value) {
            if (!in_array($field, $editable, true)) return response()->json(['ok' => false, 'error' => "字段不允许微调：{$field}"], 400);
            if ($field !== 'remark' && !is_numeric($value)) return response()->json(['ok' => false, 'error' => "字段数值无效：{$field}"], 400);
        }
        $record = DB::table('payroll_results')->where('year_month', $ym)->where('staff_legacy_id', $staffId)->first();
        if (!$record) return response()->json(['ok' => false, 'error' => '无该月核算数据'], 404);
        if ($record->archived) return response()->json(['ok' => false, 'error' => '已归档，不能微调'], 400);
        $row = $this->jsonValue($record->row_data) ?: [];
        $userGaveTax = array_key_exists('actual_tax', $changes);
        foreach ($changes as $field => $value) $row[$field] = $field === 'remark' ? (string)$value : round((float)$value, 2);

        // 出勤/绩效相关字段被微调时，按满勤基准重算 base_pay / perf_pay，
        // 否则应发不会跟着出勤变化（如把出勤改成 0 应发仍不变）。
        $attTouched = array_key_exists('act_att', $changes)
            || array_key_exists('req_att', $changes)
            || array_key_exists('perf_att', $changes)
            || array_key_exists('coef', $changes);
        if ($attTouched) {
            $req  = (float)($row['req_att'] ?? 0);
            $act  = (float)($row['act_att'] ?? 0);
            $perf = (float)($row['perf_att'] ?? $act);
            $coef = (float)($row['coef'] ?? 1);
            if ($req > 0) {
                $baseRef = (float)($row['base_pay_ref'] ?? ($row['base_pay'] ?? 0));
                $perfRef = (float)($row['perf_pay_ref'] ?? ($row['perf_pay'] ?? 0));
                $row['base_pay'] = round($baseRef * $act / $req, 2);
                $row['perf_pay'] = round($perfRef * $perf / $req * $coef, 2);
                // 锚定基准，避免多次微调互相复合
                $row['base_pay_ref'] = round($baseRef, 2);
                $row['perf_pay_ref'] = round($perfRef, 2);
            } else {
                $row['base_pay'] = 0.0;
                $row['perf_pay'] = 0.0;
            }
        }

        try {
            $row = $calc->recomputeDerived($row, $staffId, $ym);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => '微调重算失败：' . $e->getMessage()], 400);
        }
        // 用户显式改过 actual_tax → 覆盖回去并按公式重算 net
        if ($userGaveTax) {
            $row['actual_tax'] = round((float)$changes['actual_tax'], 2);
            $row['withhold'] = $row['actual_tax'];
            $vars = [
                'gross' => (float)($row['gross'] ?? 0), 'soc_total' => (float)($row['soc_total'] ?? 0),
                'actual_tax' => $row['actual_tax'], 'welfare' => (float)($row['welfare'] ?? 0),
            ];
            $defaultNet = 'gross - soc_total - actual_tax - welfare';
            $row['net'] = round($rules->evaluate($rules->str('formula.net', ''), $vars, $defaultNet), 2);
            if ($rules->getLastError()) {
                return response()->json(['ok' => false, 'error' => '实发公式计算失败：' . ($rules->getLastError()['error'] ?? '未知错误')], 400);
            }
        }

        DB::table('payroll_results')->where('id', $record->id)->update([
            'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'row' => $row]);
    }

    /**
     * POST /api/payroll/import-history
     * 导入历史工资表（线下核算的月份），写入 payroll_results 归档行，使后续月份累计个税自洽。
     * 参数：file（Excel，可多 sheet，sheet 名表示月份如 2026-01 / 1月）；可选 ym（单 sheet 时指定月份）
     * 表头需包含：姓名、项目、应发工资合计、五险一金合计、专项附加扣除、本月个税
     */
    public function importHistory(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可导入历史工资'], 403);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['ok' => false, 'error' => '未收到文件'], 400);
        }
        $paramYm = trim((string) $request->input('ym', ''));

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getPathname());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => '文件无法解析：' . $e->getMessage()], 400);
        }

        $stats = ['sheets' => 0, 'inserted' => 0, 'updated' => 0, 'skipped_before_hire' => 0, 'errors' => []];
        $staffCache = []; // "name|project" => staff object 或 false

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetName = trim($sheet->getTitle());
            $ym = $this->parseSheetMonth($sheetName, $paramYm);
            if ($ym === null) {
                $stats['errors'][] = "Sheet「{$sheetName}」无法识别月份（命名需为 YYYY-MM 或 M月）";
                continue;
            }
            $stats['sheets']++;

            // 定位表头行（含「姓名」且含「应发」的行）
            $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $headerRow = null;
            for ($row = 1; $row <= min(10, $sheet->getHighestRow()); $row++) {
                $vals = [];
                for ($c = 1; $c <= min(40, $highestCol); $c++) {
                    $vals[] = trim(trim((string) $this->cellValue($sheet, $c, $row)), " *＊");
                }
                if (in_array('姓名', $vals, true) && preg_grep('/应发/', $vals)) {
                    $headerRow = $row;
                    break;
                }
            }
            if (!$headerRow) {
                $stats['errors'][] = "{$ym} Sheet「{$sheetName}」未找到表头（需含「姓名」「应发工资合计」列）";
                continue;
            }

            // 列映射：先按标准 35 列工资表精确匹配（列名同系统导出），
            // 再用模糊规则回退（兼容 7 列精简模板或列名微调）；表头的 */空白标记忽略
            $fullMap = [
                '项目' => 'project', '部门' => 'department', '岗位' => 'position', '姓名' => 'name',
                '人员状态' => 'status', '固定月薪' => 'fixed', '基本工资' => 'base',
                '应出勤' => 'req_att', '实际出勤' => 'act_att', '绩效系数' => 'coef',
                '应发基本工资' => 'base_pay', '应发绩效工资' => 'perf_pay', '病假工资' => 'sick_pay',
                '夜班/话费补贴' => 'night', '餐补' => 'meal', '其他补贴' => 'title_sub',
                '月度奖励' => 'reward', '已发福利' => 'welfare', '月度扣罚' => 'punish',
                '迟到早退扣款' => 'late_d', '缺卡扣款' => 'miss_d', '其他扣款' => 'other_d',
                '工装扣款' => 'uniform_d', '应发工资合计' => 'gross',
                '养老保险' => 'pen', '医疗保险' => 'med', '失业保险' => 'une',
                '住房公积金' => 'house', '大病' => 'big', '五险一金合计' => 'soc_total',
                '专项附加扣除' => 'spec_total', '本月个税' => 'actual_tax', '实发工资' => 'net', '备注' => 'remark',
            ];
            $cols = [];
            $headByCol = [];
            for ($c = 1; $c <= $highestCol; $c++) {
                $h = trim(trim((string) $this->cellValue($sheet, $c, $headerRow)), " *＊");
                $headByCol[$c] = $h;
                if ($h === '' || $h === '序号') continue;
                if (isset($fullMap[$h])) $cols[$fullMap[$h]] = $c;
            }
            // 模糊回退：精确映射没命中时按关键词找列（精简模板/列名微调）
            $findCol = function (callable $pred) use ($headByCol) {
                foreach ($headByCol as $c => $h) {
                    if ($h !== '' && $pred($h)) return $c;
                }
                return null;
            };
            if (!isset($cols['project'])) {
                $cp = $findCol(fn($h) => $h !== '项目' && str_contains($h, '项目'));
                if ($cp) $cols['project'] = $cp;
            }
            if (!isset($cols['gross'])) {
                $cg = $findCol(fn($h) => str_contains($h, '应发') && str_contains($h, '合计'));
                if ($cg) $cols['gross'] = $cg;
            }
            if (!isset($cols['soc_total'])) {
                $cs = $findCol(fn($h) => str_contains($h, '五险一金') && str_contains($h, '合计'));
                if ($cs) $cols['soc_total'] = $cs;
            }
            if (!isset($cols['spec_total'])) {
                $c2 = $findCol(fn($h) => str_contains($h, '专项附加'));
                if ($c2) $cols['spec_total'] = $c2;
            }
            if (!isset($cols['actual_tax'])) {
                $c3 = $findCol(fn($h) => str_contains($h, '个税'));
                if ($c3) $cols['actual_tax'] = $c3;
            }
            if (!isset($cols['net'])) {
                $c4 = $findCol(fn($h) => str_contains($h, '实发'));
                if ($c4) $cols['net'] = $c4;
            }
            foreach (['name', 'gross', 'actual_tax'] as $req) {
                if (!isset($cols[$req])) {
                    $stats['errors'][] = "{$ym} 缺少必需列：" . match ($req) {
                        'name' => '姓名', 'gross' => '应发工资合计', 'actual_tax' => '本月个税',
                    };
                    continue 2;
                }
            }

            // 数值列读取：列不存在或单元格为空时返回 null（由调用方决定默认值）
            $num = function (string $key, int $row) use ($sheet, $cols) {
                if (!isset($cols[$key])) return null;
                $v = $this->cellValue($sheet, $cols[$key], $row);
                if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : (string) $v;
                $v = trim((string) $v);
                return $v === '' ? null : round((float) $v, 2);
            };
            $txt = function (string $key, int $row) use ($sheet, $cols) {
                if (!isset($cols[$key])) return '';
                $v = $this->cellValue($sheet, $cols[$key], $row);
                if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : (string) $v;
                return trim((string) $v);
            };

            // 用 getHighestDataRow 而非 getHighestRow：模板预置了金额格式到1000行，
            // 但空 Sheet 不应按样式行空转；只扫描有实际值的行
            for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
                $name = trim((string) $this->cellValue($sheet, $cols['name'], $row));
                if ($name === '' || str_contains($name, '合计') || str_contains($name, '总计')) continue;
                $project = isset($cols['project']) ? trim((string) $this->cellValue($sheet, $cols['project'], $row)) : '';
                if ($project === '') {
                    $stats['errors'][] = "{$ym} 第{$row}行「{$name}」缺少项目";
                    continue;
                }
                $cacheKey = $name . '|' . $project;
                if (!isset($staffCache[$cacheKey])) {
                    // 历史工资是事实数据：离职、已删档人员均可匹配（当月确实领薪）
                    $matches = DB::table('payroll_staff')->where('name', $name)
                        ->where('project_name', $project)->get();
                    if ($matches->count() === 0) {
                        $stats['errors'][] = "{$ym} 「{$name}@{$project}」不在系统人员档案中";
                        $staffCache[$cacheKey] = false;
                        continue;
                    }
                    if ($matches->count() > 1) {
                        $stats['errors'][] = "{$ym} 「{$name}@{$project}」存在重名人员，无法匹配";
                        $staffCache[$cacheKey] = false;
                        continue;
                    }
                    $staffCache[$cacheKey] = $matches->first();
                }
                $staff = $staffCache[$cacheKey];
                if ($staff === false) continue;

                // 入职日前的月份跳过（年中入职员工不累计入职前）
                if (!empty($staff->hire_date)) {
                    $hireYm = substr((string) $staff->hire_date, 0, 7);
                    if ($ym < $hireYm) {
                        $stats['skipped_before_hire']++;
                        continue;
                    }
                }

                // 数值列：Excel 有值用 Excel，无列/空值按 0
                $numKeys = ['fixed', 'base', 'req_att', 'act_att', 'coef', 'base_pay', 'perf_pay', 'sick_pay',
                    'night', 'meal', 'title_sub', 'reward', 'welfare', 'punish', 'late_d', 'miss_d', 'other_d',
                    'uniform_d', 'gross', 'pen', 'med', 'une', 'house', 'big', 'soc_total', 'spec_total', 'actual_tax'];
                $vals = [];
                foreach ($numKeys as $k) $vals[$k] = $num($k, $row) ?? 0.0;
                // 固定月薪/基本工资：Excel 留空时回退人员档案当前值
                if (($fixedExcel = $num('fixed', $row)) === null) $vals['fixed'] = (float) ($staff->fixed_monthly ?? 0);
                if (($baseExcel = $num('base', $row)) === null) $vals['base'] = (float) ($staff->base_salary ?? 0);

                $gross = $vals['gross'];
                $soc = $vals['soc_total'];
                $tax = $vals['actual_tax'];
                $welfare = $vals['welfare'];
                // 实发：Excel 有值用 Excel；否则按系统公式 应发-五险一金-个税-已发福利
                $netExcel = $num('net', $row);
                $net = $netExcel !== null ? $netExcel : round($gross - $soc - $tax - $welfare, 2);

                // 文本列：Excel 有值用 Excel，空值回退人员档案
                $department = $txt('department', $row);
                if ($department === '') $department = $this->deptFromPath($staff->dept_path ?? '', (string) $staff->project_name);
                $position = $txt('position', $row) ?: ((string) ($staff->position ?? ''));
                $status = $txt('status', $row) ?: ((string) ($staff->status ?? '') ?: '正式');
                $remark = $txt('remark', $row);

                $rowData = [
                    'staff_id' => $staff->legacy_id,
                    'name' => $staff->name,
                    'project' => $staff->project_name,
                    'department' => $department,
                    'position' => $position,
                    'status' => $status,
                    'fixed' => $vals['fixed'],
                    'base' => $vals['base'],
                    'req_att' => $vals['req_att'],
                    'act_att' => $vals['act_att'],
                    'coef' => $vals['coef'],
                    'base_pay' => $vals['base_pay'],
                    'perf_pay' => $vals['perf_pay'],
                    'sick_pay' => $vals['sick_pay'],
                    'night' => $vals['night'],
                    'meal' => $vals['meal'],
                    'title_sub' => $vals['title_sub'],
                    'reward' => $vals['reward'],
                    'welfare' => $welfare,
                    'punish' => $vals['punish'],
                    'late_d' => $vals['late_d'],
                    'miss_d' => $vals['miss_d'],
                    'other_d' => $vals['other_d'],
                    'uniform_d' => $vals['uniform_d'],
                    'gross' => $gross,
                    'pen' => $vals['pen'],
                    'med' => $vals['med'],
                    'une' => $vals['une'],
                    'house' => $vals['house'],
                    'big' => $vals['big'],
                    'soc_total' => $soc,
                    'spec_total' => $vals['spec_total'],
                    'actual_tax' => $tax,
                    'withhold' => $tax,
                    'net' => $net,
                    'remark' => $remark,
                    'imported_history' => true,
                ];

                $existing = DB::table('payroll_results')
                    ->where('year_month', $ym)->where('staff_legacy_id', $staff->legacy_id)->first();
                if ($existing) {
                    DB::table('payroll_results')->where('id', $existing->id)->update([
                        'row_data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
                        'archived' => true,
                        'updated_at' => now(),
                    ]);
                    $stats['updated']++;
                } else {
                    DB::table('payroll_results')->insert([
                        'year_month' => $ym,
                        'staff_legacy_id' => $staff->legacy_id,
                        'project_name' => $staff->project_name,
                        'row_data' => json_encode($rowData, JSON_UNESCAPED_UNICODE),
                        'archived' => true,
                        'is_manager_row' => (int) ($staff->person_type === 'manager'),
                        'is_case_row' => (int) ($staff->person_type === 'case'),
                        'is_hq_row' => (int) ($staff->person_type === 'hq'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $stats['inserted']++;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'sheets' => $stats['sheets'],
            'inserted' => $stats['inserted'],
            'updated' => $stats['updated'],
            'skipped_before_hire' => $stats['skipped_before_hire'],
            'errors' => $stats['errors'],
        ]);
    }

    /** 从 dept_path（形如 "罗庄春暖花开/客服部"）解析末级部门名；末级等于项目名时回退上一级（与 PayrollCalculator 同逻辑） */
    private function deptFromPath(?string $deptPath, string $project): string
    {
        $path = trim((string) $deptPath);
        if ($path === '') return '';
        $parts = array_values(array_filter(array_map('trim', preg_split('#[\\\/]#u', $path)), fn($s) => $s !== ''));
        if (!$parts) return '';
        $last = end($parts);
        if ($last === $project && count($parts) >= 2) $last = $parts[count($parts) - 2];
        return $last === $project ? '' : (string) $last;
    }

    /** 从 sheet 名解析月份（YYYY-MM 或 M月/MM月）；无法解析时回退到 $fallback */
    private function parseSheetMonth(string $sheetName, string $fallback): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $sheetName, $m)) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^(\d{1,2})月$/', $sheetName, $m)) {
            $year = $fallback !== '' ? (int) substr($fallback, 0, 4) : (int) date('Y');
            return sprintf('%04d-%02d', $year, (int) $m[1]);
        }
        if ($fallback !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fallback)) {
            return $fallback;
        }
        return null;
    }
}
