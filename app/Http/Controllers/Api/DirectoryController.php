<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DirectoryController extends ApiController
{
    public function init(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $projects = DB::table('payroll_projects')->where('status', '启用');
        if ($this->isProjectScope($account)) {
            $projects->where('name', $account->project_name);
        }
        return response()->json([
            'ok' => true,
            'user' => ['username' => $account->username, 'name' => $account->name ?: $account->username,
                       'role' => $account->role, 'project' => $account->project_name ?: '',
                       'scope' => $this->isProjectScope($account) ? 'project' : 'all'],
            'projects' => $projects->pluck('name')->values()->all(),
            'all_projects' => $projects->get(),
            'roles' => DB::table('payroll_roles')->get(),
            'perm_modules' => \App\Services\AppModules::tree(),
            'app_modules' => \App\Services\AppModules::tree(),
            'settings' => [],
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $query = DB::table('payroll_projects');
        if ($this->isProjectScope($account)) {
            $query->where('name', $account->project_name);
        }
        return response()->json(['ok' => true, 'projects' => $query->get()]);
    }

    public function staff(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $query = DB::table('payroll_staff');
        if ($this->isProjectScope($account)) {
            $query->where('project_name', $account->project_name);
        } elseif ($request->filled('project')) {
            $query->where('project_name', $request->string('project'));
        }
        if ($request->filled('kw')) {
            $kw = $request->string('kw');
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', '%' . $kw . '%')->orWhere('position', 'like', '%' . $kw . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('person_type')) {
            $query->where('person_type', $request->string('person_type'));
        }
        if ($request->filled('org_id')) {
            $ids = (new \App\Services\OrgService())->descendants((int) $request->input('org_id'));
            $query->whereIn('org_id', $ids);
        } elseif ($request->filled('dept')) {
            $query->where('dept_path', 'like', '%' . $request->string('dept') . '%');
        }
        $rows = $query->orderBy('project_name')->orderBy('name')->get();
        $today = date('Y-m-d');
        $cat = (string) $request->input('cat', '');
        $archivedMarkers = $this->archivedMarkers($rows->pluck('legacy_id')->all());
        $staff = $rows->map(function ($row) use ($today, $archivedMarkers) {
            $legacy = $this->jsonValue($row->data) ?: [];
            $legacy['id'] = $row->legacy_id;
            $legacy['name'] = $row->name;
            $legacy['project'] = $row->project_name;
            $legacy['position'] = $row->position ?: '';
            $legacy['status'] = $row->status ?: '正式';
            $legacy['fixed_monthly'] = (float) $row->fixed_monthly;
            $legacy['base_salary'] = (float) $row->base_salary;
            $legacy['deleted'] = (bool) $row->deleted;
            // 钉钉绑定标记（布尔，不暴露 userid 本体）：前端据此禁用调薪等钉钉权威源字段的本地入口
            $legacy['dingtalk_bound'] = !empty($row->dingtalk_userid);
            $legacy['org_id'] = $row->org_id;
            $legacy['dept_path'] = $row->dept_path;
            $legacy['leader_id'] = $row->leader_id;
            $legacy['is_manager'] = (int) $row->is_manager;
            $legacy['is_case_field'] = (int) $row->is_case_field;
            $legacy['person_type'] = $row->person_type ?: 'staff';
            $legacy['person_type_since'] = $row->person_type_since;
            $legacy['hire_date'] = $row->hire_date ?: ($legacy['hire_date'] ?? null);
            $legacy['regular_date'] = $row->regular_date ?: ($legacy['regular_date'] ?? null);
            $legacy['resign_date'] = $row->resign_date ?: ($legacy['resign_date'] ?? null);
            $legacy['category'] = \App\Services\StaffCategory::derive((array) $row, $today);
            // 分类变更后已归档月份仍计在旧分类：人员列表可见标记（来源：最近一次分类迁移审计）
            $marker = $archivedMarkers[(int) $row->legacy_id] ?? null;
            $legacy['archived_months'] = $marker['months'] ?? [];
            $legacy['archived_notice'] = $marker['notice'] ?? '';
            $legacy['archived_from_label'] = $marker['from_label'] ?? '';
            return $legacy;
        });
        $cats = ['在职' => 0, '离职' => 0, '黑名单' => 0];
        foreach ($staff as $s) {
            if (isset($cats[$s['category']])) $cats[$s['category']]++;
        }
        if ($cat !== '') $staff = $staff->filter(fn ($s) => ($s['category'] ?? '') === $cat)->values();
        return response()->json(['ok' => true, 'staff' => $staff, 'counts' => $cats]);
    }

    /**
     * 人员列表「已归档，仍计在旧分类」标记。
     * 来源：最近一次分类迁移审计（迁移服务每次都全量重算该人的已归档月份，
     * 因此最新一条即当前真实状态；分类改回后该记录为空，标记自动消失）。
     *
     * @param  array<int, mixed> $ids
     * @return array<int, array{months: array<int, string>, from_label: string, notice: string}>
     */
    private function archivedMarkers(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $latest = [];
        $rows = DB::table('payroll_staff_type_migrations')
            ->whereIn('staff_legacy_id', $ids)
            ->orderBy('id')
            ->get(['staff_legacy_id', 'from_type', 'archived_months']);
        foreach ($rows as $row) $latest[(int) $row->staff_legacy_id] = $row;

        $markers = [];
        foreach ($latest as $id => $row) {
            $months = json_decode((string) ($row->archived_months ?? '[]'), true);
            if (!is_array($months) || !$months) continue;
            $months = array_values(array_map('strval', $months));
            $fromLabel = \App\Services\StaffCategoryMigration::LABELS[$row->from_type] ?? (string) $row->from_type;
            $markers[$id] = [
                'months' => $months,
                'from_label' => $fromLabel,
                'notice' => implode('、', $months) . ' 已归档，仍计在' . $fromLabel,
            ];
        }
        return $markers;
    }
}
