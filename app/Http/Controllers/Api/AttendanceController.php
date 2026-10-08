<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceController extends ApiController
{
    /** 表头名 => 入库 record key（模板预填与上传解析共用，禁止两边各写一份导致漂移） */
    public const FIELD_MAP = [
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
            // 薪资口径：已锁定才算"已上传"
            'uploaded' => isset($records[$project->name]) && (bool) $records[$project->name]->locked,
            'has_data' => isset($records[$project->name]),
            'count' => isset($records[$project->name]) ? count($this->jsonValue($records[$project->name]->rows) ?: []) : 0,
            'uploaded_at' => isset($records[$project->name]) ? $records[$project->name]->updated_at : '',
            'locked' => isset($records[$project->name]) ? (bool) $records[$project->name]->locked : false,
            'locked_at' => isset($records[$project->name]) && $records[$project->name]->locked_at ? (string) $records[$project->name]->locked_at : '',
            'locked_by' => isset($records[$project->name]) ? (string) ($records[$project->name]->locked_by ?? '') : '',
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
        $dryRun = in_array(strtolower((string) $request->input('dry_run')), ['1', 'true', 'yes'], true);

        // 项目角色的 project 入口已被强制覆盖为自己的项目，虚拟哨兵到不了这里，只能走普通项目分支
        $group = \App\Services\PayrollCalculator::ATT_VIRTUAL_GROUPS[$project] ?? null;
        if ($group) {
            [$rows, $errors] = $this->parseWorkbook($request->file('file')->getPathname());
            if ($errors) {
                return response()->json(['ok' => false, 'error' => implode('；', array_slice($errors, 0, 30))], 400);
            }
            return $this->uploadVirtualGroup($ym, $group, $rows, $dryRun);
        }

        if (!DB::table('payroll_projects')->where('name', $project)->exists()) {
            return response()->json(['ok' => false, 'error' => '项目不存在'], 400);
        }
        if ($this->isArchived($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '该月工资已归档，不能重传考勤'], 400);
        }
        if ($this->isLocked($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '该项目考勤已锁定为最终版本，如需修改请先解锁'], 400);
        }
        [$rows, $errors] = $this->parseWorkbook($request->file('file')->getPathname());
        if ($errors) {
            return response()->json(['ok' => false, 'error' => implode('；', array_slice($errors, 0, 30))], 400);
        }
        // 离职人员不参与同名校验：与模板下载 resignGuard 一致口径
        // （status='离职' 且 resign_date 早于当月 1 日的人不进 staffRows，避免离职档案挡住同名在岗人员上传）
        $staffRows = DB::table('payroll_staff')->where('project_name', $project)->where('deleted', false)
            ->where(function ($q) use ($ym) {
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->get(['legacy_id', 'name', 'dingtalk_userid']);
        $staffNames = $staffRows->pluck('name')->all();
        $unknown = array_values(array_diff(array_keys($rows), $staffNames));
        if ($unknown) {
            return response()->json(['ok' => false, 'error' => '以下人员不在本项目人员档案中：' . implode('、', array_slice($unknown, 0, 10))], 400);
        }
        // 同名人员会让"按姓名匹配考勤"产生归属歧义（核算时无法确定考勤行是谁的），直接拒绝上传
        $dupNames = $staffRows->countBy('name')->filter(fn ($c) => $c > 1)->keys()->all();
        if ($dupNames) {
            return response()->json(['ok' => false, 'error' => '以下姓名在本项目人员档案中存在多条记录，按姓名匹配考勤会产生歧义，请先处理同名人员（钉钉同步后每人有唯一ID）：'
                . implode('、', array_slice($dupNames, 0, 10))], 400);
        }
        // 给每行考勤打上人员标记：核算时优先按 dingtalk_userid / staff_id 匹配，姓名仅作旧数据兜底
        $staffByName = $staffRows->keyBy('name');
        foreach ($rows as $name => $record) {
            $s = $staffByName[$name] ?? null;
            if ($s) {
                $rows[$name]['staff_id'] = (int) $s->legacy_id;
                $rows[$name]['dingtalk_userid'] = trim((string) ($s->dingtalk_userid ?? ''));
            }
        }
        $key = $ym . '|' . $project;
        $existing = DB::table('payroll_attendance')->where('record_key', $key)->first();
        $response = ['preview' => $dryRun, 'count' => count($rows), 'overwrite' => (bool) $existing,
            'names' => array_slice(array_keys($rows), 0, 50),
            'has_calc' => DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)->exists()];
        if (!$dryRun) {
            // 按人合并：只更新表中出现的人员，旧块里不在表中的行保留（防止重传冲掉汇总表/其他人数据；整表清空走删除按钮）
            $this->mergeBlock($ym, $project, $rows);
        }
        return response()->json(['ok' => true] + $response);
    }

    /**
     * 管理人员/案场人员汇总表上传：逐人按当月分类档案定位所属项目，
     * 分组后按人合并进各项目考勤块。任一涉及项目归档/锁定则整单拒绝，不做部分写入。
     */
    private function uploadVirtualGroup(string $ym, array $group, array $rows, bool $dryRun): JsonResponse
    {
        $label = $group['label']; $category = $group['category'];
        $catMap = \App\Services\PayrollCalculator::categoryMap($ym);
        $candidates = DB::table('payroll_staff')->where('deleted', false)
            ->where(function ($q) use ($ym) {
                // 虚拟组同样过滤离职（与项目分支、模板下载一致）
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->get(['legacy_id', 'name', 'project_name', 'dingtalk_userid'])
            ->filter(function ($p) use ($catMap, $category) {
                if (($catMap[(int) $p->legacy_id] ?? 'staff') !== $category) return false;
                // 物业总部人员（含管理人员标记）走物业总部自己的项目入口，避免重复数据源
                return !($category === 'manager' && $p->project_name === \App\Services\PayrollCalculator::HQ_PROJECT);
            })->values();

        $unknown = array_values(array_diff(array_keys($rows), $candidates->pluck('name')->all()));
        if ($unknown) {
            return response()->json(['ok' => false, 'error' => "以下人员不在全公司{$label}档案中（{$label}表只受理{$label}，基层/案场/物业总部人员请走各自项目表）："
                . implode('、', array_slice($unknown, 0, 10))], 400);
        }
        $byName = [];
        foreach ($candidates as $p) { $byName[$p->name][] = $p; }
        // 跨项目同名（含同项目多条档案）→ 按姓名无法定位归属，整单拒绝并给出项目消歧
        $ambiguous = [];
        foreach (array_keys($rows) as $nm) {
            if (count($byName[$nm] ?? []) > 1) {
                $projs = implode('、', array_values(array_unique(array_map(fn ($p) => $p->project_name, $byName[$nm]))));
                $ambiguous[] = "{$nm}（{$projs}）";
            }
        }
        if ($ambiguous) {
            return response()->json(['ok' => false, 'error' => "以下姓名在{$label}档案中存在多条记录，跨项目无法按姓名匹配，请先在人员档案中处理同名（钉钉同步后每人有唯一ID）："
                . implode('、', array_slice($ambiguous, 0, 10))], 400);
        }

        $byProject = [];
        foreach ($rows as $name => $record) {
            $p = $byName[$name][0];
            $record['staff_id'] = (int) $p->legacy_id;
            $record['dingtalk_userid'] = trim((string) ($p->dingtalk_userid ?? ''));
            $byProject[$p->project_name][$name] = $record;
        }
        foreach (array_keys($byProject) as $proj) {
            if ($this->isArchived($ym, $proj)) {
                return response()->json(['ok' => false, 'error' => "项目「{$proj}」{$ym}工资已归档，请先撤销该项目归档后再上传{$label}表"], 400);
            }
            if ($this->isLocked($ym, $proj)) {
                return response()->json(['ok' => false, 'error' => "项目「{$proj}」考勤已锁定为最终版本，请先解锁该项目后再上传{$label}表"], 400);
            }
        }

        $projects = array_keys($byProject);
        $existingKeys = DB::table('payroll_attendance')->where('year_month', $ym)
            ->whereIn('project_name', $projects)->pluck('project_name')->all();
        $response = ['preview' => $dryRun, 'count' => count($rows), 'overwrite' => (bool) $existingKeys,
            'names' => array_slice(array_keys($rows), 0, 50),
            'has_calc' => DB::table('payroll_results')->where('year_month', $ym)->whereIn('project_name', $projects)->exists()];
        if (!$dryRun) {
            foreach ($byProject as $proj => $incoming) {
                $this->mergeBlock($ym, $proj, $incoming);
            }
        }
        return response()->json(['ok' => true] + $response);
    }

    /**
     * 按人合并写入一个项目的考勤块：incoming 中的人覆盖/新增，
     * 旧块中不在 incoming 的人原样保留。locked 等字段不动（调用方需先做归档/锁定拦截）。
     */
    private function mergeBlock(string $ym, string $project, array $incoming): void
    {
        $key = $ym . '|' . $project;
        $existing = DB::table('payroll_attendance')->where('record_key', $key)->first();
        $oldRows = $existing ? ($this->jsonValue((string) $existing->rows) ?: []) : [];
        $merged = $incoming;
        foreach ($oldRows as $nm => $rec) {
            if (!array_key_exists($nm, $merged)) $merged[$nm] = $rec;
        }
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => $key],
            ['year_month' => $ym, 'project_name' => $project,
             'rows' => json_encode($merged, JSON_UNESCAPED_UNICODE),
             'data' => json_encode(['ym' => $ym, 'project' => $project, 'rows' => $merged], JSON_UNESCAPED_UNICODE),
             'updated_at' => now(), 'created_at' => now()]
        );
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
        if ($this->isLocked($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '该项目考勤已锁定，如需删除请先解锁'], 400);
        }
        DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->delete();
        return response()->json(['ok' => true]);
    }

    private function isArchived(string $ym, string $project): bool
    {
        return DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)->where('archived', true)->exists();
    }

    private function isLocked(string $ym, string $project): bool
    {
        return DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->where('locked', true)->exists();
    }

    /**
     * POST /api/attendance/lock
     * body: { ym, project, locked: true|false }
     */
    public function lock(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份格式错误'], 400);
        }
        $locked = (bool) $request->input('locked', true);
        $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->first();
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '该项目本月尚无考勤数据，无法锁定'], 400);
        }
        if (!$locked && $this->isArchived($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '工资已归档，不能解锁考勤'], 400);
        }
        DB::table('payroll_attendance')->where('id', $row->id)->update([
            'locked' => $locked,
            'locked_at' => $locked ? now() : null,
            'locked_by' => $locked ? ($account->name ?? $account->username ?? 'admin') : null,
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'locked' => $locked]);
    }

    /**
     * GET /api/attendance/export?ym=2026-09&project=XX&staff_type=XX
     * 导出已锁定考勤为 Excel。人员类型取 payroll_staff.person_type（钉钉花名册「岗位职级」同步）。
     */
    public function export(Request $request, \App\Services\PayrollCalculator $calc)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份格式错误'], 400);
        }
        $staffType = $request->string('staff_type')->toString();
        $staffTypeMap = ['管理人员' => 'manager', '基层人员' => 'staff', '案场人员' => 'case', '总部人员' => 'hq'];
        if ($staffType !== '' && !isset($staffTypeMap[$staffType])) {
            return response()->json(['ok' => false, 'error' => '人员类型参数错误'], 400);
        }
        $daysInMonth = (int) date('t', strtotime($ym . '-01'));

        if ($this->isProjectScope($account)) {
            $projects = [(string) $account->project_name];
        } else {
            $proj = trim((string) $request->string('project')->toString());
            $projects = $proj !== '' ? [$proj] :
                DB::table('payroll_projects')->where('status', '启用')->pluck('name')->all();
        }

        // 必须已锁定
        $notLocked = [];
        foreach ($projects as $p) {
            $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
            if (!$row || !(bool) $row->locked) $notLocked[] = $p;
        }
        if ($notLocked) {
            return response()->json(['ok' => false, 'error' => '以下项目考勤尚未锁定，不能导出：' . implode('、', $notLocked)], 400);
        }

        // 收集考勤数据
        $attByProject = [];
        foreach ($projects as $p) {
            $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
            $attByProject[$p] = $this->jsonValue($row->rows) ?: [];
        }

        // 查员工档案，按 person_type 筛选（与工资核算的管理/案场/总部/员工分类同源）
        $staffQuery = DB::table('payroll_staff')->whereIn('project_name', $projects)->where('deleted', false);
        if ($staffType !== '') {
            $staffQuery->where('person_type', $staffTypeMap[$staffType]);
        }
        $staff = $staffQuery->orderBy('project_name')->orderBy('position')->orderBy('name')->get();
        $filtered = [];
        foreach ($staff as $person) {
            $att = $attByProject[$person->project_name][$person->name] ?? null;
            if (!$att) continue;
            $person->_att = $att;
            $filtered[] = $person;
        }
        if (!$filtered) {
            return response()->json(['ok' => false, 'error' => '没有符合条件的考勤数据'], 404);
        }

        // 生成 Excel
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('考勤表');
        $baseCols = ['序号', '姓名', '人员状态', '岗位'];
        $dateCols = [];
        for ($d = 1; $d <= $daysInMonth; $d++) $dateCols[] = (string) $d;
        $statCols = ['应出勤(天)', '实际出勤(天)', '绩效系数', '事假(天)', '病假(天)', '产假(天)',
            '带薪假(天)', '缺卡(次)', '旷工(天)', '迟到(次)', '早退(次)'];
        $moneyCols = ['月度奖励', '月度扣罚', '餐补', '夜班/话费补贴', '职称/证书补贴',
            '养老保险', '医疗保险', '失业保险', '住房公积金', '大病', '其他扣款', '工装扣款'];
        $tailCols = ['备注'];
        $headers = array_merge($baseCols, $dateCols, $statCols, $moneyCols, $tailCols);
        $lastCol = count($headers);
        $lastLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);
        $dateStart = count($baseCols) + 1;
        $dateEnd = count($baseCols) + count($dateCols);
        $statStart = $dateEnd + 1;
        $moneyStart = $statEnd = $dateEnd + count($statCols) + 1;
        $tailStart = $moneyStart + count($moneyCols);

        $projLabel = count($projects) > 1 ? '全部项目' : $projects[0];
        $typeLabel = $staffType !== '' ? $staffType : '全部人员';
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', "【{$projLabel}】" . substr($ym,0,4) . '年' . (int)substr($ym,5,2) . "月考勤表（{$typeLabel}）");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->fromArray($headers, null, 'A2');
        $sheet->getStyle("A2:{$lastLetter}2")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("A2:{$lastLetter}2")->getAlignment()->setHorizontal('center')->setWrapText(true);
        $sheet->getStyle("A2:{$lastLetter}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5496');
        $sheet->getStyle("A2:{$lastLetter}2")->getFont()->getColor()->setRGB('FFFFFF');

        $symbols = \App\Services\PayrollCalculator::symbols();
        $row = 3;
        foreach ($filtered as $i => $person) {
            $att = $person->_att;
            $stats = \App\Services\PayrollCalculator::attendanceStats($att['days'] ?? [], $symbols);
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $person->name);
            $sheet->setCellValue("C{$row}", $person->status ?: '正式');
            $sheet->setCellValue("D{$row}", $person->position ?: '');
            foreach (($att['days'] ?? []) as $dayIdx => $sym) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateStart + $dayIdx);
                $sheet->setCellValue("{$col}{$row}", $sym);
            }
            $c = fn($off) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + $off);
            $sheet->setCellValue("{$c(0)}{$row}", $att['req_attend'] ?? $stats['required'] ?? 0);
            $sheet->setCellValue("{$c(1)}{$row}", $att['act_attend'] ?? $stats['attend'] ?? 0);
            $sheet->setCellValue("{$c(2)}{$row}", $att['coef'] ?? 1);
            $sheet->setCellValue("{$c(3)}{$row}", $stats['personal']);
            $sheet->setCellValue("{$c(4)}{$row}", $stats['sick']);
            $sheet->setCellValue("{$c(5)}{$row}", $stats['maternity']);
            $sheet->setCellValue("{$c(6)}{$row}", $stats['paid']);
            $sheet->setCellValue("{$c(7)}{$row}", $stats['miss']);
            $sheet->setCellValue("{$c(8)}{$row}", $stats['absent']);
            $sheet->setCellValue("{$c(9)}{$row}", $stats['late']);
            $sheet->setCellValue("{$c(10)}{$row}", $stats['early']);
            $m = fn($off) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStart + $off);
            $sheet->setCellValue("{$m(0)}{$row}", (float)($att['reward'] ?? 0));
            $sheet->setCellValue("{$m(1)}{$row}", (float)($att['punish'] ?? 0));
            $sheet->setCellValue("{$m(2)}{$row}", (float)($att['meal_sub'] ?? 0));
            $sheet->setCellValue("{$m(3)}{$row}", (float)($att['night_sub'] ?? 0));
            $sheet->setCellValue("{$m(4)}{$row}", (float)($att['title_sub'] ?? 0));
            $sheet->setCellValue("{$m(5)}{$row}", (float)($att['pen'] ?? 0));
            $sheet->setCellValue("{$m(6)}{$row}", (float)($att['med'] ?? 0));
            $sheet->setCellValue("{$m(7)}{$row}", (float)($att['une'] ?? 0));
            $sheet->setCellValue("{$m(8)}{$row}", (float)($att['house'] ?? 0));
            $sheet->setCellValue("{$m(9)}{$row}", (float)($att['big'] ?? 0));
            $sheet->setCellValue("{$m(10)}{$row}", (float)($att['other_deduct'] ?? 0));
            $sheet->setCellValue("{$m(11)}{$row}", (float)($att['uniform_deduct'] ?? 0));
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($tailStart) . $row, (string)($att['remark'] ?? ''));
            $row++;
        }
        $sheet->getStyle("A2:{$lastLetter}" . ($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->freezePane('E3');
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateStart + $d - 1))->setWidth(3.4);
        }

        $filename = "考勤导出_{$projLabel}_{$ym}_{$typeLabel}.xlsx";
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        return response()->streamDownload(fn() => fpassthru($stream), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
        $__cfSnap = \Illuminate\Support\Facades\DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $__cfPayload = $__cfSnap ? (json_decode((string)$__cfSnap->payload, true) ?: []) : [];
        $__cfFields = $__cfPayload['rules']['custom_fields'] ?? [];
        $dynamicMap = self::FIELD_MAP;
        foreach ($__cfFields as $__cf) { if (!empty($__cf['enabled'])) $dynamicMap[$__cf['name']] = 'cf_' . $__cf['name']; }
        for ($col = 1; $col <= $highestColumn; $col++) {
            $value = str_replace(["\n", "\r"], '', trim((string) $this->cellValue($sheet, $col, $headerRow)));
            if (preg_match('/^\d{1,2}$/', $value) && (int) $value >= 1 && (int) $value <= 31) $days[(int) $value] = $col;
            if (isset($dynamicMap[$value])) $columns[$dynamicMap[$value]] = $col;
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
                if ($symbol !== '' && !\App\Services\PayrollCalculator::symbolLookup($symbol, $symbols)) $errors[] = "第{$row}行第{$day}日符号无效：{$symbol}";
                $daysData[] = $symbol;
            }
            $record = ['days' => $daysData];
            foreach ($dynamicMap as $key) {
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
