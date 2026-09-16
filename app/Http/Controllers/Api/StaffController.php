<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\PayrollCalculator;

class StaffController extends ApiController
{
    public function bulkDelete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $ids = array_map('intval', (array) $request->input('ids', []));
        $query = DB::table('payroll_staff')->whereIn('legacy_id', $ids);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $count = $query->update(['deleted' => true, 'updated_at' => now()]);
        return response()->json(['ok' => true, 'count' => $count]);
    }

    public function bulkDeduct(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $ids = array_map('intval', (array) $request->input('ids', [])); $items = $request->input('items', []);
        if (!is_array($items)) return response()->json(['ok' => false, 'error' => '扣除数据格式错误'], 400);
        $query = DB::table('payroll_staff')->whereIn('legacy_id', $ids);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        foreach ($rows as $row) { $data = $this->jsonValue($row->data) ?: []; $data['special_deductions'] = $items; DB::table('payroll_staff')->where('legacy_id', $row->legacy_id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]); }
        return response()->json(['ok' => true, 'count' => $rows->count()]);
    }

    /** 批量设置个税扣除模式：0=普通（每月5000累计），1=6万扣除（年初一次性按全年6万） */
    public function bulkTaxMode(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $ids = array_map('intval', (array) $request->input('ids', []));
        $mode = (int) $request->input('mode', 0);
        if (!in_array($mode, [0, 1], true)) return response()->json(['ok' => false, 'error' => '模式参数错误'], 400);
        if (!$ids) return response()->json(['ok' => false, 'error' => '未选择人员'], 400);
        $query = DB::table('payroll_staff')->whereIn('legacy_id', $ids);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        foreach ($rows as $row) {
            $data = $this->jsonValue($row->data) ?: [];
            $data['tax_mode'] = $mode;
            DB::table('payroll_staff')->where('legacy_id', $row->legacy_id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        return response()->json(['ok' => true, 'count' => $rows->count()]);
    }

    /** 导入模板列定位：精确 → 模糊；新增档案扩展字段 */
    private const EXACT_COLS = [
        '姓名' => 'name', '所属项目' => 'project', '所属部门' => 'dept', '岗位' => 'position',
        '固定工资' => 'fixed', '基本工资' => 'base',
        '本人联系方式' => 'phone', '紧急联系人' => 'emergency_contact', '紧急联系人电话' => 'emergency_phone',
        '性别' => 'gender', '出生日期' => 'birth_date', '民族' => 'nation', '婚姻状况' => 'marital',
        '毕业院校' => 'school', '所学专业' => 'major', '学历' => 'education', '毕业时间' => 'grad_date',
        '资格证书' => 'certificate', '政治面貌' => 'politics', '家庭住址' => 'home_addr',
        '招聘渠道' => 'recruit', '直属上级' => 'leader', '籍贯' => 'hometown',
    ];
    private const CONTAINS_COLS = [
        'name' => '姓名', 'project' => '项目', 'position' => '岗位',
        'fixed' => '月薪', 'base' => '基本工资',
        'hire' => '入职', 'regular' => '转正', 'resign' => '离职',
        'pen' => '养老', 'med' => '医疗', 'une' => '失业', 'house' => '公积金', 'big' => '大病',
        'd_rent' => '租房', 'd_loan' => '住房贷款', 'd_child' => '子女', 'd_elder' => '赡养',
        'd_edu' => '继续教育', 'd_baby' => '婴幼儿', 'card' => '银行卡', 'idcard' => '身份证',
        'black' => '黑名单',
    ];
    /** REQUIRABLE 字段 key → 模板列映射 key（兼容历史必填项配置） */
    private const REQUIRED_COL_MAP = [
        'hire_date' => 'hire', 'id_card' => 'idcard', 'bank_card' => 'card', 'recruit_channel' => 'recruit',
    ];

    public function bulkUpload(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到文件'], 400);
        try { $sheet = IOFactory::load($request->file('file')->getPathname())->getSheet(0); }
        catch (\Throwable $error) { return response()->json(['ok' => false, 'error' => '文件无法解析'], 400); }

        $colMap = $this->locateColumns($sheet);
        if (!isset($colMap['name'], $colMap['project'])) {
            return response()->json(['ok' => false, 'error' => '模板缺少必需列：姓名、所属项目（请下载最新模板）'], 400);
        }
        $get = function (string $key, int $row) use ($sheet, $colMap) {
            if (!isset($colMap[$key])) return null;
            $v = $this->cellValue($sheet, $colMap[$key], $row);
            if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : (string) $v;
            // Excel 日期列可能返回序列号（如 44287=2021-04-01），转成 Y-m-d 文本
            if (in_array($key, ['hire', 'regular', 'resign', 'birth_date', 'grad_date'], true)
                && is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
                try {
                    $v = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
                } catch (\Throwable $e) {}
            }
            return $v;
        };
        $deductKeys = ['d_rent' => '租房租金', 'd_loan' => '住房贷款利息', 'd_child' => '子女教育',
            'd_elder' => '赡养老人', 'd_edu' => '继续教育', 'd_baby' => '婴幼儿照护'];

        $required = \App\Services\StaffProfile::requiredFields();
        $projects = DB::table('payroll_projects')->pluck('name')->all();
        $allStaff = DB::table('payroll_staff')->select('legacy_id', 'name', 'project_name', 'deleted')->get();
        $leaderByName = [];
        foreach ($allStaff as $s) $leaderByName[trim((string) $s->name)] = $s->legacy_id;
        $orgService = new \App\Services\OrgService();
        $enums = \App\Services\StaffProfile::enums();
        $today = date('Y-m-d');

        // ===== 阶段一：全量校验（任一错误 → 整批拒绝，不写库）=====
        $rows = []; $errors = [];
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $get('name', $row));
            if ($name === '' || str_starts_with($name, '填写说明') || mb_strpos($name, '示例') !== false) continue;
            $err = [];
            $blank = fn (string $k) => trim((string) $get($k, $row)) === '';
            // 必填项校验
            foreach ($required as $fk) {
                $colKey = self::REQUIRED_COL_MAP[$fk] ?? $fk;
                if ($blank($colKey)) $err[] = '[' . (\App\Services\StaffProfile::REQUIRABLE[$fk] ?? $fk) . ' 必填]';
            }
            $project = trim((string) $get('project', $row));
            if (!in_array($project, $projects, true)) $err[] = '项目不存在:' . $project;
            elseif ($this->isProjectScope($account) && $project !== (string) $account->project_name) $err[] = '无权操作该项目';
            $position = trim((string) $get('position', $row));
            // 日期校验（兼容 Excel 日期单元格与文本）
            foreach (['hire' => '入职日期', 'regular' => '转正日期', 'resign' => '离职日期', 'birth_date' => '出生日期', 'grad_date' => '毕业时间'] as $k => $label) {
                $v = $this->normDate($get($k, $row));
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $err[] = $label . '格式应为YYYY-MM-DD';
            }
            // 身份证
            $idCard = strtoupper(trim((string) $get('idcard', $row)));
            if ($idCard !== '' && !\App\Services\StaffProfile::validIdCard($idCard)) $err[] = '身份证号不合法';
            // 手机号
            foreach (['phone' => '本人联系方式', 'emergency_phone' => '紧急联系人电话'] as $k => $label) {
                $v = trim((string) $get($k, $row));
                if ($v !== '' && !\App\Services\StaffProfile::validPhone($v)) $err[] = $label . '应为11位手机号';
            }
            // 枚举
            foreach (['gender' => 'gender', 'marital' => 'marital', 'education' => 'education',
                      'politics' => 'politics', 'recruit' => 'recruit', 'nation' => 'nation'] as $k => $enumKey) {
                $v = trim((string) $get($k, $row));
                if ($v !== '' && !in_array($v, $enums[$enumKey] ?? [], true)) $err[] = $v . '不在' . $k . '选项内';
            }
            // 上级存在性（按姓名全库匹配，不限项目）
            $leaderName = trim((string) $get('leader', $row));
            $leaderId = null;
            if ($leaderName !== '') {
                if (!isset($leaderByName[$leaderName])) $err[] = '直属上级「' . $leaderName . '」不在系统人员档案中';
                else $leaderId = $leaderByName[$leaderName];
            }
            // 固定工资 / 基本工资（数值校验，>=0；留空按 0）
            $fixedRaw = trim((string) $get('fixed', $row));
            $baseRaw = trim((string) $get('base', $row));
            $fixedVal = $fixedRaw === '' ? null : (float) $fixedRaw;
            $baseVal = $baseRaw === '' ? null : (float) $baseRaw;
            if ($fixedRaw !== '' && (!is_numeric($fixedRaw) || $fixedVal < 0)) $err[] = '固定工资应为不小于0的数字';
            if ($baseRaw !== '' && (!is_numeric($baseRaw) || $baseVal < 0)) $err[] = '基本工资应为不小于0的数字';
            if ($err) { $errors[] = "第{$row}行【{$name}】：" . implode('；', $err); continue; }

            $birth = $this->normDate($get('birth_date', $row)) ?: \App\Services\StaffProfile::birthFromIdCard($idCard);
            $hire = $this->normDate($get('hire', $row)) ?: null; $regular = $this->normDate($get('regular', $row)) ?: null;
            $resign = $this->normDate($get('resign', $row)) ?: null;
            $deptNode = $orgService->resolveDeptNode($project, (string) $get('dept', $row), $position);
            $deptPath = $deptNode ? $orgService->path((int) $deptNode->id) : ($project . '/待分配');
            $socialRef = ['养老' => (float) $get('pen', $row), '医疗' => (float) $get('med', $row),
                '失业' => (float) $get('une', $row), '公积金' => (float) $get('house', $row), '大病' => (float) $get('big', $row)];
            $socialProvided = collect(['pen', 'med', 'une', 'house', 'big'])->contains(fn ($k) => $get($k, $row) !== null && trim((string) $get($k, $row)) !== '');
            $deducts = []; $deductProvided = false;
            foreach ($deductKeys as $k => $label) {
                $v = $get($k, $row);
                if ($v !== null && trim((string) $v) !== '') $deductProvided = true;
                $deducts[] = ['item' => $label, 'amount' => (float) $v, 'from_ym' => ''];
            }
            $blackRaw = trim((string) $get('black', $row));
            $isBlack = in_array($blackRaw, ['是', 'Y', 'y', '1', 'true'], true);
            $input = [
                'name' => $name, 'project' => $project, 'position' => $position,
                'hire_date' => $hire, 'regular_date' => $regular, 'resign_date' => $resign,
                'bank_card' => (string) $get('card', $row), 'id_card' => $idCard,
                'gender' => trim((string) $get('gender', $row)), 'phone' => trim((string) $get('phone', $row)),
                'birth_date' => $birth, 'nation' => trim((string) $get('nation', $row)),
                'marital' => trim((string) $get('marital', $row)), 'school' => trim((string) $get('school', $row)),
                'major' => trim((string) $get('major', $row)), 'education' => trim((string) $get('education', $row)),
                'grad_date' => $this->normDate($get('grad_date', $row)) ?: null, 'certificate' => trim((string) $get('certificate', $row)),
                'politics' => trim((string) $get('politics', $row)), 'home_addr' => trim((string) $get('home_addr', $row)),
                'emergency_contact' => trim((string) $get('emergency_contact', $row)),
                'emergency_phone' => trim((string) $get('emergency_phone', $row)),
                'recruit_channel' => trim((string) $get('recruit', $row)),
                'hometown' => trim((string) $get('hometown', $row)),
                'social_ref' => $socialRef, 'special_deductions' => $deducts,
            ];
            if ($isBlack) $input['blacklist'] = 1;
            $rows[] = ['input' => $input, 'socialProvided' => $socialProvided, 'deductProvided' => $deductProvided,
                'orgId' => (int) ($deptNode?->id ?? 0), 'deptPath' => $deptPath, 'project' => $project,
                'position' => $position, 'hire' => $hire, 'regular' => $regular, 'resign' => $resign,
                'leaderId' => $leaderId, 'fixedVal' => $fixedVal, 'baseVal' => $baseVal];
        }
        if ($errors) {
            return response()->json(['ok' => false, 'error' => '导入未执行：' . count($errors) . ' 处错误 → ' . implode('；', array_slice($errors, 0, 25))], 400);
        }

        // ===== 阶段二：全部通过后统一写入 =====
        $added = 0; $updated = 0; $pending = 0;
        foreach ($rows as $r) {
            $input = $r['input'];
            $existing = DB::table('payroll_staff')->where('name', $input['name'])->where('project_name', $r['project'])->first();
            $leaderId = $r['leaderId'];
            if ($existing) {
                $data = $this->jsonValue($existing->data) ?: [];
                $data = array_merge($data, $input);
                if ($r['socialProvided']) $data['social_ref'] = $input['social_ref'];
                if ($r['deductProvided']) $data['special_deductions'] = $input['special_deductions'];
                elseif (empty($data['special_deductions'])) $data['special_deductions'] = $input['special_deductions'];
                unset($data['socialProvided'], $data['deductProvided']);
                DB::table('payroll_staff')->where('legacy_id', $existing->legacy_id)->update([
                    'position' => $r['position'],
                    'status' => \App\Services\StaffStatus::derive($r['resign'] ?: $existing->resign_date, $r['regular'] ?: $existing->regular_date, $today),
                    'hire_date' => $r['hire'] ?: $existing->hire_date, 'regular_date' => $r['regular'] ?: $existing->regular_date,
                    'resign_date' => $r['resign'] ?: $existing->resign_date,
                    'org_id' => $r['orgId'] ?: $existing->org_id, 'dept_path' => $r['deptPath'],
                    'leader_id' => $leaderId ?: $existing->leader_id,
                    'fixed_monthly' => $r['fixedVal'] ?? $existing->fixed_monthly,
                    'base_salary' => $r['baseVal'] ?? $existing->base_salary,
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
                ]);
                $updated++;
            } else {
                $id = (int) (DB::table('payroll_staff')->max('legacy_id') ?? 0) + 1;
                DB::table('payroll_staff')->insert([
                    'legacy_id' => $id, 'name' => $input['name'], 'project_name' => $r['project'], 'position' => $r['position'],
                    'status' => \App\Services\StaffStatus::derive($r['resign'], $r['regular'], $today),
                    'fixed_monthly' => $r['fixedVal'] ?? 0, 'base_salary' => $r['baseVal'] ?? 0,
                    'hire_date' => $r['hire'], 'regular_date' => $r['regular'], 'resign_date' => $r['resign'],
                    'org_id' => $r['orgId'] ?: null, 'dept_path' => $r['deptPath'], 'deleted' => false,
                    'leader_id' => $leaderId,
                    'data' => json_encode($input, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $added++;
            }
            if (!$r['orgId']) $pending++;
        }
        $msg = "导入完成：新增 {$added} 人，更新 {$updated} 人";
        if ($pending) $msg .= "；{$pending} 人未能按岗位匹配部门（已标为待分配，可在组织页手动调动）";
        return response()->json(['ok' => true, 'added' => $added, 'updated' => $updated, 'pending' => $pending, 'message' => $msg]);
    }

    /** 表头定位：第一趟精确（去掉*与括号说明），第二趟模糊包含 */
    private function locateColumns($sheet): array
    {
        $colMap = []; $claimed = [];
        $h = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $heads = [];
        for ($c = 1; $c <= $h; $c++) {
            $raw = $sheet->getCell([$c, 1])->getValue();
            if (is_object($raw)) $raw = method_exists($raw, 'getPlainText') ? $raw->getPlainText() : (string) $raw;
            $heads[$c] = trim((string) $raw);
        }
        foreach ($heads as $c => $head) {
            if ($head === '') continue;
            $norm = trim(preg_replace('/[*＊\s]+$/u', '', $head));
            $norm = trim(preg_replace('/[（(].*$/u', '', $norm));
            foreach (self::EXACT_COLS as $label => $key) {
                if (!isset($colMap[$key]) && $norm === $label) { $colMap[$key] = $c; $claimed[$c] = true; }
            }
        }
        foreach ($heads as $c => $head) {
            if ($head === '' || isset($claimed[$c])) continue;
            foreach (self::CONTAINS_COLS as $key => $kw) {
                if (!isset($colMap[$key]) && mb_strpos($head, $kw) !== false) $colMap[$key] = $c;
            }
        }
        return $colMap;
    }

    /** GET 字段配置（枚举+必填项）；POST 保存必填项（仅管理员） */
    public function fieldConfig(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($request->isMethod('post')) {
            if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可设置必填项'], 403);
            \App\Services\StaffProfile::saveRequiredFields((array) $request->input('required', []));
            return response()->json(['ok' => true, 'required' => \App\Services\StaffProfile::requiredFields()]);
        }
        return response()->json(['ok' => true,
            'enums' => \App\Services\StaffProfile::enums(),
            'fields' => array_map(fn ($k, $f) => ['key' => $k, 'label' => $f[0], 'type' => $f[1], 'enum' => $f[2]],
                array_keys(\App\Services\StaffProfile::FIELDS), array_values(\App\Services\StaffProfile::FIELDS)),
            'requirable' => \App\Services\StaffProfile::REQUIRABLE,
            'required' => \App\Services\StaffProfile::requiredFields(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $staff = DB::table('payroll_staff')->where('legacy_id', (int) $request->input('id'))->first();
        if (!$staff) {
            return response()->json(['ok' => false, 'error' => '人员不存在'], 404);
        }
        if ($this->isProjectScope($account) && $staff->project_name !== $account->project_name) {
            return response()->json(['ok' => false, 'error' => '无权查看该项目人员'], 403);
        }
        $data = $this->jsonValue($staff->data) ?: [];
        return response()->json(['ok' => true, 'history' => $data['salary_history'] ?? [], 'staff' => $data]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $query = DB::table('payroll_salary_adjustments');
        if ($request->filled('id')) {
            $staff = DB::table('payroll_staff')->where('legacy_id', (int) $request->input('id'))->first();
            if (!$staff) return response()->json(['ok' => false, 'error' => '人员不存在'], 404);
            if ($this->isProjectScope($account) && $staff->project_name !== $account->project_name) {
                return response()->json(['ok' => false, 'error' => '无权查看该项目人员'], 403);
            }
            $query->where('staff_legacy_id', (int) $request->input('id'));
        } elseif ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        }
        return response()->json(['ok' => true, 'adjusts' => $query->orderByDesc('id')->get()]);
    }

    public function save(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            error_log('[SAVE_DEBUG] requireAccount: ' . $account->getContent());
            return $account;
        }
        $input = $request->input('staff');
        if (!is_array($input)) {
            return response()->json(['ok' => false, 'error' => '人员数据格式错误'], 400);
        }
        $name = trim((string) ($input['name'] ?? ''));
        // 部门 → 自动带项目（不再手选）；未选部门时保留原 project
        $org = null;
        if (!empty($input['org_id'])) {
            $org = DB::table('org_nodes')->where('id', (int) $input['org_id'])->first();
            if (!$org) return response()->json(['ok' => false, 'error' => '所属部门不存在'], 400);
            if (!\App\Services\OrgService::isLeafType($org->type)) {
                return response()->json(['ok' => false, 'error' => '请选择部门或班组（末级节点）作为所属部门'], 400);
            }
        }
        $orgService = new \App\Services\OrgService();
        $project = $org ? $orgService->projectOf((int) $org->id)?->name : trim((string) ($input['project'] ?? ''));
        if ($org && !$project) return response()->json(['ok' => false, 'error' => '所选部门未归属任何项目'], 400);
        // 部门必须属于前端所选项目（先选项目再选部门）
        $reqProject = trim((string) ($input['project'] ?? ''));
        if ($org && $reqProject !== '' && $project !== $reqProject) {
            return response()->json(['ok' => false, 'error' => '所属部门不属于所选项目「' . $reqProject . '」，请重新选择'], 400);
        }
        if ($name === '' || $project === '') {
            return response()->json(['ok' => false, 'error' => '姓名和项目不能为空'], 400);
        }
        if ($this->isProjectScope($account) && $project !== (string) $account->project_name) {
            return response()->json(['ok' => false, 'error' => '无权操作其他项目人员'], 403);
        }
        if (!DB::table('payroll_projects')->where('name', $project)->exists()) {
            return response()->json(['ok' => false, 'error' => '项目不存在'], 400);
        }
        if (!empty($input['leader_id']) && ($input['leader_id'] != ($input['id'] ?? null))
            && DB::table('payroll_staff')->where('legacy_id', (int) $input['leader_id'])->doesntExist()) {
            return response()->json(['ok' => false, 'error' => '直属上级不存在'], 400);
        }
        // 档案扩展字段校验（必填项按管理员配置、枚举、身份证/手机、出生日期推算）
        $required = \App\Services\StaffProfile::requiredFields();
        foreach ($required as $fk) {
            // 部门/直属上级在表单中分别以 org_id / leader_id 提交
            $checkKey = match ($fk) { 'dept' => 'org_id', 'leader' => 'leader_id', default => $fk };
            $v = trim((string) ($input[$checkKey] ?? ''));
            if ($v === '') return response()->json(['ok' => false, 'error' => (\App\Services\StaffProfile::REQUIRABLE[$fk] ?? $fk) . '必填'], 400);
        }
        $enums = \App\Services\StaffProfile::enums();
        foreach (['gender' => 'gender', 'marital' => 'marital', 'education' => 'education',
                  'politics' => 'politics', 'recruit_channel' => 'recruit', 'nation' => 'nation', 'level' => 'level'] as $k => $enumKey) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v !== '' && !in_array($v, $enums[$enumKey] ?? [], true)) return response()->json(['ok' => false, 'error' => $v . '不在' . $k . '选项内'], 400);
        }
        if (!empty($input['id_card']) && !\App\Services\StaffProfile::validIdCard((string) $input['id_card'])) {
            return response()->json(['ok' => false, 'error' => '身份证号不合法'], 400);
        }
        foreach (['phone', 'emergency_phone'] as $pk) {
            if (!empty($input[$pk]) && !\App\Services\StaffProfile::validPhone((string) $input[$pk])) {
                return response()->json(['ok' => false, 'error' => (($pk === 'phone') ? '本人联系方式' : '紧急联系人电话') . '应为11位手机号'], 400);
            }
        }
        if (empty($input['birth_date']) && !empty($input['id_card'])) {
            $input['birth_date'] = \App\Services\StaffProfile::birthFromIdCard((string) $input['id_card']);
        }

        return DB::transaction(function () use ($input, $name, $project, $org, $orgService, $account) {
            $legacyId = isset($input['id']) ? (int) $input['id'] : null;
            $existing = $legacyId ? DB::table('payroll_staff')->where('legacy_id', $legacyId)->first() : null;
            $duplicate = DB::table('payroll_staff')->where('name', $name)->where('project_name', $project)
                ->when($legacyId, fn ($query) => $query->where('legacy_id', '!=', $legacyId))->where('deleted', false)->exists();
            if ($duplicate) {
                return response()->json(['ok' => false, 'error' => '该项目下已存在同名人员'], 409);
            }
            $data = $existing ? ($this->jsonValue($existing->data) ?: []) : [];
            $data = array_merge($data, $input, ['name' => $name, 'project' => $project]);
            $regularDate = array_key_exists('regular_date', $input) ? $this->dateValue($input['regular_date']) : ($existing->regular_date ?? null);
            $resignDate = array_key_exists('resign_date', $input) ? $this->dateValue($input['resign_date']) : ($existing->resign_date ?? null);
            $orgId = $org ? (int) $org->id : ($existing->org_id ?? null);
            $deptPath = $org ? $orgService->path((int) $org->id) : ($existing->dept_path ?? null);
            $leaderId = !empty($input['leader_id']) ? (int) $input['leader_id'] : ($existing->leader_id ?? null);
            $payload = [
                'name' => $name, 'project_name' => $project,
                'position' => $input['position'] ?? ($existing->position ?? null),
                'status' => \App\Services\StaffStatus::derive($resignDate, $regularDate, date('Y-m-d')),
                'fixed_monthly' => (array_key_exists('fixed_monthly', $input) && $input['fixed_monthly'] !== null && $input['fixed_monthly'] !== '') ? (float) $input['fixed_monthly'] : ($existing->fixed_monthly ?? 0),
                'base_salary' => (array_key_exists('base_salary', $input) && $input['base_salary'] !== null && $input['base_salary'] !== '') ? (float) $input['base_salary'] : ($existing->base_salary ?? 0),
                'hire_date' => array_key_exists('hire_date', $input) ? $this->dateValue($input['hire_date']) : ($existing->hire_date ?? null),
                'regular_date' => $regularDate,
                'resign_date' => $resignDate,
                'deleted' => (bool) ($input['deleted'] ?? ($existing->deleted ?? false)),
                'is_manager' => (int) (bool) ($input['is_manager'] ?? ($existing->is_manager ?? false)),
                'org_id' => $orgId, 'leader_id' => $leaderId, 'dept_path' => $deptPath,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now(),
            ];
            if ($existing) {
                DB::table('payroll_staff')->where('legacy_id', $legacyId)->update($payload);
                $id = $legacyId;
            } else {
                $id = (int) (DB::table('payroll_staff')->max('legacy_id') ?? 0) + 1;
                $payload['legacy_id'] = $id;
                DB::table('payroll_staff')->insert($payload);
            }
            // 调动留痕
            $orgChanged = $existing && ((int) ($existing->org_id ?? 0) !== (int) $orgId || ($existing->dept_path ?? '') !== (string) $deptPath);
            if ($orgChanged) {
                DB::table('org_transfer_logs')->insert([
                    'staff_legacy_id' => $id, 'staff_name' => $name,
                    'from_org_id' => $existing->org_id, 'to_org_id' => $orgId,
                    'from_path' => $existing->dept_path, 'to_path' => $deptPath,
                    'change_date' => now()->toDateString(), 'reason' => (($input['transfer_reason'] ?? '') ?: '部门调整'),
                    'by_user' => $account->username, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // 离职/黑名单 → 账号自动停用；复职恢复
            $category = \App\Services\StaffCategory::derive((array) $payload, date('Y-m-d'));
            $bound = DB::table('payroll_accounts')->where('staff_id', $id)->first();
            if ($bound) {
                $shouldEnable = in_array($category, ['在职'], true);
                if ((bool) $bound->enabled !== $shouldEnable) {
                    DB::table('payroll_accounts')->where('id', $bound->id)->update(['enabled' => $shouldEnable, 'updated_at' => now()]);
                }
            }
            return response()->json(['ok' => true, 'id' => $id]);
        });
    }

    public function deduct(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $legacyId = (int) $request->input('staff_id');
        $staff = DB::table('payroll_staff')->where('legacy_id', $legacyId)->first();
        if (!$staff) {
            return response()->json(['ok' => false, 'error' => '人员不存在'], 404);
        }
        if ($this->isProjectScope($account) && $staff->project_name !== $account->project_name) {
            return response()->json(['ok' => false, 'error' => '无权操作其他项目人员'], 403);
        }
        $items = $request->input('items');
        if (!is_array($items)) {
            return response()->json(['ok' => false, 'error' => '扣除数据格式错误'], 400);
        }
        $data = $this->jsonValue($staff->data) ?: [];
        $data['special_deductions'] = $items;
        DB::table('payroll_staff')->where('legacy_id', $legacyId)->update([
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function salaryAdjust(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $id = (int) $request->input('staff_id');
        $staff = DB::table('payroll_staff')->where('legacy_id', $id)->first();
        if (!$staff) return response()->json(['ok' => false, 'error' => '人员不存在'], 404);
        if ($this->isProjectScope($account) && $staff->project_name !== $account->project_name) {
            return response()->json(['ok' => false, 'error' => '无权操作其他项目人员'], 403);
        }
        $effective = $this->dateValue($request->input('effective_date'));
        $fixed = (float) $request->input('fixed_monthly', 0);
        $base = (float) $request->input('base_salary', 0);
        if (!$effective || $fixed <= 0 || $base <= 0) {
            return response()->json(['ok' => false, 'error' => '请填写有效的生效日期、固定月薪和基本工资'], 400);
        }
        return DB::transaction(function () use ($request, $staff, $id, $effective, $fixed, $base, $account) {
            $data = $this->jsonValue($staff->data) ?: [];
            // 追加新生效段并补全变更前基线，保证月中调薪当月基本工资按段折算（生效日前不被新值覆盖）
            $data['salary_history'] = PayrollCalculator::appendSalarySegment(
                $data['salary_history'] ?? [],
                $staff->hire_date,
                (float) $staff->fixed_monthly, (float) $staff->base_salary,
                $effective, $fixed, $base,
                ['type' => $request->input('type', '调薪'), 'note' => $request->input('note', '')]
            );
            DB::table('payroll_staff')->where('legacy_id', $id)->update([
                'fixed_monthly' => $fixed, 'base_salary' => $base,
                'regular_date' => $request->input('type') === '转正' ? $effective : $staff->regular_date,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);
            DB::table('payroll_salary_adjustments')->insert([
                'staff_legacy_id' => $id, 'name' => $staff->name, 'project_name' => $staff->project_name,
                'type' => $request->input('type', '调薪'), 'effective_date' => $effective,
                'old_fixed' => $staff->fixed_monthly, 'old_base' => $staff->base_salary,
                'new_fixed' => $fixed, 'new_base' => $base, 'note' => $request->input('note', ''),
                'created_by' => $account->username, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['ok' => true]);
        });
    }

    /** 日期规范化：兼容 Excel 日期序列号、DateTime 对象、'2021-04-01 00:00:00'、'2021/4/1'、'2021年4月1日' 等 */
    private function normDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        if (is_object($value) && method_exists($value, 'format')) return $value->format('Y-m-d');
        $s = trim((string) $value);
        if ($s === '') return '';
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $s)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return $s;
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }
}
