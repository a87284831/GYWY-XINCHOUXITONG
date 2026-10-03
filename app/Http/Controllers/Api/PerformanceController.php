<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PerformanceController extends ApiController
{
    private const STATUS = ['draft' => '草稿', 'confirm' => '待确认指标', 'ongoing' => '考核进行中', 'report' => '待数据填报',
        'self' => '待发起人自评', 'approve' => '待逐级审批', 'done' => '已归档'];
    private const CALC_TYPES = ['ratio' => '比例计分', 'ladder' => '阶梯扣分', 'count' => '达标扣分',
        'check' => '核查定分', 'manual' => '主观评分'];

    public function userOptions(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        return response()->json(['ok' => true, 'users' => DB::table('payroll_accounts')
            ->select('legacy_id as userId', 'staff_id as staffId', 'username', 'name', 'role', 'enabled')->get()]);
    }

    public function list(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $scope = (string) $request->input('scope', 'mine');
        $myAccountId = (int) $account->legacy_id;
        $myStaffId = $account->staff_id ? (int) $account->staff_id : null;
        $isAdmin = $account->role === 'admin';

        // 读取前先执行“周期结束自动转入数据填报”的懒推进，保证任何入口看到的都是最新状态
        $this->autoAdvance();

        $plans = DB::table('performance_plans')->orderByDesc('id')->get()
            ->map(fn ($row) => $this->jsonValue($row->data) ?: [])
            ->filter(function (array $p) use ($scope, $isAdmin, $myAccountId, $myStaffId) {
                if ($scope === 'all') return $isAdmin;
                $founder = (int) ($p['founderId'] ?? 0) === $myAccountId;
                $employee = (int) ($p['employeeId'] ?? 0) === $myStaffId;
                if ($scope === 'mine')  return $founder || $employee;
                if ($scope === 'approve') {
                    if (!in_array($p['status'] ?? '', ['confirm', 'approve'], true)) return false;
                    foreach (($p['approvers'] ?? []) as $a) {
                        $sid = (int) ($a['staffId'] ?? 0); $uid = (int) ($a['userId'] ?? 0);
                        if (($sid && $sid === $myStaffId) || $uid === $myAccountId) return true;
                    }
                    return false;
                }
                if ($scope === 'report') {
                    if (($p['status'] ?? '') !== 'report') return false;
                    foreach (($p['categories'] ?? []) as $c) foreach (($c['items'] ?? []) as $it) {
                        $rid = (int) ($it['reporterId'] ?? 0);
                        if ($rid && ($rid === $myStaffId || $rid === $myAccountId)) return true;
                    }
                    return false;
                }
                return $founder || $employee;
            })->map(fn ($p) => $this->decoratePlan($p))->values();
        return response()->json(['ok' => true, 'plans' => $plans, 'statusMap' => self::STATUS]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $this->autoAdvance();
        $row = DB::table('performance_plans')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '考核单不存在'], 404);
        $plan = $this->jsonValue($row->data) ?: [];
        $plan = $this->decoratePlan($plan);
        return response()->json(['ok' => true, 'plan' => $plan, 'statusMap' => self::STATUS,
            'calcTypes' => self::CALC_TYPES,
            'gradeRules' => $this->gradeRulesData(),
            'scoreWeights' => $this->scoreWeightsData(),
            'me' => ['userId' => (int) $account->legacy_id, 'staffId' => $account->staff_id ? (int) $account->staff_id : null,
                     'username' => $account->username, 'name' => $account->name ?: $account->username],
            'isAdmin' => $account->role === 'admin']);
    }

    public function save(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $request->all(); unset($plan['submit']);
        $id = (int) ($plan['id'] ?? 0);
        // 指标冻结：考核单一旦离开草稿态（提交审核后），指标只能由首位审批人在 confirm 态改
        if ($id) {
            $existing = $this->plan($id);
            if ($existing && ($existing['status'] ?? 'draft') !== 'draft') {
                return response()->json(['ok' => false, 'error' => '考核指标已提交/生效，不可再编辑'], 400);
            }
        }
        // 普通员工（非管理员）发起考核：被考核人锁定为本人
        if ($account->role !== 'admin') {
            if (empty($account->staff_id)) {
                return response()->json(['ok' => false, 'error' => '您的账号未绑定人员档案，无法发起考核，请联系管理员绑定'], 400);
            }
            $plan['employeeId'] = (int) $account->staff_id;
        }
        // 考核周期：自定义开始/结束日期（必填）
        $periodStart = trim((string) ($plan['periodStart'] ?? ''));
        $periodEnd = trim((string) ($plan['periodEnd'] ?? ''));
        if ($periodStart === '' || $periodEnd === '') {
            return response()->json(['ok' => false, 'error' => '请选择本次考核周期的开始与结束日期'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
            return response()->json(['ok' => false, 'error' => '考核周期日期格式应为 YYYY-MM-DD'], 400);
        }
        if ($periodEnd < $periodStart) {
            return response()->json(['ok' => false, 'error' => '考核周期结束日期不能早于开始日期'], 400);
        }
        // 必选被考核人
        if (empty($plan['employeeId'])) {
            return response()->json(['ok' => false, 'error' => '请选择被考核员工'], 400);
        }
        // 统一按被考核人档案补齐姓名与所属项目（管理员发起同样生效，保证排名/列表按真实项目展示）
        $emp = DB::table('payroll_staff')->where('legacy_id', (int) $plan['employeeId'])->first();
        if (!$emp) {
            return response()->json(['ok' => false, 'error' => '被考核人不在人员档案中'], 400);
        }
        $plan['employeeName'] = $emp->name;
        $plan['project'] = $emp->project_name ?: ($plan['project'] ?? '');
        // 审批人必须都存在且带有启用账号（人员维度）
        $approvers = [];
        foreach ((array) ($plan['approvers'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $staffId = isset($a['staffId']) ? (int) $a['staffId'] : 0;
            if ($staffId && empty($a['name'])) {
                $st = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
                $a['name'] = $st ? $st->name : '';
            }
            if ($staffId) $approvers[] = $a;
        }
        if (!$approvers) {
            return response()->json(['ok' => false, 'error' => '请至少添加一级审批人'], 400);
        }
        foreach ($approvers as $a) {
            $staffId = (int) ($a['staffId'] ?? 0);
            if (!$staffId) return response()->json(['ok' => false, 'error' => '审批人数据无效'], 400);
            $staff = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
            if (!$staff) return response()->json(['ok' => false, 'error' => '审批人不在人员档案中'], 400);
            $hasAccount = DB::table('payroll_accounts')->where('staff_id', $staffId)->where('enabled', true)->exists();
            if (!$hasAccount) {
                return response()->json(['ok' => false, 'error' => '审批人「' . $staff->name . '」未绑定可用账号，无法执行审批，请先为其开通账号'], 400);
            }
        }
        // 防重复：同一被考核人在该时间区间内已有未归档考核
        $duplicate = DB::table('performance_plans')
            ->where('employee_id', (int) $plan['employeeId'])
            ->where('status', '!=', 'done')
            ->get()
            ->contains(function ($row) use ($periodStart, $periodEnd, $id) {
                if ((int) $row->legacy_id === $id) return false;
                $data = $this->jsonValue($row->data) ?: [];
                $s = (string) ($data['periodStart'] ?? '');
                $e = (string) ($data['periodEnd'] ?? '');
                if ($s === '' || $e === '') return true;
                return $periodStart <= $e && $periodEnd >= $s;
            });
        if ($duplicate) {
            return response()->json(['ok' => false, 'error' => '该员工在当前考核周期内已存在进行中的考核，请勿重复发起'], 409);
        }
        $plan['approvers'] = $approvers;
        $isNew = !$id;
        if (!$id) $id = (int) (DB::table('performance_plans')->max('legacy_id') ?? 0) + 1;
        $plan['id'] = $id; $plan['founderId'] = $plan['founderId'] ?? (int) $account->legacy_id;
        $plan['founderName'] = $plan['founderName'] ?? ($account->name ?: $account->username);
        $plan['status'] = $plan['status'] ?? 'draft'; $plan['createdAt'] = $plan['createdAt'] ?? now()->toDateTimeString();
        $plan['periodStart'] = $periodStart; $plan['periodEnd'] = $periodEnd;
        $plan['year'] = (int) substr($periodStart, 0, 4);
        unset($plan['quarter']);
        // 提交时校验指标（类型合法、权重合计=100，防绕过前端）；草稿保存不强制
        if ($request->boolean('submit')) {
            $err = $this->validateCategories((array) ($plan['categories'] ?? []));
            if ($err !== null) return response()->json(['ok' => false, 'error' => $err], 400);
        }
        if ($request->boolean('submit') && $plan['status'] === 'draft') $plan['status'] = 'confirm';
        $this->log($plan, $account, $request->boolean('submit')
            ? '提交考核，进入上级确认指标'
            : ($isNew ? '发起考核并保存草稿' : '编辑保存考核草稿'));
        DB::table('performance_plans')->updateOrInsert(['legacy_id' => $id], [
            'employee_id' => (int) $plan['employeeId'], 'employee_name' => $plan['employeeName'] ?? null,
            'project_name' => $plan['project'] ?? null, 'year' => $plan['year'] ?? null,
            'quarter' => null, 'status' => $plan['status'],
            'data' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $id, 'status' => $plan['status']]);
    }

    /** 被考核人的直属上级账号（若其有账号），供前端预设审批人 */
    public function leaderApprover(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $staffId = (int) $request->input('staff_id');
        $leader = $this->leaderApproverFor($staffId);
        return response()->json(['ok' => true, 'leader' => $leader,
            'msg' => $leader ? '已自动带出直属上级为审批人' : '该人员未设置直属上级，请手动选择审批人']);
    }

    /** 被考核人的直属上级（人员），供前端预设审批人；hasAccount 标识其是否有启用账号可登录审批 */
    private function leaderApproverFor(int $staffId): ?array
    {
        $staff = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
        if (!$staff || empty($staff->leader_id)) return null;
        $leader = DB::table('payroll_staff')->where('legacy_id', $staff->leader_id)->first();
        if (!$leader) return null;
        $hasAccount = DB::table('payroll_accounts')->where('staff_id', $leader->legacy_id)->where('enabled', true)->exists();
        return ['staffId' => (int) $leader->legacy_id, 'name' => $leader->name,
                'hasAccount' => $hasAccount,
                'userId' => optional(DB::table('payroll_accounts')->where('staff_id', $leader->legacy_id)->where('enabled', true)->first())->legacy_id];
    }

    public function delete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id'); $row = DB::table('performance_plans')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '考核单不存在'], 404);
        $plan = $this->jsonValue($row->data) ?: [];
        if ($account->role !== 'admin' && (int) ($plan['founderId'] ?? 0) !== (int) $account->legacy_id) return response()->json(['ok' => false, 'error' => '无权删除'], 403);
        if (($plan['status'] ?? '') === 'done') {
            return response()->json(['ok' => false, 'error' => '已归档考核不可删除；如需修改请由管理员撤销归档'], 400);
        }
        DB::table('performance_plans')->where('legacy_id', $id)->delete(); return response()->json(['ok' => true]);
    }

    /** 是否有指标审核权：管理员、发起人、首位审批人（与 confirm 通过权限一致） */
    private function firstApproverAuthorized(array $plan, object $account): bool
    {
        if ($account->role === 'admin') return true;
        if ((int) ($plan['founderId'] ?? 0) === (int) $account->legacy_id) return true;
        $first = $plan['approvers'][0] ?? null;
        if (!$first) return false;
        $myStaffId = $account->staff_id ? (int) $account->staff_id : -1;
        return (int) ($first['staffId'] ?? 0) === $myStaffId
            || (int) ($first['userId'] ?? 0) === (int) $account->legacy_id;
    }

    public function confirm(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'confirm') return response()->json(['ok' => false, 'error' => '当前状态不可确认'], 400);
        if (!$this->firstApproverAuthorized($plan, $account)) {
            return response()->json(['ok' => false, 'error' => '仅首位审批人可确认指标'], 403);
        }
        $plan['status'] = 'ongoing'; $plan['confirmedAt'] = now()->toDateTimeString();
        $this->log($plan, $account, '确认指标通过，进入考核周期', $plan['periodStart'] . ' ~ ' . $plan['periodEnd']);
        $this->storePlan($plan); return response()->json(['ok' => true, 'status' => 'ongoing', 'periodEnd' => $plan['periodEnd'] ?? null]);
    }

    /**
     * 审核态保存首位审批人对指标的修改（可多次保存，不改变状态）。
     * 仅替换 categories；被考核人/周期/审批人链一律以服务端现存数据为准。
     */
    public function confirmSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'confirm') {
            return response()->json(['ok' => false, 'error' => '当前状态不可修改指标'], 400);
        }
        if (!$this->firstApproverAuthorized($plan, $account)) {
            return response()->json(['ok' => false, 'error' => '仅首位审批人可修改指标'], 403);
        }
        $cats = (array) $request->input('categories', []);
        $err = $this->validateCategories($cats);
        if ($err !== null) return response()->json(['ok' => false, 'error' => $err], 400);

        $oldCategories = $plan['categories'] ?? [];
        $plan['categories'] = array_values($cats);
        $this->logCategoriesDiff($plan, $oldCategories, $account);
        $this->log($plan, $account, '审核人保存考核指标修改', '');
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'id' => $plan['id'], 'status' => 'confirm']);
    }

    /** 指标修改逐项 diff 留痕：按 item id 匹配，记录内容/定义/权重/类型/参数/核查人变化与增删 */
    private function logCategoriesDiff(array &$plan, array $oldCategories, object $account): void
    {
        $flatten = function (array $cats): array {
            $map = [];
            foreach ($cats as $cat) {
                foreach (($cat['items'] ?? []) as $it) {
                    if (!empty($it['id'])) $map[(string) $it['id']] = $it;
                }
            }
            return $map;
        };
        $old = $flatten($oldCategories);
        $new = $flatten($plan['categories'] ?? []);

        foreach ($new as $iid => $it) {
            $label = fn (array $x): string => (string) ($x['content'] ?? '未命名指标');
            if (!isset($old[$iid])) {
                $this->log($plan, $account, '新增指标「' . $label($it) . '」', '权重 ' . ($it['weight'] ?? 0));
                continue;
            }
            $o = $old[$iid];
            $changes = [];
            if ((string) ($o['content'] ?? '') !== (string) ($it['content'] ?? '')) {
                $changes[] = '指标内容「' . $o['content'] . '」→「' . $it['content'] . '」';
            }
            if ((string) ($o['definition'] ?? '') !== (string) ($it['definition'] ?? '')) {
                $changes[] = '指标定义调整';
            }
            $ow = (float) ($o['weight'] ?? 0); $nw = (float) ($it['weight'] ?? 0);
            if (abs($ow - $nw) > 0.0001) {
                $changes[] = '权重 ' . rtrim(rtrim(number_format($ow, 2, '.', ''), '0'), '.')
                    . '→' . rtrim(rtrim(number_format($nw, 2, '.', ''), '0'), '.');
            }
            $ot = (string) ($o['calcType'] ?? 'ratio'); $nt = (string) ($it['calcType'] ?? 'ratio');
            if ($ot !== $nt) {
                $changes[] = '类型 ' . (self::CALC_TYPES[$ot] ?? $ot) . '→' . (self::CALC_TYPES[$nt] ?? $nt);
            }
            if (json_encode($o['calcParams'] ?? [], JSON_UNESCAPED_UNICODE) !== json_encode($it['calcParams'] ?? [], JSON_UNESCAPED_UNICODE)) {
                $changes[] = '评分参数调整';
            }
            if ((string) ($o['reporterName'] ?? '') !== (string) ($it['reporterName'] ?? '')) {
                $changes[] = '核查人「' . ($o['reporterName'] ?? '未指派') . '」→「' . ($it['reporterName'] ?? '未指派') . '」';
            }
            if ($changes) {
                $this->log($plan, $account, '修改指标「' . $label($it) . '」', implode('；', $changes));
            }
        }
        foreach ($old as $iid => $it) {
            if (!isset($new[$iid])) {
                $this->log($plan, $account, '删除指标「' . ($it['content'] ?? '未命名指标') . '」', '原权重 ' . ($it['weight'] ?? 0));
            }
        }
    }

    /**
     * 手动提前结束考核周期，立即转入数据填报（仅管理员或发起人）。
     */
    public function startReport(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'ongoing') {
            return response()->json(['ok' => false, 'error' => '当前状态（' . (self::STATUS[$plan['status'] ?? ''] ?? $plan['status'] ?? '未知') . '）不可转入数据填报'], 400);
        }
        $isFounder = (int) ($plan['founderId'] ?? 0) === (int) $account->legacy_id;
        if ($account->role !== 'admin' && !$isFounder) {
            return response()->json(['ok' => false, 'error' => '仅管理员或发起人可提前转入数据填报'], 403);
        }
        $plan['status'] = 'report'; $plan['reportStartedAt'] = now()->toDateTimeString();
        $plan['manualAdvance'] = true;
        $this->log($plan, $account, '手动结束考核周期，转入数据填报', $plan['periodStart'] . ' ~ ' . $plan['periodEnd']);
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'status' => 'report']);
    }

    public function reject(Request $request, string $node): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        $opinion = trim((string) $request->input('opinion', ''));
        if ($opinion === '') return response()->json(['ok' => false, 'error' => '驳回必须填写意见'], 400);
        $plan['status'] = $node === 'confirm' ? 'draft' : 'self'; $plan['rejectOpinion'] = $opinion;
        $this->log($plan, $account, $node === 'confirm' ? '驳回指标确认，退回发起人修改' : '驳回审批，退回发起人自评', $opinion);
        $this->storePlan($plan); return response()->json(['ok' => true, 'status' => $plan['status']]);
    }

    public function report(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $admin = $account->role === 'admin';
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (!in_array($plan['status'] ?? '', ['report', 'ongoing'], true)) {
            return response()->json(['ok' => false, 'error' => '当前状态不可填报数据'], 400);
        }
        $itemId = (string) $request->input('itemId'); $found = false; $itemContent = '';
        $myStaffId = $account->staff_id ? (int) $account->staff_id : null;
        $myAccountId = (int) $account->legacy_id;
        foreach (($plan['categories'] ?? []) as $ci => $cat) {
            foreach (($cat['items'] ?? []) as $ii => $it) {
                if ((string) ($it['id'] ?? '') !== $itemId) continue;
                if (!$admin && isset($it['reporterId']) && (int) $it['reporterId'] !== $myStaffId
                    && (int) $it['reporterId'] !== $myAccountId) return response()->json(['ok' => false, 'error' => '该指标不是指派给您'], 403);
                $calcType = (string) ($it['calcType'] ?? 'ratio');
                $actualValue = $request->input('actualValue', '');
                if ($calcType === 'check') {
                    // 核查定分：核查人直接给该项得分（0~权重），提交后锁定
                    $checkScore = $request->input('checkScore');
                    if (!is_numeric($checkScore)) {
                        return response()->json(['ok' => false, 'error' => '该指标为「核查定分」，请填写 0~权重 的得分'], 400);
                    }
                    $checkScore = (float) $checkScore;
                    $weight = (float) ($it['weight'] ?? 0);
                    if ($checkScore < 0 || $checkScore > $weight + 0.0001) {
                        return response()->json(['ok' => false, 'error' => '核查定分须在 0~' . rtrim(rtrim((string) $weight, '0'), '.') . ' 之间'], 400);
                    }
                    $plan['categories'][$ci]['items'][$ii]['checkScore'] = round($checkScore, 2);
                    $plan['categories'][$ci]['items'][$ii]['actualValue'] = '';
                } elseif ($calcType !== 'manual') {
                    // 比例/阶梯/达标类必须有数值实际值，否则无法自动算分
                    if ($actualValue === '' || !is_numeric($actualValue)) {
                        return response()->json(['ok' => false, 'error' => '该指标为「' . (self::CALC_TYPES[$calcType] ?? '') . '」，请填写数值型实际完成值后再提交'], 400);
                    }
                    $plan['categories'][$ci]['items'][$ii]['actualValue'] = $actualValue;
                    unset($plan['categories'][$ci]['items'][$ii]['checkScore']);
                } else {
                    $plan['categories'][$ci]['items'][$ii]['actualValue'] = '';
                }
                $plan['categories'][$ci]['items'][$ii]['actualText'] = trim((string) $request->input('actualText', ''));
                $plan['categories'][$ci]['items'][$ii]['reportBy'] = $account->username;
                $plan['categories'][$ci]['items'][$ii]['reportTime'] = now()->toDateTimeString();
                $itemContent = (string) ($it['content'] ?? '');
                $found = true;
            }
        }
        if (!$found) return response()->json(['ok' => false, 'error' => '指标项不存在'], 404);
        if ($plan['status'] === 'ongoing') $plan['status'] = 'report';
        $this->log($plan, $account, $admin ? '管理员代填数据' : '填报数据', '指标「' . $itemContent . '」');
        $pending = $this->pendingReportCount($plan);
        if ($plan['status'] === 'report' && $pending === 0) {
            $plan['status'] = 'self';
            $this->log($plan, $account, '全部指标填报完成，转入发起人自评', '');
        }
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'status' => $plan['status'], 'pending' => $pending]);
    }

    /**
     * 手动完成数据填报、转入发起人自评（仅管理员或发起人，兜底用）。
     */
    public function finishReport(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'report') {
            return response()->json(['ok' => false, 'error' => '当前状态（' . (self::STATUS[$plan['status'] ?? ''] ?? $plan['status'] ?? '未知') . '）不可进入自评'], 400);
        }
        $isFounder = (int) ($plan['founderId'] ?? 0) === (int) $account->legacy_id;
        if ($account->role !== 'admin' && !$isFounder) {
            return response()->json(['ok' => false, 'error' => '仅管理员或发起人可结束数据填报'], 403);
        }
        $pending = $this->pendingReportCount($plan);
        $plan['status'] = 'self';
        $this->log($plan, $account, '结束数据填报，转入发起人自评', $pending > 0 ? "尚有 {$pending} 项指标未由填报人提交，手动提前进入" : '全部指标已由填报人提交');
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'status' => 'self', 'pending' => $pending]);
    }

    public function selfSubmit(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $admin = $account->role === 'admin';
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'self' && ($plan['status'] ?? '') !== 'report') return response()->json(['ok' => false, 'error' => '当前状态不可提交自评'], 400);
        if (!$admin && (int) ($account->staff_id ?? 0) !== (int) ($plan['employeeId'] ?? 0)
            && (int) $account->legacy_id !== (int) ($plan['founderId'] ?? 0)) {
            return response()->json(['ok' => false, 'error' => '仅被考核人本人或管理员可提交自评'], 403);
        }
        $scores = collect((array) $request->input('items', []))->keyBy('id');
        foreach (($plan['categories'] ?? []) as $ci => $cat) {
            foreach (($cat['items'] ?? []) as $ii => $it) {
                $sc = $scores[$it['id']]['selfScore'] ?? null;
                if ($sc === null || !is_numeric($sc)) continue;
                if (($it['calcType'] ?? 'ratio') !== 'manual') {
                    return response()->json(['ok' => false, 'error' => '客观指标「' . ($it['content'] ?? '') . '」分数由核查数据锁定，不可自评'], 400);
                }
                $plan['categories'][$ci]['items'][$ii]['selfScore'] = round((float) $sc, 2);
            }
        }
        $this->applyScores($plan);
        $plan['status'] = 'approve'; $plan['currentStep'] = 0;
        $this->log($plan, $account, '提交自评，进入逐级审批', '自评总分 ' . ($plan['selfTotal'] ?? '-'));
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'status' => 'approve', 'selfTotal' => $plan['selfTotal'] ?? null]);
    }

    public function approve(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        if (($plan['status'] ?? '') !== 'approve') return response()->json(['ok' => false, 'error' => '当前状态不可审批'], 400);
        // 逐级审批：仅当前步骤审批人（按绑定人员）或管理员可审
        $step = (int) ($plan['currentStep'] ?? 0);
        if ($account->role !== 'admin') {
            $cur = $plan['approvers'][$step] ?? null;
            $myStaffId = $account->staff_id ? (int) $account->staff_id : -1;
            $curStaff = (int) ($cur['staffId'] ?? 0); $curUser = (int) ($cur['userId'] ?? 0);
            if (!$cur || ($curStaff !== $myStaffId && $curUser !== (int) $account->legacy_id)) {
                return response()->json(['ok' => false, 'error' => '当前不是您的审批节点'], 403);
            }
        }
        // 应用当前审批人的考核人评分 + 最终分微调（以最后一级审批通过时的评分为准）
        // 仅主观项可评分；客观项分数由核查数据锁定
        $byId = collect((array) $request->input('items', []))->keyBy('id');
        foreach (($plan['categories'] ?? []) as $ci => $cat) {
            foreach (($cat['items'] ?? []) as $ii => $it) {
                $in = $byId[$it['id']] ?? null;
                if (!$in) continue;
                $isManual = (($it['calcType'] ?? 'ratio') === 'manual');
                if (!$isManual && ((isset($in['approverScore']) && is_numeric($in['approverScore']))
                    || (isset($in['finalScore']) && is_numeric($in['finalScore'])))) {
                    return response()->json(['ok' => false, 'error' => '客观指标「' . ($it['content'] ?? '') . '」分数由核查数据锁定，不可审批评分'], 400);
                }
                if (!$isManual) continue;
                if (isset($in['approverScore']) && is_numeric($in['approverScore'])) {
                    $plan['categories'][$ci]['items'][$ii]['approverScore'] = round((float) $in['approverScore'], 2);
                }
                if (isset($in['finalScore']) && is_numeric($in['finalScore'])) {
                    $plan['categories'][$ci]['items'][$ii]['finalScore'] = round((float) $in['finalScore'], 2);
                    $plan['categories'][$ci]['items'][$ii]['finalManual'] = true;
                }
            }
        }
        $plan['approverNames'] = $plan['approverNames'] ?? [];
        $plan['approverTimes'] = $plan['approverTimes'] ?? [];
        $plan['approverNames'][$step] = $account->name ?: $account->username;
        $plan['approverTimes'][$step] = now()->toDateTimeString();
        $opinion = trim((string) $request->input('opinion', ''));
        if ($opinion !== '') { $plan['approverOpinions'] = $plan['approverOpinions'] ?? []; $plan['approverOpinions'][$step] = $opinion; }
        $isLast = $step + 1 >= count($plan['approvers'] ?? []);
        if (!$isLast) {
            $plan['currentStep'] = $step + 1;
            $this->log($plan, $account, '审批通过，送下一级审批', $opinion !== '' ? $opinion : '');
            $this->storePlan($plan);
            return response()->json(['ok' => true, 'status' => 'approve', 'step' => $plan['currentStep']]);
        }
        // 终审归档：客观项必须都有锁定分，显式失败，不静默按 0
        $missing = $this->missingObjectiveScores($plan);
        if ($missing) {
            return response()->json(['ok' => false,
                'error' => '以下客观指标尚无核查分数，无法归档：' . implode('；', $missing),
                'missing' => $missing], 400);
        }
        // 终审归档：按占比混合计算最终总分与等级
        $this->applyScores($plan);
        $plan['status'] = 'done'; $plan['finishedAt'] = now()->toDateTimeString();
        $this->log($plan, $account, '终审通过并归档', ($opinion !== '' ? $opinion . '；' : '') . '最终总分 ' . ($plan['finalTotal'] ?? '-') . '，等级 ' . ($plan['grade'] ?: '-'));
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'status' => 'done', 'finalTotal' => $plan['finalTotal'] ?? null, 'grade' => $plan['grade'] ?? '']);
    }

    public function urge(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan((int) $request->input('id')); if (!$plan) return $this->missingPlan();
        $plan['urgeCount'] = ((int) ($plan['urgeCount'] ?? 0)) + 1; $plan['lastUrgeAt'] = now()->toDateTimeString();
        $this->log($plan, $account, '一键催办未填报人', '第 ' . $plan['urgeCount'] . ' 次催办');
        $this->storePlan($plan);
        return response()->json(['ok' => true, 'pending' => []]);
    }

    public function calc(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $weight = (float) $request->input('weight', 0); $actual = (float) $request->input('actualValue', 0);
        $params = (array) $request->input('calcParams', []); $type = $request->input('calcType'); $score = null;
        if ($type === 'ratio') { $target = (float) ($params['target'] ?? 0); $score = $target ? max(0, min($weight, $weight * $actual / $target)) : 0; }
        if ($type === 'count') { $score = max(0, min($weight, $weight - max(0, (float) ($params['required'] ?? 0) - $actual) * (float) ($params['deductEach'] ?? 0))); }
        if ($type === 'ladder') { $score = max(0, min($weight, $weight - max(0, (float) ($params['target'] ?? 100) - $actual) / max(1, (float) ($params['stepUnit'] ?? 1)) * (float) ($params['stepDeduct'] ?? 0))); }
        if ($type === 'check') { $score = max(0, min($weight, $actual)); }
        return response()->json(['ok' => true, 'score' => $score === null ? null : round($score, 2)]);
    }

    public function export(Request $request, int $id)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $plan = $this->plan($id); if (!$plan) return $this->missingPlan();
        if ($account->role !== 'admin' && (int) ($plan['founderId'] ?? 0) !== (int) $account->legacy_id) return response()->json(['ok' => false, 'error' => '无权导出'], 403);
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('绩效考核表');
        $sheet->fromArray(['指标类别', '指标内容', '指标定义', '完成时间', '权重', '实际情况', '最终得分'], null, 'A1'); $row = 2;
        foreach (($plan['categories'] ?? []) as $category) foreach (($category['items'] ?? []) as $item) {
            $sheet->fromArray([$category['name'] ?? '', $item['content'] ?? '', $item['definition'] ?? '', $item['finishTime'] ?? '',
                $item['weight'] ?? 0, $item['actualText'] ?? $item['actualValue'] ?? '', $item['finalScore'] ?? $item['selfScore'] ?? ''], null, 'A' . $row++);
        }
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), '绩效考核表.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** 项目绩效得分排名：已归档考核按最终总分降序 */
    public function ranking(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $project = trim((string) $request->input('project', ''));
        $quarter = (int) $request->input('quarter', 0); // 0=全部
        $plans = DB::table('performance_plans')->where('status', 'done')->orderByDesc('id')->get()
            ->map(fn ($row) => $this->jsonValue($row->data) ?: [])
            ->filter(function ($p) use ($project, $quarter) {
                if ($project !== '' && ($p['project'] ?? '') !== $project) return false;
                if ($quarter > 0 && empty($p['periodStart'])) return false;
                if ($quarter > 0 && $this->quarterOf((string) $p['periodStart']) !== $quarter) return false;
                return true;
            })
            ->map(fn ($p) => $this->decoratePlan($p))
            ->filter(fn ($p) => isset($p['finalTotal']))
            ->sortByDesc('finalTotal')->values();
        $out = [];
        foreach ($plans as $i => $p) {
            $out[] = ['rank' => $i + 1, 'id' => (int) $p['id'], 'employeeName' => $p['employeeName'] ?? '',
                'employeeId' => (int) ($p['employeeId'] ?? 0), 'project' => $p['project'] ?? '',
                'line' => $p['line'] ?? '', 'periodStart' => $p['periodStart'] ?? '', 'periodEnd' => $p['periodEnd'] ?? '',
                'quarter' => $p['quarter'] ?? null, 'selfTotal' => $p['selfTotal'] ?? null,
                'approverTotal' => $p['approverTotal'] ?? null, 'finalTotal' => $p['finalTotal'] ?? null,
                'grade' => $p['grade'] ?? ''];
        }
        return response()->json(['ok' => true, 'items' => $out]);
    }

    /** 批量导出考核记录（支持 tab/quarter/kw/project 筛选），仅管理员 */
    public function exportAll(Request $request)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可批量导出'], 403);
        $tab = (string) $request->input('tab', 'all');
        $quarter = (int) $request->input('quarter', 0);
        $kw = trim((string) $request->input('kw', ''));
        $project = trim((string) $request->input('project', ''));
        $plans = DB::table('performance_plans')->orderByDesc('id')->get()
            ->map(fn ($row) => $this->jsonValue($row->data) ?: [])
            ->filter(function ($p) use ($tab, $quarter, $kw, $project) {
                $st = $p['status'] ?? '';
                if ($tab === 'ongoing' && $st === 'done') return false;
                if ($tab === 'done' && $st !== 'done') return false;
                if ($quarter > 0 && empty($p['periodStart'])) return false;
                if ($quarter > 0 && $this->quarterOf((string) $p['periodStart']) !== $quarter) return false;
                if ($kw !== '' && !str_contains((string) ($p['employeeName'] ?? ''), $kw)
                    && !str_contains((string) ($p['line'] ?? ''), $kw) && !str_contains((string) ($p['project'] ?? ''), $kw)) return false;
                if ($project !== '' && ($p['project'] ?? '') !== $project) return false;
                return true;
            })
            ->map(fn ($p) => $this->decoratePlan($p))
            ->sortByDesc(fn ($p) => (int) ($p['id'] ?? 0))->values();
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('考核记录');
        $sheet->fromArray(['单号', '被考核人', '所属项目', '条线/岗位', '考核周期开始', '考核周期结束', '所属季度', '状态', '权重合计', '自评总分', '考核人评分总分', '最终总分', '等级', '当前审批人', '发起人', '审批链'], null, 'A1');
        $row = 2;
        foreach ($plans as $p) {
            $chain = implode(' → ', array_map(fn ($a) => ($a['name'] ?? '') . (($a['state'] ?? '') === 'approved' ? '✔' : ''), $p['approvers'] ?? []));
            $sheet->fromArray([
                $p['id'] ?? '', $p['employeeName'] ?? '', $p['project'] ?? '', $p['line'] ?? '',
                $p['periodStart'] ?? '', $p['periodEnd'] ?? '', $p['quarter'] ?? '', $p['statusLabel'] ?? '',
                $p['weightSum'] ?? 0, $p['selfTotal'] ?? '', $p['approverTotal'] ?? '', $p['finalTotal'] ?? '',
                $p['grade'] ?? '', $p['approverName'] ?? '', $p['founderName'] ?? '', $chain,
            ], null, 'A' . $row++);
        }
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), '考核记录导出.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function gradeRules(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($request->isMethod('get')) {
            return response()->json(['ok' => true, 'gradeRules' => $this->gradeRulesData(), 'scoreWeights' => $this->scoreWeightsData()]);
        }
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可设置等级与评分占比'], 403);
        $rules = array_values(array_filter((array) $request->input('gradeRules', []), fn ($r) => isset($r['min'], $r['label']) && $r['label'] !== ''));
        if (!$rules) return response()->json(['ok' => false, 'error' => '至少保留一个等级'], 400);
        $weights = $this->normalizeWeights((array) $request->input('scoreWeights', []));
        if ($weights === null) return response()->json(['ok' => false, 'error' => '自评占比与考核人评分占比合计必须等于100'], 400);
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'performance.json')->first();
        $data = $snapshot ? ($this->jsonValue($snapshot->payload) ?: []) : [];
        $data['gradeRules'] = $rules; $data['scoreWeights'] = $weights;
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'performance.json'], ['payload' => json_encode($data, JSON_UNESCAPED_UNICODE), 'imported_at' => now()]);
        return response()->json(['ok' => true, 'gradeRules' => $rules, 'scoreWeights' => $weights]);
    }

    private function gradeRulesData(): array
    {
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'performance.json')->first();
        return $snapshot ? (($this->jsonValue($snapshot->payload)['gradeRules'] ?? [])) : [];
    }

    /** 评分占比归一化：自评 + 考核人评分必须等于 100；非法返回 null */
    private function normalizeWeights(array $w): ?array
    {
        $self = isset($w['self']) && is_numeric($w['self']) ? (float) $w['self'] : -1;
        $approver = isset($w['approver']) && is_numeric($w['approver']) ? (float) $w['approver'] : -1;
        if ($self < 0 || $approver < 0) return null;
        if (abs($self + $approver - 100) > 0.01) return null;
        return ['self' => round($self, 2), 'approver' => round($approver, 2)];
    }

    private function scoreWeightsData(): array
    {
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'performance.json')->first();
        $d = $snapshot ? ($this->jsonValue($snapshot->payload)['scoreWeights'] ?? []) : [];
        $self = isset($d['self']) && is_numeric($d['self']) ? (float) $d['self'] : 50.0;
        $approver = isset($d['approver']) && is_numeric($d['approver']) ? (float) $d['approver'] : 50.0;
        if ($self + $approver <= 0) { $self = 50.0; $approver = 50.0; }
        return ['self' => round($self, 2), 'approver' => round($approver, 2)];
    }

    /** 单项自动分（与 /calc 一致）；无实际值、核查定分、主观项返回 null */
    private function autoScore(array $it): ?float
    {
        $w = (float) ($it['weight'] ?? 0);
        $v = isset($it['actualValue']) && is_numeric($it['actualValue']) ? (float) $it['actualValue'] : null;
        if ($v === null) return null;
        $p = (array) ($it['calcParams'] ?? []);
        $t = $it['calcType'] ?? 'ratio';
        if ($t === 'manual' || $t === 'check') return null;
        if ($t === 'ratio') { $tg = (float) ($p['target'] ?? 0); return $tg ? round(max(0, min($w, $w * $v / $tg)), 2) : 0.0; }
        if ($t === 'count') { $req = (float) ($p['required'] ?? 0); $ded = (float) ($p['deductEach'] ?? 0); return round(max(0, min($w, $w - max(0, $req - $v) * $ded)), 2); }
        if ($t === 'ladder') {
            $tg = (float) ($p['target'] ?? 100); $unit = (float) ($p['stepUnit'] ?? 1); $ded = (float) ($p['stepDeduct'] ?? 0);
            $zt = (isset($p['zeroThreshold']) && $p['zeroThreshold'] !== '') ? (float) $p['zeroThreshold'] : null;
            if ($zt !== null && $v < $zt) return 0.0;
            return round(max(0, min($w, $w - max(0, $tg - $v) / max(1, $unit) * $ded)), 2);
        }
        return null;
    }

    /**
     * 客观项的锁定最终分：
     * - ratio/ladder/count（含历史缺省类型）：按核查数值自动算分，无值返回 null
     * - check：核查人填报的 checkScore（0 是有效分值），未填返回 null
     * - manual：返回 null（走自评/上级加权）
     */
    private function objectiveLockedScore(array $it): ?float
    {
        $t = (string) ($it['calcType'] ?? 'ratio');
        if ($t === 'manual') return null;
        if ($t === 'check') {
            return isset($it['checkScore']) && is_numeric($it['checkScore']) ? round((float) $it['checkScore'], 2) : null;
        }
        return $this->autoScore($it);
    }

    /** 归档前找出缺锁定分的客观项，返回"类别/内容"标签数组 */
    private function missingObjectiveScores(array $plan): array
    {
        $missing = [];
        foreach (($plan['categories'] ?? []) as $cat) {
            foreach (($cat['items'] ?? []) as $it) {
                if (($it['calcType'] ?? 'ratio') === 'manual') continue;
                if ($this->objectiveLockedScore($it) === null) {
                    $missing[] = (($cat['name'] ?? '') !== '' ? $cat['name'] . '/' : '') . ($it['content'] ?? '未命名指标');
                }
            }
        }
        return $missing;
    }

    /** 指标树校验：类型合法、权重非负、合计=100；通过返回 null，否则返回中文错误 */
    private function validateCategories(array $categories): ?string
    {
        $sum = 0.0; $n = 0;
        foreach ($categories as $cat) {
            foreach (($cat['items'] ?? []) as $it) {
                $t = (string) ($it['calcType'] ?? 'ratio');
                if (!isset(self::CALC_TYPES[$t])) return '存在不支持的指标类型：' . $t;
                $w = (float) ($it['weight'] ?? 0);
                if ($w < 0) return '指标「' . ($it['content'] ?? '') . '」权重不能为负';
                $sum += $w; $n++;
            }
        }
        if ($n === 0) return '请至少添加一个考核指标';
        if (abs($sum - 100) > 0.01) return '指标权重合计须为 100，当前为 ' . rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.');
        return null;
    }

    /**
     * 重算各分并写回 plan（不落库），口径：
     * - 客观项（ratio/ladder/count/check）：最终分=锁定分，本人/上级评分不参与
     * - 主观项（manual）：最终分=自评×自评占比+考核人×考核人占比（终审微调过则用微调值；仅一方评则回退该方）
     * - 自评/考核人总分只累加主观项；最终总分=Σ各项最终分；等级按规则匹配
     */
    private function applyScores(array &$plan): void
    {
        $w = $this->scoreWeightsData();
        $sw = $w['self'] / 100; $aw = $w['approver'] / 100;
        $autoTotal = 0.0; $selfTotal = 0.0; $approverTotal = 0.0; $finalTotal = 0.0; $hasFinal = false;
        foreach (($plan['categories'] ?? []) as $ci => $cat) {
            foreach (($cat['items'] ?? []) as $ii => $it) {
                $isManual = (($it['calcType'] ?? 'ratio') === 'manual');
                $as = $this->autoScore($it);
                $plan['categories'][$ci]['items'][$ii]['autoScore'] = $as;
                if ($as !== null) $autoTotal += $as;
                if (!$isManual) {
                    // 客观项：锁定分即最终分；忽略任何途径残留的本人/上级评分
                    $locked = $this->objectiveLockedScore($it);
                    if ($locked !== null) {
                        $plan['categories'][$ci]['items'][$ii]['finalScore'] = round($locked, 2);
                        $finalTotal += $locked; $hasFinal = true;
                    }
                    $plan['categories'][$ci]['items'][$ii]['selfWeighted'] = null;
                    $plan['categories'][$ci]['items'][$ii]['approverWeighted'] = null;
                    continue;
                }
                $self = (isset($it['selfScore']) && is_numeric($it['selfScore'])) ? (float) $it['selfScore'] : null;
                $appr = (isset($it['approverScore']) && is_numeric($it['approverScore'])) ? (float) $it['approverScore'] : null;
                if ($self !== null) $selfTotal += $self;
                if ($appr !== null) $approverTotal += $appr;
                $f = null;
                if (is_numeric($it['finalScore'] ?? null) && !empty($it['finalManual'])) {
                    $f = (float) $it['finalScore'];
                } elseif ($self !== null && $appr !== null) {
                    $f = round($self * $sw + $appr * $aw, 2);
                } elseif ($self !== null) {
                    $f = $self;
                } elseif ($appr !== null) {
                    $f = $appr;
                }
                if ($f !== null) {
                    $plan['categories'][$ci]['items'][$ii]['finalScore'] = round($f, 2);
                    $finalTotal += $f; $hasFinal = true;
                }
                $plan['categories'][$ci]['items'][$ii]['selfWeighted'] = $self !== null ? round($self * $sw, 2) : null;
                $plan['categories'][$ci]['items'][$ii]['approverWeighted'] = $appr !== null ? round($appr * $aw, 2) : null;
            }
        }
        $plan['autoTotal'] = round($autoTotal, 2);
        $plan['selfTotal'] = round($selfTotal, 2);
        $plan['approverTotal'] = round($approverTotal, 2);
        if ($hasFinal) $plan['finalTotal'] = round($finalTotal, 2);
        $plan['grade'] = isset($plan['finalTotal']) ? $this->gradeFor((float) $plan['finalTotal']) : '';
    }

    private function gradeFor(float $total): string
    {
        $rules = collect($this->gradeRulesData())->sortByDesc('min')->values();
        foreach ($rules as $r) {
            if ($total >= (float) ($r['min'] ?? 0)) return (string) ($r['label'] ?? '');
        }
        return '';
    }

    /** 考核周期按开始日期归属季度 */
    private function quarterOf(string $start): int
    {
        $m = (int) (substr($start, 5, 2) ?: '1');
        return intdiv($m - 1, 3) + 1;
    }

    /** 写入流程时间线 */
    private function log(array &$plan, object $account, string $action, string $comment = ''): void
    {
        $logs = $plan['logs'] ?? [];
        $logs[] = ['ts' => now()->toDateTimeString(), 'actor' => $account->name ?: $account->username, 'action' => $action, 'comment' => $comment];
        $plan['logs'] = array_slice($logs, -200);
    }

    /**
     * 懒推进：考核周期结束次日（服务器当天日期严格晚于 periodEnd）仍处于 ongoing 的单，
     * 自动转入数据填报(report)。不依赖定时任务，列表/详情读取时统一触发；
     * 仅推进 ongoing，推进后即为 report，天然幂等、不会重复记日志。
     */
    private function autoAdvance(): void
    {
        $today = now()->format('Y-m-d');
        $rows = DB::table('performance_plans')->where('status', 'ongoing')->get();
        foreach ($rows as $row) {
            $plan = $this->jsonValue($row->data) ?: [];
            if (($plan['status'] ?? '') !== 'ongoing') continue;
            $end = trim((string) ($plan['periodEnd'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) continue;
            if ($today <= $end) continue; // 结束次日才推进：当天日期必须严格晚于结束日
            $plan['status'] = 'report';
            $plan['reportStartedAt'] = now()->toDateTimeString();
            $plan['autoAdvance'] = true;
            $logs = $plan['logs'] ?? [];
            $logs[] = ['ts' => now()->toDateTimeString(), 'actor' => '系统',
                'action' => '考核周期结束，自动转入数据填报',
                'comment' => ($plan['periodStart'] ?? '') . ' ~ ' . $end . '，周期结束次日自动推进'];
            // 若没有任何指标指派了填报人（无人需要填报），直接进入发起人自评，避免在填报环节空转；
            // 指派了填报人的指标（含人工评分）一律停在填报，等填报人逐项提交
            if ($this->pendingReportCount($plan) === 0) {
                $plan['status'] = 'self';
                $logs[] = ['ts' => now()->toDateTimeString(), 'actor' => '系统',
                    'action' => '无指派填报人的指标，直接转入发起人自评', 'comment' => '所有指标均未指派数据填报人'];
            }
            $plan['logs'] = array_slice($logs, -200);
            $this->storePlan($plan);
        }
    }

    /**
     * 统计仍需填报的指标数：指派了数据填报人(reporterId)且尚未提交(reportBy 为空)的指标；
     * 人工评分项同样需要填报人确认提交；未指派填报人的指标视为无需填报。
     */
    private function pendingReportCount(array $plan): int
    {
        $n = 0;
        foreach (($plan['categories'] ?? []) as $cat) {
            foreach (($cat['items'] ?? []) as $it) {
                if (empty($it['reporterId'])) continue;
                if (!empty($it['reportBy'])) continue;
                $n++;
            }
        }
        return $n;
    }

    /** 给列表/详情补展示字段 */
    private function decoratePlan(array $p): array
    {
        $this->applyScores($p);
        $p['statusLabel'] = self::STATUS[$p['status'] ?? ''] ?? ($p['status'] ?? '');
        $p['quarter'] = !empty($p['periodStart']) ? $this->quarterOf((string) $p['periodStart']) : null;
        $ws = 0.0;
        foreach (($p['categories'] ?? []) as $c) foreach (($c['items'] ?? []) as $it) { $w = (float) ($it['weight'] ?? 0); if ($w) $ws += $w; }
        $p['weightSum'] = round($ws, 2);
        $step = (int) ($p['currentStep'] ?? 0);
        $approvers = [];
        foreach (($p['approvers'] ?? []) as $i => $a) {
            $state = 'pending';
            if (($p['status'] ?? '') === 'done') $state = 'approved';
            elseif ($i < $step) $state = 'approved';
            elseif ($i === $step && ($p['status'] ?? '') === 'approve') $state = 'current';
            elseif (in_array(($p['status'] ?? ''), ['confirm'], true) && $i === 0) $state = 'current';
            $approvers[] = ['staffId' => (int) ($a['staffId'] ?? 0), 'userId' => (int) ($a['userId'] ?? 0), 'name' => (string) ($a['name'] ?? ''), 'state' => $state];
        }
        $p['approvers'] = $approvers;
        $p['approverName'] = isset($p['approvers'][$step]) ? (string) ($p['approvers'][$step]['name'] ?? '') : '';
        $p['approverOpinions'] = $p['approverOpinions'] ?? [];
        $p['logs'] = $p['logs'] ?? [];
        return $p;
    }

    private function plan(int $id): ?array
    {
        $row = DB::table('performance_plans')->where('legacy_id', $id)->first();
        return $row ? ($this->jsonValue($row->data) ?: []) : null;
    }

    private function storePlan(array $plan): void
    {
        DB::table('performance_plans')->where('legacy_id', (int) $plan['id'])->update([
            'status' => $plan['status'] ?? 'draft', 'employee_id' => $plan['employeeId'] ?? null,
            'employee_name' => $plan['employeeName'] ?? null, 'project_name' => $plan['project'] ?? null,
            'year' => $plan['year'] ?? null, 'quarter' => $plan['quarter'] ?? null,
            'data' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
    }

    private function missingPlan(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => '考核单不存在'], 404);
    }
}
