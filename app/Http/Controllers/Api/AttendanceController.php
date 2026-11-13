<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceController extends ApiController
{
    /** 表头名 => 入库 record key（模板预填与上传解析共用，禁止两边各写一份导致漂移） */
    public const FIELD_MAP = [
        '姓名' => 'name', '员工工号' => 'emp_no', '人员状态' => 'status', '员工状态' => 'status', '岗位' => 'position',
        '应出勤(天)' => 'req_attend', '应出勤(手填)' => 'req_attend', '实际出勤' => 'act_attend', '实际出勤(天)' => 'act_attend',
        // 考勤统计列（模板 COUNTIF 公式列）：上传时一并落库，核算/导出/页面优先读存值
        '事假(天)' => 'stat_personal', '病假(天)' => 'stat_sick', '产假(天)' => 'stat_maternity',
        '带薪假(天)' => 'stat_paid', '缺卡(次)' => 'stat_miss', '旷工(天)' => 'stat_absent',
        '迟到(次)' => 'stat_late', '早退(次)' => 'stat_early',
        '月度奖励' => 'reward', '月度扣罚' => 'punish',
        '月度奖励金额' => 'reward', '月度扣罚金额' => 'punish', // 旧表头兼容
        '餐补' => 'meal_sub',
        '夜班/话餐补贴' => 'night_sub', '夜班/话费补贴' => 'night_sub', '职称/证书补贴' => 'title_sub', '养老保险' => 'pen',
        '医疗保险' => 'med', '失业保险' => 'une', '住房公积金' => 'house', '大病' => 'big',
        '缺卡扣款' => 'miss_deduct', '迟到早退扣款' => 'late_deduct', '其他扣款' => 'other_deduct',
        '工装扣款' => 'uniform_deduct', '绩效系数' => 'coef', '月度绩效' => 'coef', '月度绩效系数' => 'coef', '备注' => 'remark',
        '已发福利/奖励' => 'welfare', '补发工资' => 'backpay',
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
        // 虚拟汇总选项（管理人员/案场人员）当月是否有数据：控制前端查看/导出按钮按月置灰
        $virtual = [];
        foreach (\App\Services\PayrollCalculator::ATT_VIRTUAL_GROUPS as $vKey => $g) {
            $virtual[$vKey] = $this->virtualGroupHasData($ym, $g);
        }
        return response()->json(['ok' => true, 'ym' => $ym, 'projects' => $result, 'virtual' => $virtual]);
    }

    /**
     * 当月某类人员名单（按项目分组）：与虚拟组查看/上传同口径
     * （payroll_staff 在职、按月 categoryMap 分类、管理人员不含物业总部）。
     * 返回 [项目名 => [姓名 => true]]，供虚拟组查看/上传/删除共用。
     */
    private function virtualGroupNames(string $ym, string $category): array
    {
        $catMap = \App\Services\PayrollCalculator::categoryMap($ym);
        $staff = DB::table('payroll_staff')->where('deleted', false)
            ->where(function ($q) use ($ym) {
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->get(['legacy_id', 'name', 'project_name'])
            ->filter(function ($p) use ($catMap, $category) {
                if (($catMap[(int) $p->legacy_id] ?? 'staff') !== $category) return false;
                return !($category === 'manager' && $p->project_name === \App\Services\PayrollCalculator::HQ_PROJECT);
            });
        $byProject = [];
        foreach ($staff as $p) { $byProject[$p->project_name][$p->name] = true; }
        return $byProject;
    }

    /** 虚拟组当月是否有考勤数据：与 attendanceVirtualGroup 同口径（按月 categoryMap 分类、管理人员不含物业总部、按姓名匹配块内行） */
    private function virtualGroupHasData(string $ym, array $group): bool
    {
        $namesByProject = $this->virtualGroupNames($ym, $group['category']);
        if (!$namesByProject) return false;
        foreach ($namesByProject as $proj => $names) {
            $record = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $proj)->first();
            if (!$record) continue;
            foreach (($this->jsonValue($record->rows) ?: []) as $name => $att) {
                if (isset($names[$name])) return true;
            }
        }
        return false;
    }

    public function upload(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        // 按文件名扩展名校验：mimes 规则基于 MIME 内容探测反推扩展名，WPS 等工具生成的 xlsx
        // 会被识别为 application/zip 而误拒；真实格式是否有效交给 parseWorkbook 的解析层把关
        $request->validate(['ym' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'project' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'max:51200', function ($attr, $value, $fail) {
                $ext = strtolower($value->getClientOriginalExtension());
                if (!in_array($ext, ['xlsx', 'xls'], true)) $fail('考勤表仅支持 xlsx / xls 格式');
            }]]);
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        $dryRun = in_array(strtolower((string) $request->input('dry_run')), ['1', 'true', 'yes'], true);
        // 统计列与格子数不一致时默认整单拒绝；管理员显式勾选"仍然上传"后才放行（并返回 warnings）
        $forceStats = $request->boolean('force_stats');
        // 拒单报错带上文件/项目/月份，便于用户定位是哪张表哪个月出的问题
        $ctx = '文件 ' . $request->file('file')->getClientOriginalName() . ' ｜ 项目 ' . $project . ' ｜ 月份 ' . $ym . '：';

        // 项目角色的 project 入口已被强制覆盖为自己的项目，虚拟哨兵到不了这里，只能走普通项目分支
        $group = \App\Services\PayrollCalculator::ATT_VIRTUAL_GROUPS[$project] ?? null;
        if ($group) {
            [$rows, $errors, $warnings] = $this->parseWorkbook($request->file('file')->getPathname(), $forceStats);
            if ($errors) {
                return $this->uploadReject($ctx, $errors);
            }
            return $this->uploadVirtualGroup($ym, $group, $rows, $dryRun, $warnings);
        }

        if (!DB::table('payroll_projects')->where('name', $project)->exists()) {
            return $this->uploadReject($ctx, [$this->noticeItem('project_missing', '项目不存在')]);
        }
        if ($this->isArchived($ym, $project)) {
            return $this->uploadReject($ctx, [$this->noticeItem('archived', '该月工资已归档，不能重传考勤')]);
        }
        if ($this->isLocked($ym, $project)) {
            return $this->uploadReject($ctx, [$this->noticeItem('locked', '该项目考勤已锁定为最终版本，如需修改请先解锁')]);
        }
        [$rows, $errors, $warnings] = $this->parseWorkbook($request->file('file')->getPathname(), $forceStats);
        if ($errors) {
            return $this->uploadReject($ctx, $errors);
        }
        // 离职人员不参与同名校验：与模板下载 resignGuard 一致口径
        // （status='离职' 且 resign_date 早于当月 1 日的人不进 staffRows，避免离职档案挡住同名在岗人员上传）
        $staffRows = DB::table('payroll_staff')->where('project_name', $project)->where('deleted', false)
            ->where(function ($q) use ($ym) {
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->get(['legacy_id', 'name', 'dingtalk_userid', 'status', 'resign_date']);
        $staffNames = $staffRows->pluck('name')->all();
        $unknown = array_values(array_diff(array_keys($rows), $staffNames));
        if ($unknown) {
            $items = [];
            foreach (array_slice($unknown, 0, 10) as $n) {
                $items[] = $this->noticeItem('unknown_staff', "人员不在本项目人员档案中：{$n}", '不在本项目人员档案中', ['name' => $n]);
            }
            return $this->uploadReject($ctx, $items);
        }
        // 同名人员会让"按姓名匹配考勤"产生归属歧义（核算时无法确定考勤行是谁的），直接拒绝上传
        $dupNames = $staffRows->countBy('name')->filter(fn ($c) => $c > 1)->keys()->all();
        if ($dupNames) {
            $items = [];
            foreach (array_slice($dupNames, 0, 10) as $n) {
                $items[] = $this->noticeItem('dup_staff', "姓名在本项目人员档案中存在多条记录，按姓名匹配考勤会产生歧义：{$n}",
                    '档案中存在多条记录，按姓名匹配有歧义，请先处理同名人员', ['name' => $n]);
            }
            return $this->uploadReject($ctx, $items);
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
        // 本月离职人员不阻断上传，仅作为提示（离职当月仍可能有考勤）
        $infoItems = $this->resignedInfos($ym, array_keys($rows), $staffByName);
        $key = $ym . '|' . $project;
        $existing = DB::table('payroll_attendance')->where('record_key', $key)->first();
        // 季度绩效法人员不得在考勤表带系数（系数走季度末月弹窗），整单拒绝、不部分写入
        if ($badCoef = $this->guardQuarterCoefItems($ym, $rows)) {
            return $this->uploadReject($ctx, $badCoef);
        }
        $response = ['preview' => $dryRun, 'count' => count($rows), 'overwrite' => (bool) $existing,
            'names' => array_slice(array_keys($rows), 0, 50),
            'has_calc' => DB::table('payroll_results')->where('year_month', $ym)->where('project_name', $project)->exists()];
        if (!$dryRun) {
            // 整表覆盖：新表完全替换旧块，旧表有而新表没有的人员行随之移除（2026-10-18 用户口径）
            $this->overwriteBlock($ym, $project, $rows);
        }
        return $this->uploadOk($response, $warnings, $infoItems);
    }

    /**
     * 管理人员/案场人员汇总表上传：逐人按当月分类档案定位所属项目，
     * 分组后按人合并进各项目考勤块。任一涉及项目归档/锁定则整单拒绝，不做部分写入。
     */
    private function uploadVirtualGroup(string $ym, array $group, array $rows, bool $dryRun, array $warnings = []): JsonResponse
    {
        $label = $group['label']; $category = $group['category'];
        $catMap = \App\Services\PayrollCalculator::categoryMap($ym);
        $candidates = DB::table('payroll_staff')->where('deleted', false)
            ->where(function ($q) use ($ym) {
                // 虚拟组同样过滤离职（与项目分支、模板下载一致）
                $q->where(function ($qq) { $qq->where('status', '!=', '离职')->whereNull('resign_date'); })
                  ->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->get(['legacy_id', 'name', 'project_name', 'dingtalk_userid', 'status', 'resign_date'])
            ->filter(function ($p) use ($catMap, $category) {
                if (($catMap[(int) $p->legacy_id] ?? 'staff') !== $category) return false;
                // 物业总部人员（含管理人员标记）走物业总部自己的项目入口，避免重复数据源
                return !($category === 'manager' && $p->project_name === \App\Services\PayrollCalculator::HQ_PROJECT);
            })->values();

        $unknown = array_values(array_diff(array_keys($rows), $candidates->pluck('name')->all()));
        if ($unknown) {
            $items = [];
            foreach (array_slice($unknown, 0, 10) as $n) {
                $items[] = $this->noticeItem('unknown_staff', "人员不在全公司{$label}档案中：{$n}",
                    "不在全公司{$label}档案中（基层/案场/物业总部人员请走各自项目表）", ['name' => $n]);
            }
            return $this->uploadReject("", $items);
        }
        $byName = [];
        foreach ($candidates as $p) { $byName[$p->name][] = $p; }
        // 跨项目同名（含同项目多条档案）→ 按姓名无法定位归属，整单拒绝并给出项目消歧
        $ambiguous = [];
        foreach (array_keys($rows) as $nm) {
            if (count($byName[$nm] ?? []) > 1) {
                $projs = implode('、', array_values(array_unique(array_map(fn ($p) => $p->project_name, $byName[$nm]))));
                $ambiguous[] = $this->noticeItem('dup_staff', "姓名在{$label}档案中存在多条记录，跨项目无法按姓名匹配：{$nm}（{$projs}）",
                    "档案中存在多条记录（{$projs}），请先处理同名", ['name' => $nm]);
            }
        }
        if ($ambiguous) {
            return $this->uploadReject("", $ambiguous);
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
                return $this->uploadReject("", [$this->noticeItem('archived', "项目「{$proj}」{$ym}工资已归档，请先撤销该项目归档后再上传{$label}表")]);
            }
            if ($this->isLocked($ym, $proj)) {
                return $this->uploadReject("", [$this->noticeItem('locked', "项目「{$proj}」考勤已锁定为最终版本，请先解锁该项目后再上传{$label}表")]);
            }
        }
        // 季度绩效法人员不得在考勤表带系数（系数走季度末月弹窗），整单拒绝、不部分写入
        foreach ($byProject as $incoming) {
            if ($badCoef = $this->guardQuarterCoefItems($ym, $incoming)) {
                return $this->uploadReject("", $badCoef);
            }
        }
        $infoItems = $this->resignedInfos($ym, array_keys($rows), collect($candidates->all())->keyBy('name'));

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
        return $this->uploadOk($response, $warnings, $infoItems);
    }

    /**
     * 按人合并写入一个项目的考勤块：仅虚拟组（管理人员/案场人员）汇总表上传使用——
     * 表中只含该类人员，incoming 中的人覆盖/新增，块内其他人员的行必须原样保留。
     * locked 等字段不动（调用方需先做归档/锁定拦截）。
     */
    private function mergeBlock(string $ym, string $project, array $incoming): void
    {
        $key = $ym . '|' . $project;
        $existing = DB::table('payroll_attendance')->where('record_key', $key)->first();
        $oldRows = $existing ? ($this->jsonValue((string) $existing->rows) ?: []) : [];
        $merged = $incoming;
        // 未参与本次上传的旧人员按原行序追加（旧块可能是 MySQL JSON，键序已乱，需用 data.order 还原）
        foreach (\App\Services\PayrollCalculator::attendanceOrder($existing, $oldRows) as $nm) {
            if (!array_key_exists($nm, $merged)) $merged[$nm] = $oldRows[$nm];
        }
        $this->overwriteBlock($ym, $project, $merged);
    }

    /**
     * 季度绩效法人员禁止通过考勤表上传绩效系数（2026-10-21 用户口径：只能走季度末月弹窗录入）。
     * 模板该列默认预填 1.0（中性值，等价于未录入），故仅拿「非 1.0 的值」判定为违规；
     * 返回违规条目数组，全部合规返回空数组。
     */
    private function guardQuarterCoefItems(string $ym, array $rows): array
    {
        $rules = new \App\Services\CalcRules();
        $catMap = \App\Services\PayrollCalculator::categoryMap($ym);
        $items = [];
        foreach ($rows as $name => $rec) {
            $coef = $rec['coef'] ?? null;
            if ($coef === null || (float) $coef === 1.0) continue;
            $sid = (int) ($rec['staff_id'] ?? 0);
            if ($sid <= 0) continue;
            $cycle = $rules->getPayRule($catMap[$sid] ?? 'staff')['cycle'] ?? 'monthly';
            if ($cycle !== 'monthly') {
                $items[] = $this->noticeItem('quarter_coef',
                    "{$name}（{$coef}）为季度绩效法，绩效系数不能在考勤表上传（请把「绩效系数」列留空或填 1）",
                    '季度绩效法人员，绩效系数须走季度末月弹窗录入（该列请留空或填 1）',
                    ['name' => $name]);
            }
        }
        return $items;
    }

    /** 整表覆盖写入一个项目的考勤块：新表完全替换旧数据（调用方需先做归档/锁定拦截） */
    private function overwriteBlock(string $ym, string $project, array $rows): void
    {
        $key = $ym . '|' . $project;
        DB::table('payroll_attendance')->updateOrInsert(
            ['record_key' => $key],
            ['year_month' => $ym, 'project_name' => $project,
             'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
             // order：上传表格行序。MySQL JSON 会重排对象键，rows 键序无法保证显示/导出顺序
             'data' => json_encode(['ym' => $ym, 'project' => $project, 'rows' => $rows, 'order' => array_keys($rows)], JSON_UNESCAPED_UNICODE),
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
        $isProject = $this->isProjectScope($account);
        $project = $isProject ? (string) $account->project_name : $request->string('project')->toString();

        // 虚拟汇总选项（管理员）：从各项目考勤块中剔除该类人员的行，其他人员数据保留
        $group = $isProject ? null : (\App\Services\PayrollCalculator::ATT_VIRTUAL_GROUPS[$project] ?? null);
        if ($group) {
            return $this->deleteVirtualGroup($ym, $group, $account);
        }

        if ($this->isArchived($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '已归档月份不能删除考勤'], 400);
        }
        if ($this->isLocked($ym, $project)) {
            return response()->json(['ok' => false, 'error' => '该项目考勤已锁定，如需删除请先解锁'], 400);
        }
        $block = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->first();
        $rowCount = $block ? count($this->jsonValue((string) $block->rows) ?: []) : 0;
        DB::table('payroll_attendance')->where('record_key', $ym . '|' . $project)->delete();
        $stats = ['blocks' => $block ? 1 : 0, 'rows' => $rowCount, 'projects' => [$project]];
        $this->writeDeleteLog($ym, $account, "考勤（{$project}）", $stats);
        return response()->json(['ok' => true, 'stats' => ['blocks' => $stats['blocks'], 'rows' => $stats['rows']]]);
    }

    /**
     * 虚拟组删除（管理人员/案场人员）：逐项目把该类人员的行从考勤块中剔除，
     * 其他人员的行原样保留；某项目块删空则整块删除。
     * 有任一涉及项目锁定/归档时整体拒绝，不做部分删除。
     */
    private function deleteVirtualGroup(string $ym, array $group, object $account): JsonResponse
    {
        $label = $group['label'];
        $namesByProject = $this->virtualGroupNames($ym, $group['category']);
        if (!$namesByProject) {
            return response()->json(['ok' => false, 'error' => "本月暂无{$label}考勤数据"], 400);
        }
        $archivedProjects = []; $lockedProjects = []; $targets = [];
        foreach ($namesByProject as $proj => $names) {
            $record = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $proj)->first();
            if (!$record) continue;
            if ($this->isArchived($ym, $proj)) { $archivedProjects[] = $proj; continue; }
            if ((bool) $record->locked) { $lockedProjects[] = $proj; continue; }
            $targets[$proj] = $record;
        }
        if ($archivedProjects) {
            return response()->json(['ok' => false, 'error' => '以下项目该月工资已归档，不能删除考勤：' . implode('、', $archivedProjects)], 400);
        }
        if ($lockedProjects) {
            return response()->json(['ok' => false, 'error' => '以下项目考勤已锁定，请先解锁后再删除：' . implode('、', $lockedProjects)], 400);
        }
        $stats = DB::transaction(function () use ($ym, $namesByProject, $targets) {
            $blocks = 0; $rows = 0; $names = [];
            foreach ($targets as $proj => $record) {
                $rowsData = $this->jsonValue((string) $record->rows) ?: [];
                $kept = [];
                foreach (\App\Services\PayrollCalculator::attendanceOrder($record, $rowsData) as $nm) {
                    $rec = $rowsData[$nm];
                    if (isset($namesByProject[$proj][$nm])) { $rows++; $names[] = $nm; }
                    else { $kept[$nm] = $rec; }
                }
                if (count($kept) === count($rowsData)) continue;
                $blocks++;
                if ($kept) {
                    DB::table('payroll_attendance')->where('id', $record->id)->update([
                        'rows' => json_encode($kept, JSON_UNESCAPED_UNICODE),
                        'data' => json_encode(['ym' => $ym, 'project' => $proj, 'rows' => $kept, 'order' => array_keys($kept)], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('payroll_attendance')->where('id', $record->id)->delete();
                }
            }
            return ['blocks' => $blocks, 'rows' => $rows, 'names' => $names];
        });
        if ($stats['blocks'] === 0) {
            return response()->json(['ok' => false, 'error' => "本月暂无{$label}考勤数据"], 400);
        }
        $this->writeDeleteLog($ym, $account, "考勤（全公司{$label}）", $stats + ['projects' => array_keys($targets)]);
        return response()->json(['ok' => true, 'stats' => ['blocks' => $stats['blocks'], 'rows' => $stats['rows']]]);
    }

    /** 考勤删除审计日志：写入 payroll_delete_logs（与薪资删除同一入口，scope_label 区分），日志失败不影响主流程 */
    private function writeDeleteLog(string $ym, object $account, string $label, array $stats): void
    {
        $names = $stats['names'] ?? [];
        unset($stats['names']);
        $stats['scope_type'] = 'attendance';
        $stats['scope_label'] = $label;
        $stats['deleted_names'] = $names
            ? implode('、', array_slice($names, 0, 10)) . (count($names) > 10 ? ' 等' . count($names) . '人' : '')
            : '';
        try {
            DB::table('payroll_delete_logs')->insert([
                'ym' => $ym,
                'scope_info' => json_encode($stats, JSON_UNESCAPED_UNICODE),
                'operator' => (string) ($account->name ?? $account->username ?? 'admin'),
                'operator_role' => (string) ($account->role ?? ''),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('attendance.delete.log_failed', ['ym' => $ym, 'err' => $e->getMessage()]);
        }
        \Illuminate\Support\Facades\Log::info('attendance.delete', ['ym' => $ym, 'label' => $label, 'operator' => $account->username ?? '', 'stats' => $stats]);
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
    public function export(Request $request, \App\Services\AttendanceExportService $service)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份格式错误'], 400);
        }
        $staffType = $request->string('staff_type')->toString();
        // 总部人员不在导出选项内：物业总部考勤走"项目=物业总部"整体导出（2026-10-18 用户口径）
        $staffTypeMap = ['管理人员' => 'manager', '基层人员' => 'staff', '案场人员' => 'case'];
        if ($staffType !== '' && !isset($staffTypeMap[$staffType])) {
            return response()->json(['ok' => false, 'error' => '人员类型参数错误'], 400);
        }

        $proj = '';
        if ($this->isProjectScope($account)) {
            $projects = [(string) $account->project_name];
        } else {
            $proj = trim((string) $request->string('project')->toString());
            // 虚拟汇总选项：映射为对应类别的全项目导出
            if ($proj === '__managers__' || $proj === '__case__') {
                $staffType = $proj === '__managers__' ? '管理人员' : '案场人员';
                $projects = DB::table('payroll_projects')->where('status', '启用')->pluck('name')->all();
            } else {
                $projects = $proj !== '' ? [$proj] :
                    DB::table('payroll_projects')->where('status', '启用')->pluck('name')->all();
            }
        }

        // 虚拟汇总选项放宽锁定校验：只要有一个项目有数据即可导出，未锁定的项目自动跳过
        $isVirtualGroup = $proj === '__managers__' || $proj === '__case__';
        if (!$isVirtualGroup) {
            // 必须已锁定（单项目导出保持原逻辑）
            $notLocked = [];
            foreach ($projects as $p) {
                $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
                if (!$row || !(bool) $row->locked) $notLocked[] = $p;
            }
            if ($notLocked) {
                return response()->json(['ok' => false, 'error' => '以下项目考勤尚未锁定，不能导出：' . implode('、', $notLocked)], 400);
            }
        } else {
            // 虚拟组：只保留有数据的项目，无数据/未锁定的项目静默跳过
            $projectsWithData = [];
            foreach ($projects as $p) {
                $row = DB::table('payroll_attendance')->where('record_key', $ym . '|' . $p)->first();
                if ($row && $row->rows) {
                    $projectsWithData[] = $p;
                }
            }
            if (empty($projectsWithData)) {
                return response()->json(['ok' => false, 'error' => '全公司暂无' . ($proj === '__managers__' ? '管理人员' : '案场人员') . '的考勤数据'], 404);
            }
            $projects = $projectsWithData;
        }

        // 取数与写表统一走 AttendanceExportService：数据导出模块复用同一实现，避免列序/口径漂移
        $category = $staffType !== '' ? $staffTypeMap[$staffType] : '';
        $prepared = $service->prepare($ym, $projects, $category, [
            'use_month_category' => $isVirtualGroup, // 虚拟汇总沿用按月分类；单项目沿用 person_type 当前值
            'skip_unlocked' => false,                // 锁定校验已在上方完成
        ]);
        $filtered = [];
        foreach ($projects as $p) {
            foreach ($prepared['projects'][$p]['people'] ?? [] as $person) $filtered[] = $person;
        }
        if (!$filtered) {
            return response()->json(['ok' => false, 'error' => '没有符合条件的考勤数据'], 404);
        }

        $projLabel = $isVirtualGroup
            ? ($proj === '__managers__' ? '管理人员' : '案场人员')
            : (count($projects) > 1 ? '全部项目' : $projects[0]);
        $typeLabel = $staffType !== '' ? $staffType : '全部人员';

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('考勤表');
        $service->writeSheet($sheet, $filtered, $ym, $projLabel, count($projects) > 1, $typeLabel);

        $filename = "考勤导出_{$projLabel}_{$ym}_{$typeLabel}.xlsx";
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        return response()->streamDownload(fn() => fpassthru($stream), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * 解析考勤表。返回 [行数据, 错误, 告警]：
     *  - 统计列与"按符号库数出来的值"不一致默认进错误（整单拒绝）；
     *  - $forceStats=true 时降级为告警（管理员显式选择"仍然上传"）。
     */
    private function parseWorkbook(string $path, bool $forceStats = false): array
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            return [[], [$this->noticeItem('file_parse', '文件解析失败，请确认上传的是有效的考勤表 Excel 文件（xls / xlsx）')], []];
        }
        $headerRow = null; $columns = []; $days = [];
        $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($row = 1; $row <= min(8, $sheet->getHighestRow()); $row++) {
            for ($col = 1; $col <= min(20, $highestColumn); $col++) {
                if (trim((string) $this->cellValue($sheet, $col, $row)) === '姓名') {
                    $headerRow = $row; break 2;
                }
            }
        }
        if (!$headerRow) return [[], [$this->noticeItem('template_structure', '未找到“姓名”表头')], []];
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
        if (!isset($columns['name']) || count($days) < 28) return [[], [$this->noticeItem('template_structure', '模板列结构不完整')], []];
        $symbols = \App\Services\PayrollCalculator::symbols(); $rows = []; $errors = []; $warnings = [];
        // 统计列与格子数一致性校验：实际出勤与各假别列在模板里是 COUNTIF 公式，正常应与符号库数出来的一致；
        // 人手改过或换过符号库就会不一致，此时按"列值"算工资会与考勤表格子对不上，默认整单拒绝。
        // 「应出勤(手填)」不参与本校验：它是人力按当月标准工作日手填的月级值（模板该列无公式），
        // 与格子中 in_required 符号数本来就可能不同；仍照常解析落库 req_attend，核算/导出/页面口径不变。
        $statChecks = [
            'act_attend' => ['label' => '实际出勤', 'stat' => 'attend'],
            'stat_personal' => ['label' => '事假', 'stat' => 'personal'],
            'stat_sick' => ['label' => '病假', 'stat' => 'sick'],
            'stat_maternity' => ['label' => '产假', 'stat' => 'maternity'],
            'stat_paid' => ['label' => '带薪假', 'stat' => 'paid'],
            'stat_miss' => ['label' => '缺卡', 'stat' => 'miss'],
            'stat_absent' => ['label' => '旷工', 'stat' => 'absent'],
            'stat_late' => ['label' => '迟到', 'stat' => 'late'],
            'stat_early' => ['label' => '早退', 'stat' => 'early'],
        ];
        for ($row = $headerRow + 1; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $this->cellValue($sheet, $columns['name'], $row));
            if ($name === '' || $name === '星期') continue;
            if (in_array($name, ['合计', '小计'], true)) break;
            if (isset($rows[$name])) {
                $errors[] = $this->noticeItem('dup_name', "第{$row}行姓名重复：{$name}", '姓名重复，无法确定归属', ['row' => $row, 'name' => $name]);
                continue;
            }
            $daysData = [];
            for ($day = 1; $day <= 31; $day++) {
                $value = $days[$day] ?? null;
                $symbol = $value ? trim((string) $this->cellValue($sheet, $value, $row)) : '';
                if ($symbol !== '' && !\App\Services\PayrollCalculator::symbolLookup($symbol, $symbols)) {
                    $errors[] = $this->noticeItem('invalid_symbol', "第{$row}行第{$day}日符号无效：{$symbol}",
                        "第{$day}日符号「{$symbol}」不在符号库中", ['row' => $row, 'day' => $day, 'name' => $name]);
                }
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
                if (in_array($key, ['status', 'position', 'emp_no', 'remark'], true)) {
                    $record[$key] = trim((string) ($value ?? ''));
                } elseif ($value === null || (is_string($value) && trim($value) === '')) {
                    // 空单元格存 null（缺失语义）：核算时回退数格子，绝不把"没填"写成 0 当作真实 0 天
                    $record[$key] = null;
                } else {
                    $record[$key] = round((float) $value, 2);
                }
            }
            // 逐列比对统计列与符号统计（只比对表里实际存在的列；空值视为未填，不参与比对）
            $grid = \App\Services\PayrollCalculator::attendanceStats($daysData, $symbols);
            foreach ($statChecks as $key => $chk) {
                if (!array_key_exists($key, $record) || $record[$key] === null) continue;
                $filled = (float) $record[$key];
                $counted = (float) ($grid[$chk['stat']] ?? 0);
                if (abs($filled - $counted) <= 0.001) continue;
                $msg = "第{$row}行 {$name}：{$chk['label']}列填 " . rtrim(rtrim(number_format($filled, 2, '.', ''), '0'), '.')
                    . '，按符号统计为 ' . rtrim(rtrim(number_format($counted, 2, '.', ''), '0'), '.');
                $item = $this->noticeItem('stat_mismatch', $msg,
                    "{$chk['label']}列填 " . rtrim(rtrim(number_format($filled, 2, '.', ''), '0'), '.')
                    . '，按符号统计为 ' . rtrim(rtrim(number_format($counted, 2, '.', ''), '0'), '.'),
                    ['row' => $row, 'name' => $name, 'field' => $key, 'label' => $chk['label'],
                     'filled' => $filled, 'counted' => $counted]);
                if ($forceStats) {
                    $warnings[] = $item;
                } else {
                    $errors[] = $item;
                }
            }
            $rows[$name] = $record;
        }
        return [$rows, $errors, $warnings];
    }

    /**
     * 统一的上传提示条目：message 用于兼容旧的 error 拼接串与日志，
     * reason 用于前端分组明细（姓名/行号已拆到独立字段，不再重复）。
     */
    private function noticeItem(string $code, string $message, string $reason = '', array $extra = []): array
    {
        return array_merge([
            'code' => $code, 'row' => null, 'day' => null, 'name' => '',
            'field' => '', 'label' => '', 'filled' => null, 'counted' => null,
            'message' => $message, 'reason' => $reason !== '' ? $reason : $message,
        ], $extra);
    }

    /** 上传拒单：保留旧 error 字符串（最多 30 条），新增结构化条目供前端分组展示 */
    private function uploadReject(string $ctx, array $items): JsonResponse
    {
        $canForce = false;
        foreach ($items as $it) {
            if (($it['code'] ?? '') === 'stat_mismatch') { $canForce = true; break; }
        }
        $messages = array_map(fn ($it) => (string) $it['message'], $items);
        $error = $ctx . implode('；', array_slice($messages, 0, 30));
        if (count($messages) > 30) $error .= "（共 " . count($messages) . ' 条，仅显示前 30 条）';
        if ($canForce) {
            $error .= '（请核对格子符号，或把该列留空让系统按符号统计；确认无误可勾选“仍然上传”）';
        }
        return response()->json([
            'ok' => false,
            'error' => $error,
            'error_items' => $items,
            'error_total' => count($items),
            'error_shown' => count($items),
            'can_force' => $canForce,
        ], 400);
    }

    /** 上传成功：warnings 保留旧字符串数组，新增 warning_items / info_items 结构化条目 */
    private function uploadOk(array $response, array $warnItems, array $infoItems = []): JsonResponse
    {
        $payload = ['ok' => true] + $response;
        if ($warnItems) {
            $payload['warnings'] = array_map(fn ($it) => (string) $it['message'], $warnItems);
            $payload['warning_items'] = $warnItems;
        }
        if ($infoItems) {
            $payload['info_items'] = $infoItems;
        }
        return response()->json($payload);
    }

    /** 本月离职人员提示（不阻断上传）：离职日期落在本月内且出现在上传表中 */
    private function resignedInfos(string $ym, array $names, $staffByName): array
    {
        $monthEnd = date('Y-m-t', strtotime($ym . '-01'));
        $items = [];
        foreach ($names as $name) {
            $s = $staffByName[$name] ?? null;
            if (!$s) continue;
            $rd = substr((string) ($s->resign_date ?? ''), 0, 10);
            if ($rd === '' || $rd < $ym . '-01' || $rd > $monthEnd) continue;
            $items[] = $this->noticeItem('resigned_in_month',
                "{$name} 本月离职（离职日期 {$rd}），已计入上传，请确认考勤天数",
                "本月离职（离职日期 {$rd}），请确认考勤天数",
                ['name' => $name]);
        }
        return $items;
    }
}
