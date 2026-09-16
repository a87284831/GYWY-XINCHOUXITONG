<?php

namespace App\Http\Controllers\Api;

use App\Services\OrgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrgController extends ApiController
{
    public function __construct(private readonly OrgService $org) {}

    /** GET /api/org/tree — 组织树（含各节点人数统计） */
    public function tree(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $nodes = DB::table('org_nodes')->orderBy('sort_order')->get()->all();
        $staff = DB::table('payroll_staff')->select('org_id', 'deleted', 'project_name')->get();
        $counts = ['in' => [], 'out' => []];
        foreach ($staff as $s) {
            if ($s->org_id === null) continue;
            $key = (int)$s->org_id;
            $counts['in'][$key] = ($counts['in'][$key] ?? 0) + ((bool)$s->deleted ? 0 : 1);
            $counts['out'][$key] = ($counts['out'][$key] ?? 0) + ((bool)$s->deleted ? 1 : 0);
        }
        $byParent = [];
        foreach ($nodes as $n) {
            $byParent[(string)($n->parent_id ?? 'root')][] = $n;
        }
        $build = function ($parentId) use (&$build, $byParent, $counts) {
            $list = $byParent[$parentId] ?? [];
            $out = [];
            foreach ($list as $n) {
                if (!empty($n->hidden)) continue; // 隐藏节点：整棵子树不显示
                $children = $build((string)$n->id);
                $id = (int)$n->id;
                $out[] = [
                    'id' => $id, 'parent_id' => $n->parent_id, 'type' => $n->type, 'name' => $n->name,
                    'code' => $n->code, 'manager_staff_id' => $n->manager_staff_id,
                    'sort_order' => $n->sort_order, 'enabled' => (bool)$n->enabled,
                    'hidden' => (bool)($n->hidden ?? false),
                    'contact' => $n->contact, 'phone' => $n->phone, 'address' => $n->address,
                    'aliases' => $this->jsonValue($n->aliases) ?: [], 'note' => $n->note,
                    'path' => $this->org->path($id),
                    'count_in' => $counts['in'][$id] ?? 0, 'count_out' => $counts['out'][$id] ?? 0,
                    'children' => $children,
                ];
            }
            return $out;
        };
        $tree = $build('root');
        // 项目档案信息并入 project 节点供右侧详情展示
        return response()->json(['ok' => true, 'tree' => $tree,
            'projects' => DB::table('payroll_projects')->orderBy('id')->get()]);
    }

    /** POST /api/org/node — 新增节点（type 由父节点自动限定） */
    public function createNode(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可维护组织'], 403);
        $parentId = (int)$request->input('parent_id');
        $name = trim((string)$request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => '节点名称不能为空'], 400);
        $parent = DB::table('org_nodes')->where('id', $parentId)->first();
        if (!$parent) return response()->json(['ok' => false, 'error' => '父节点不存在'], 404);
        $type = $request->input('type') ?: $this->org->allowedChildType($parent->type);
        $allowed = $this->org->allowedChildType($parent->type);
        // 允许显式降级（项目下可直接建 team，区域下可建 project，公司下可直接建 project 兼容无区域层模式）
        $okTypes = [$allowed];
        if ($parent->type === 'company') $okTypes[] = 'project';
        if ($parent->type === 'project') $okTypes[] = 'team';
        if ($parent->type === 'region') { $okTypes[] = 'project'; }
        if (!in_array($type, $okTypes, true)) {
            return response()->json(['ok' => false, 'error' => "{$parent->name} 下不允许创建 {$type} 类型"], 400);
        }
        $projectNode = $this->org->projectOf($parentId);
        if ($projectNode && $projectNode->id !== $parentId) {
            $dup = DB::table('org_nodes')->where('parent_id', $parentId)->where('name', $name)->exists();
            if ($dup) return response()->json(['ok' => false, 'error' => '同级下已存在同名节点'], 409);
        }
        $maxOrder = (int)(DB::table('org_nodes')->where('parent_id', $parentId)->max('sort_order') ?? 0);
        $nodeId = DB::table('org_nodes')->insertGetId([
            'parent_id' => $parentId, 'type' => $type, 'name' => $name,
            'code' => $request->input('code') ?: null, 'manager_staff_id' => $request->input('manager_staff_id') ?: null,
            'sort_order' => $maxOrder + 1, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 新增项目节点时同步项目档案（人员档案/考勤等项目下拉以 payroll_projects 为数据源），并自动建标准部门
        if ($type === 'project') {
            DB::table('payroll_projects')->updateOrInsert(
                ['name' => $name],
                ['status' => '启用', 'data' => json_encode(['name' => $name, 'status' => '启用'], JSON_UNESCAPED_UNICODE),
                 'updated_at' => now(), 'created_at' => now()]
            );
            foreach (\App\Services\OrgService::STANDARD_DEPTS as $i => $deptName) {
                $exists = DB::table('org_nodes')->where('parent_id', $nodeId)->where('name', $deptName)->exists();
                if ($exists) continue;
                DB::table('org_nodes')->insertGetId([
                    'parent_id' => $nodeId, 'type' => 'department', 'name' => $deptName,
                    'sort_order' => $i + 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        return response()->json(['ok' => true, 'id' => $nodeId]);
    }

    /** POST /api/org/node/update — 更新节点属性 */
    public function updateNode(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可维护组织'], 403);
        $id = (int)$request->input('id');
        $node = DB::table('org_nodes')->where('id', $id)->first();
        if (!$node) return response()->json(['ok' => false, 'error' => '节点不存在'], 404);
        $name = trim((string)$request->input('name', $node->name));
        if ($name === '') return response()->json(['ok' => false, 'error' => '节点名称不能为空'], 400);
        $dup = DB::table('org_nodes')->where('parent_id', $node->parent_id)->where('name', $name)
            ->where('id', '!=', $id)->exists();
        if ($dup) return response()->json(['ok' => false, 'error' => '同级下已存在同名节点'], 409);

        $oldName = $node->name;
        $oldType = $node->type;
        DB::table('org_nodes')->where('id', $id)->update([
            'name' => $name,
            'code' => $request->input('code') ?: $node->code,
            'manager_staff_id' => $request->input('manager_staff_id') ?: $node->manager_staff_id,
            'contact' => $request->input('contact') ?: $node->contact,
            'phone' => $request->input('phone') ?: $node->phone,
            'address' => $request->input('address') ?: $node->address,
            'aliases' => json_encode($request->input('aliases', $this->jsonValue($node->aliases) ?: []), JSON_UNESCAPED_UNICODE),
            'note' => $request->input('note') ?: $node->note,
            'hidden' => (bool)$request->input('hidden', (bool)($node->hidden ?? false)),
            'updated_at' => now(),
        ]);
        if ($oldName !== $name) $this->refreshDeptPaths($id);
        return response()->json(['ok' => true]);
    }

    /** POST /api/org/node/move — 移动节点（换父级/排序） */
    public function moveNode(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可维护组织'], 403);
        $id = (int)$request->input('id');
        $node = DB::table('org_nodes')->where('id', $id)->first();
        if (!$node) return response()->json(['ok' => false, 'error' => '节点不存在'], 404);
        if ($node->type === 'company') return response()->json(['ok' => false, 'error' => '根公司节点不可移动'], 400);
        $newParent = (int)$request->input('parent_id');
        if ($newParent && DB::table('org_nodes')->where('id', $newParent)->doesntExist()) {
            return response()->json(['ok' => false, 'error' => '目标父节点不存在'], 404);
        }
        if ($newParent) {
            // 防成环：不能移到自己的子孙下
            $desc = $this->org->descendants($id);
            if (in_array($newParent, $desc, true)) return response()->json(['ok' => false, 'error' => '不能移动到自身或其子孙节点下'], 400);
        }
        $parent = $newParent ? DB::table('org_nodes')->where('id', $newParent)->first() : null;
        if ($parent) {
            $allowed = $this->org->allowedChildType($parent->type);
            $okTypes = [$allowed];
            if ($parent->type === 'company') $okTypes[] = 'project';
            if ($parent->type === 'project') $okTypes[] = 'team';
            if (!in_array($node->type, $okTypes, true)) {
                return response()->json(['ok' => false, 'error' => "{$parent->name} 下不允许放置 {$node->type} 类型"], 400);
            }
        }
        DB::table('org_nodes')->where('id', $id)->update([
            'parent_id' => $newParent ?: null,
            'sort_order' => (int)$request->input('sort_order', $node->sort_order),
            'updated_at' => now(),
        ]);
        $this->refreshDeptPaths($id);
        return response()->json(['ok' => true]);
    }

    /** POST /api/org/node/status — 启用/停用 */
    public function setStatus(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可维护组织'], 403);
        $id = (int)$request->input('id');
        if (DB::table('org_nodes')->where('id', $id)->doesntExist()) {
            return response()->json(['ok' => false, 'error' => '节点不存在'], 404);
        }
        DB::table('org_nodes')->where('id', $id)->update([
            'enabled' => (bool)$request->input('enabled', true), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    /** POST /api/org/node/delete — 删除节点（保护：有启用人员/未归档业务时禁止） */
    public function deleteNode(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可维护组织'], 403);
        $id = (int)$request->input('id');
        $node = DB::table('org_nodes')->where('id', $id)->first();
        if (!$node) return response()->json(['ok' => false, 'error' => '节点不存在'], 404);
        if ($node->type === 'company') return response()->json(['ok' => false, 'error' => '根公司节点不可删除'], 400);
        $desc = $this->org->descendants($id);
        $descIds = array_map('intval', $desc);
        $activeStaff = DB::table('payroll_staff')->whereIn('org_id', $descIds)->where('deleted', false)->count();
        if ($activeStaff > 0) {
            return response()->json(['ok' => false, 'error' => "该节点及子孙下仍有 {$activeStaff} 名在职人员，请先调动或停用，不能删除"], 400);
        }
        // descendants 含自身；除自身外仍有任意子孙即非叶子，禁止删除（否则会留下 parent 悬空的孤儿节点）
        $childCount = count($descIds) - 1;
        if ($childCount > 0) {
            return response()->json(['ok' => false, 'error' => "该节点下仍有 {$childCount} 个下级节点，请按从末级到上级的顺序先删除全部下级"], 400);
        }
        // 项目节点有未归档业务时禁止删除
        if ($node->type === 'project') {
            $openResults = DB::table('payroll_results')->where('project_name', $node->name)->where('archived', false)->count();
            $hasContracts = DB::table('maintenance_contracts')->where('project_name', $node->name)->count();
            if ($openResults > 0 || $hasContracts > 0) {
                return response()->json(['ok' => false, 'error' => '该项目仍有未归档核算或维保合同，只能停用'], 400);
            }
        }
        DB::table('org_nodes')->where('id', $id)->delete();
        // 项目节点删除时同步停用项目档案（列与 data JSON 状态保持一致，避免下拉取数列时与档案详情不一致）
        if ($node->type === 'project') {
            $proj = DB::table('payroll_projects')->where('name', $node->name)->first();
            if ($proj) {
                $pd = json_decode((string) $proj->data, true) ?: [];
                $pd['status'] = '停用';
                DB::table('payroll_projects')->where('name', $node->name)->update([
                    'status' => '停用', 'data' => json_encode($pd, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
                ]);
            }
        }
        return response()->json(['ok' => true]);
    }

    /** POST /api/org/migrate — 老数据自动迁移 */
    public function migrate(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可执行迁移'], 403);
        $result = $this->org->migrate($account->username);
        if (!empty($result['error'])) {
            return response()->json(['ok' => false, 'error' => $result['error']], 400);
        }
        return response()->json(['ok' => true, 'report' => $result['report']]);
    }

    /** GET /api/org/staff?node_id=..&kw=.. — 节点下人员（含子孙），带部门筛选 */
    public function staffUnder(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('payroll_staff');
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        } elseif ($request->filled('project')) {
            $query->where('project_name', $request->string('project'));
        }
        if ($request->filled('org_id')) {
            $ids = $this->org->descendants((int)$request->input('org_id'));
            $query->whereIn('org_id', $ids);
        } elseif ($request->filled('dept')) {
            $query->where('dept_path', 'like', '%' . $request->string('dept') . '%');
        }
        if ($request->filled('kw')) {
            $kw = $request->string('kw');
            $query->where(fn($q) => $q->where('name', 'like', '%' . $kw . '%')
                ->orWhere('position', 'like', '%' . $kw . '%'));
        }
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        $rows = $query->orderBy('project_name')->orderBy('name')->get();
        $staff = $rows->map(fn($row) => [
            'id' => $row->legacy_id, 'name' => $row->name, 'project' => $row->project_name,
            'position' => $row->position ?: '', 'status' => $row->status ?: '正式',
            'org_id' => $row->org_id, 'dept_path' => $row->dept_path, 'leader_id' => $row->leader_id,
            'deleted' => (bool)$row->deleted,
            'category' => \App\Services\StaffCategory::derive((array)$row, date('Y-m-d')),
            'fixed_monthly' => (float)$row->fixed_monthly, 'base_salary' => (float)$row->base_salary,
        ]);
        return response()->json(['ok' => true, 'staff' => $staff]);
    }

    /** POST /api/org/staff/move — 人员调入部门（写调动记录） */
    public function moveStaff(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可调整人员部门'], 403);
        $staffId = (int)$request->input('staff_id');
        $toOrgId = (int)$request->input('org_id');
        $staff = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
        if (!$staff) return response()->json(['ok' => false, 'error' => '人员不存在'], 404);
        $node = DB::table('org_nodes')->where('id', $toOrgId)->first();
        if (!$node || !OrgService::isLeafType($node->type)) {
            return response()->json(['ok' => false, 'error' => '目标必须是部门或班组节点'], 400);
        }
        $projectNode = $this->org->projectOf($toOrgId);
        if (!$projectNode) return response()->json(['ok' => false, 'error' => '目标节点未归属任何项目'], 400);
        $fromPath = (string)$staff->dept_path;
        $toPath = $this->org->path($toOrgId);
        DB::table('payroll_staff')->where('legacy_id', $staffId)->update([
            'org_id' => $toOrgId, 'dept_path' => $toPath,
            'project_name' => $projectNode->name, 'updated_at' => now(),
        ]);
        if ((int)$staff->org_id !== $toOrgId || $fromPath !== $toPath) {
            DB::table('org_transfer_logs')->insert([
                'staff_legacy_id' => $staffId, 'staff_name' => $staff->name,
                'from_org_id' => $staff->org_id, 'to_org_id' => $toOrgId,
                'from_path' => $fromPath, 'to_path' => $toPath,
                'change_date' => now()->toDateString(), 'reason' => $request->input('reason') ?: '部门调整',
                'by_user' => $account->username, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return response()->json(['ok' => true, 'dept_path' => $toPath]);
    }

    /** GET /api/org/transfer-logs?staff_id=.. — 人员调动履历 */
    public function transferLogs(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('org_transfer_logs')->orderByDesc('id');
        if ($request->filled('staff_id')) $query->where('staff_legacy_id', (int)$request->input('staff_id'));
        return response()->json(['ok' => true, 'logs' => $query->limit(200)->get()]);
    }

    /** GET /api/org/positions?node_id=.. — 部门常用岗位（去重自该部门人员） */
    public function positions(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('payroll_staff')->whereNotNull('position')->where('position', '!=', '');
        if ($request->filled('node_id')) {
            $query->whereIn('org_id', $this->org->descendants((int)$request->input('node_id')));
        } elseif ($request->filled('project')) {
            $query->where('project_name', $request->string('project'));
        }
        $positions = $query->distinct()->pluck('position')->values()->all();
        return response()->json(['ok' => true, 'positions' => $positions]);
    }

    /** GET /api/org/staff-options — 可选人员（含部门/岗位），供选领导/选人绑定 */
    public function staffOptions(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('payroll_staff')->where('deleted', false);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        if ($request->filled('kw')) {
            $kw = $request->string('kw');
            $query->where(fn($q) => $q->where('name', 'like', '%' . $kw . '%')
                ->orWhere('position', 'like', '%' . $kw . '%'));
        }
        $rows = $query->orderBy('project_name')->orderBy('name')->limit(300)->get();
        return response()->json(['ok' => true, 'staff' => $rows->map(function ($r) {
            $d = $this->jsonValue($r->data) ?: [];
            return [
                'id' => $r->legacy_id, 'name' => $r->name, 'project' => $r->project_name,
                'position' => $r->position ?: '', 'dept_path' => $r->dept_path,
                'level' => (string) ($d['level'] ?? ''),
                'department' => (string) ($d['department'] ?? ($r->dept_path ?? '')),
                'hire_date' => (string) ($r->hire_date ?? ''),
                'regular_date' => (string) ($r->regular_date ?? ''),
                'fixed_monthly' => (string) ($r->fixed_monthly ?? ''),
                'base_salary' => (string) ($r->base_salary ?? ''),
                'emergency_contact' => (string) ($d['emergency_contact'] ?? ''),
                'emergency_phone' => (string) ($d['emergency_phone'] ?? ''),
                'education' => (string) ($d['education'] ?? ''),
                'ethnicity' => (string) ($d['ethnicity'] ?? ''),
            ];
        })]);
    }

    /** GET /api/org/accounts-pending — 待关联账号清单 */
    public function accountsPending(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $pending = DB::table('payroll_accounts')->where('username', '!=', 'admin')
            ->whereNull('staff_id')->get();
        return response()->json(['ok' => true, 'accounts' => $pending->map(fn($a) => [
            'id' => $a->legacy_id, 'username' => $a->username, 'name' => $a->name,
            'role' => $a->role, 'enabled' => (bool)$a->enabled,
        ])]);
    }

    /** 级联刷新某节点及其子孙下人员的 dept_path 快照 */
    private function refreshDeptPaths(int $nodeId): void
    {
        $desc = $this->org->descendants($nodeId);
        foreach ($desc as $id) {
            $path = $this->org->path($id);
            DB::table('payroll_staff')->where('org_id', $id)->update(['dept_path' => $path, 'updated_at' => now()]);
        }
    }
}
