<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends ApiController
{
    public function __construct(private readonly \App\Services\OrgService $org) {}

    public function save(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可修改项目'], 403);
        $input = $request->input('project', []); $name = trim((string) ($input['name'] ?? '')); $original = trim((string) ($input['_orig'] ?? ''));
        if ($name === '') return response()->json(['ok' => false, 'error' => '项目名称必填'], 400);
        if ($request->input('action') === 'delete') {
            if (DB::table('payroll_staff')->where('project_name', $name)->where('deleted', false)->exists()) return response()->json(['ok' => false, 'error' => '项目下仍有员工，不能删除'], 400);
            DB::table('payroll_projects')->where('name', $name)->delete();
            // 级联删除项目组织子树（含其下部门），避免留下 parent 悬空的孤儿部门
            $node = DB::table('org_nodes')->where('type', 'project')->where('name', $name)->first();
            if ($node) {
                $subIds = $this->org->descendants((int) $node->id);
                DB::table('org_nodes')->whereIn('id', array_map('intval', $subIds))->delete();
            }
            return response()->json(['ok' => true]);
        }
        if ($original && $original !== $name) {
            if (DB::table('payroll_projects')->where('name', $name)->exists()) return response()->json(['ok' => false, 'error' => '项目名称已存在'], 409);
            DB::transaction(function () use ($original, $name) {
                DB::table('payroll_projects')->where('name', $original)->update(['name' => $name, 'updated_at' => now()]);
                DB::table('payroll_staff')->where('project_name', $original)->update(['project_name' => $name, 'updated_at' => now()]);
                DB::table('payroll_attendance')->where('project_name', $original)->get()->each(function ($row) use ($name) { DB::table('payroll_attendance')->where('id', $row->id)->update(['project_name' => $name, 'record_key' => str_replace('|' . $original, '|' . $name, $row->record_key), 'updated_at' => now()]); });
                DB::table('payroll_budgets')->where('project_name', $original)->update(['project_name' => $name, 'updated_at' => now()]);
                DB::table('payroll_results')->where('project_name', $original)->update(['project_name' => $name, 'updated_at' => now()]);
                $node = DB::table('org_nodes')->where('type', 'project')->where('name', $original)->first();
                if ($node) {
                    DB::table('org_nodes')->where('id', $node->id)->update(['name' => $name, 'updated_at' => now()]);
                    $this->refreshStaffPaths($node->id, $name);
                }
            });
            return response()->json(['ok' => true]);
        }
        DB::table('payroll_projects')->updateOrInsert(['name' => $name], ['status' => $input['status'] ?? '启用', 'data' => json_encode($input, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]);
        // 同步组织树 project 节点（若组织已初始化）
        $projectNode = DB::table('org_nodes')->where('type', 'project')->where('name', $name)->first();
        if ($projectNode) {
            DB::table('org_nodes')->where('id', $projectNode->id)->update([
                'name' => $name, 'enabled' => ($input['status'] ?? '启用') !== '停用',
                'contact' => $input['contact'] ?? $projectNode->contact,
                'phone' => $input['phone'] ?? $projectNode->phone,
                'address' => $input['address'] ?? $projectNode->address,
                'aliases' => json_encode($input['aliases'] ?? ($this->jsonValue($projectNode->aliases) ?: []), JSON_UNESCAPED_UNICODE),
                'note' => $input['note'] ?? $projectNode->note,
                'updated_at' => now(),
            ]);
        } elseif (DB::table('org_nodes')->where('type', 'company')->exists()) {
            $companyId = (int) DB::table('org_nodes')->where('type', 'company')->value('id');
            $maxOrder = (int) (DB::table('org_nodes')->where('parent_id', $companyId)->max('sort_order') ?? 0);
            $nodeId = DB::table('org_nodes')->insertGetId([
                'parent_id' => $companyId, 'type' => 'project', 'name' => $name,
                'sort_order' => $maxOrder + 1, 'enabled' => ($input['status'] ?? '启用') !== '停用',
                'contact' => $input['contact'] ?? null, 'phone' => $input['phone'] ?? null,
                'address' => $input['address'] ?? null,
                'aliases' => json_encode($input['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
                'note' => $input['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // 新项目自动建 5 标准部门
            foreach (\App\Services\OrgService::STANDARD_DEPTS as $i => $deptName) {
                DB::table('org_nodes')->insertGetId([
                    'parent_id' => $nodeId, 'type' => 'department', 'name' => $deptName,
                    'sort_order' => $i + 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        return response()->json(['ok' => true]);
    }

    private function refreshStaffPaths(int $projectNodeId, string $newProjectName): void
    {
        $desc = $this->org->descendants($projectNodeId);
        foreach ($desc as $id) {
            if ($id === $projectNodeId) continue;
            $path = $this->org->path($id);
            DB::table('payroll_staff')->where('org_id', $id)->update(['dept_path' => $path, 'project_name' => $newProjectName, 'updated_at' => now()]);
        }
    }
}
