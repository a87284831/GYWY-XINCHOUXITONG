<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AdminController extends ApiController
{
    public function logs(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'op_logs.json')->first();
        $logs = $snapshot ? ($this->jsonValue($snapshot->payload) ?: []) : [];
        return response()->json(['ok' => true, 'logs' => array_reverse(array_slice($logs, -200))]);
    }

    public function backup(Request $request)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $name = 'backup_' . now()->format('Ymd_His') . '.zip'; $relative = 'backups/' . $name;
        $path = Storage::disk('local')->path($relative); Storage::disk('local')->makeDirectory('backups');
        $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        // 1. JSON 快照（配置）
        foreach (DB::table('legacy_json_snapshots')->get() as $snapshot) $zip->addFromString($snapshot->file_name, json_encode($this->jsonValue($snapshot->payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // 2. MySQL 业务表（新增，修复"备份不覆盖业务表"缺口）
        foreach (['payroll_staff', 'payroll_projects', 'payroll_roles', 'payroll_accounts',
                     'payroll_attendance', 'payroll_results', 'payroll_budgets', 'payroll_salary_adjustments',
                     'maintenance_partners', 'maintenance_contracts', 'performance_plans',
                     'org_nodes', 'org_transfer_logs'] as $table) {
            try {
                $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
                $zip->addFromString($table . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (\Throwable $e) {
                // 表可能不存在（如未迁移），跳过
            }
        }
        // 3. 采购系统业务表（gy_procurement 独立库，与一键清除范围一致）
        $procTables = [
            'purchase_items', 'archived_purchases', 'audit_records', 'budget_plan',
            'monthly_archive', 'notifications', 'op_logs', 'products', 'product_synonyms',
        ];
        try {
            $pdo = $this->procDb();
            foreach ($procTables as $t) {
                try {
                    $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(\PDO::FETCH_ASSOC);
                    $zip->addFromString('proc_' . $t . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } catch (\Throwable $e) {
                    // 表不存在则跳过
                }
            }
        } catch (\Throwable $e) {
            // 采购库连接失败不影响平台备份
        }
        $zip->close(); return response()->json(['ok' => true, 'file' => $name]);
    }

    /** gy_procurement 采购库 PDO 连接（与采购模块同一套配置） */
    private function procDb(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $d = config('procurement', []);
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $d['host'] ?? '127.0.0.1', (int) ($d['port'] ?? 3306), $d['dbname'] ?? 'gy_procurement'),
                $d['user'] ?? 'payroll', (string) ($d['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        }
        return $pdo;
    }

    public function backups(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $files = collect(Storage::disk('local')->files('backups'))->filter(fn ($file) => str_ends_with($file, '.zip'))->map(fn ($file) => ['name' => basename($file), 'size' => Storage::disk('local')->size($file), 'ts' => date('Y-m-d H:i:s', Storage::disk('local')->lastModified($file))])->values();
        return response()->json(['ok' => true, 'backups' => $files]);
    }


    /** POST /api/admin/clear-data — 一键清除业务数据（保留账号/角色/系统配置/审批流设计/组织架构）
     *  body: { backup: true|false }  清除前是否先自动备份
     *  清除范围：人员档案、考勤、工资核算、工资调整、预算、绩效、审批单(含草稿/分享)、
     *            入职办理清单、维保合同/合作方、调动日志、系统消息、项目档案表；
     *            采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、
     *            操作日志、标准商品库（含别名）；
     *            之后从组织架构启用项目自动重建项目档案（项目以组织架构为准）。
     */
    public function clearData(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);

        $backupFile = null;
        if ((bool)$request->input('backup', true)) {
            $resp = $this->backup($request);
            $data = $resp->getData();
            if (!empty($data->file)) $backupFile = $data->file;
        }

        $tables = [
            'payroll_staff', 'payroll_attendance', 'payroll_results', 'payroll_salary_adjustments',
            'payroll_budgets', 'payroll_projects', 'performance_plans',
            'maintenance_partners', 'maintenance_contracts',
            'approval_instances', 'approval_drafts', 'approval_shares', 'onboard_checklists',
            'org_transfer_logs', 'app_messages',
        ];
        $cleared = [];
        foreach ($tables as $t) {
            try {
                $n = DB::table($t)->count();
                DB::table($t)->delete();
                $cleared[$t] = $n;
            } catch (\Throwable $e) {
                $cleared[$t] = 'ERR: ' . $e->getMessage();
            }
        }
        // 采购系统业务数据（gy_procurement 独立库；保留项目/账号/填报窗口/系统设置等配置）
        $procTables = [
            'purchase_items', 'archived_purchases', 'audit_records', 'budget_plan',
            'monthly_archive', 'notifications', 'op_logs', 'products', 'product_synonyms',
        ];
        try {
            $pdo = $this->procDb();
            foreach ($procTables as $t) {
                try {
                    $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    $pdo->exec("DELETE FROM `{$t}`");
                    $cleared['proc_' . $t] = $n;
                } catch (\Throwable $e) {
                    $cleared['proc_' . $t] = 'ERR: ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $cleared['proc_purchase'] = 'ERR: ' . $e->getMessage();
        }
        // 账号与人员解绑（账号保留，人员已清空）
        DB::table('payroll_accounts')->update(['staff_id' => null]);
        // 清空操作日志
        DB::table('legacy_json_snapshots')->where('file_name', 'op_logs.json')
            ->update(['payload' => json_encode([])]);
        // 从组织架构启用项目重建项目档案（保持全系统项目下拉可用，项目以组织架构为准）
        $now = now();
        foreach (DB::table('org_nodes')->where('type', 'project')->where('enabled', true)->get() as $pr) {
            if (DB::table('payroll_projects')->where('name', $pr->name)->exists()) continue;
            DB::table('payroll_projects')->insert([
                'name' => $pr->name, 'status' => '启用',
                'data' => json_encode([
                    'name' => $pr->name, 'note' => $pr->note, 'phone' => $pr->phone,
                    'status' => '启用', 'address' => $pr->address,
                    'aliases' => $this->jsonValue($pr->aliases) ?: [], 'contact' => $pr->contact,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return response()->json(['ok' => true, 'backup' => $backupFile, 'cleared' => $cleared]);
    }

    public function downloadBackup(Request $request)
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $name = basename((string) $request->input('f')); $path = 'backups/' . $name;
        if ($name === '' || !Storage::disk('local')->exists($path)) return response()->json(['ok' => false, 'error' => '文件不存在'], 404);
        return Storage::disk('local')->download($path, $name, ['Content-Type' => 'application/zip']);
    }

    public function users(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $rows = DB::table('payroll_accounts')->orderBy('legacy_id')->get();
        $staffMap = DB::table('payroll_staff')->select('legacy_id', 'name', 'project_name', 'dept_path', 'deleted')->get()
            ->keyBy('legacy_id');
        $users = $rows->map(function ($a) use ($staffMap) {
            $bound = isset($a->staff_id) ? ($staffMap[$a->staff_id] ?? null) : null;
            return [
                'id' => $a->legacy_id, 'username' => $a->username, 'name' => $a->name,
                'role' => $a->role, 'project' => $a->project_name,
                'staff_id' => $a->staff_id, 'enabled' => (bool) $a->enabled,
                'staff_name' => $bound?->name, 'staff_dept' => $bound?->dept_path,
                'staff_deleted' => $bound ? (bool) $bound->deleted : null,
            ];
        });
        return response()->json(['ok' => true, 'users' => $users]);
    }

    public function roles(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $roles = DB::table('payroll_roles')->get()->map(function ($role) {
            $role->id = $role->role_key;
            $role->perms = $this->jsonValue($role->permissions) ?: [];
            unset($role->role_key, $role->permissions);
            return $role;
        });
        return response()->json(['ok' => true, 'roles' => $roles, 'app_modules' => \App\Services\AppModules::tree()]);
    }

    public function saveUser(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $input = $request->input('user');
        if (!is_array($input) || trim((string) ($input['username'] ?? '')) === '') {
            return response()->json(['ok' => false, 'error' => '账号数据无效'], 400);
        }
        $role = DB::table('payroll_roles')->where('role_key', $input['role'] ?? 'viewer')->first();
        if (!$role) return response()->json(['ok' => false, 'error' => '账号类型不存在'], 400);
        $id = (int) ($input['id'] ?? 0);
        // 绑定人员（内置 admin 豁免，其余账号必须绑定人员）
        $staffIdProvided = array_key_exists('staff_id', $input);
        $staffId = $staffIdProvided && $input['staff_id'] !== '' && $input['staff_id'] !== null ? (int) $input['staff_id'] : null;
        if (!$staffIdProvided && $id) {
            $staffId = (int) (DB::table('payroll_accounts')->where('legacy_id', $id)->value('staff_id') ?? 0) ?: null;
        }
        $bound = null;
        if ($role->role_key !== 'admin') {
            if ($staffId) {
                $bound = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
                if (!$bound) return response()->json(['ok' => false, 'error' => '绑定人员不存在'], 400);
            } elseif (!$id) {
                return response()->json(['ok' => false, 'error' => '请先选择要绑定的人员（一人一号）'], 400);
            }
            // 一人一号唯一约束
            $dupQuery = DB::table('payroll_accounts')->where('staff_id', $staffId)->whereNotNull('staff_id');
            if ($id) $dupQuery->where('legacy_id', '!=', $id);
            if ($staffId && $dupQuery->exists()) {
                return response()->json(['ok' => false, 'error' => '该人员已开通账号，一人一号不可重复开通'], 409);
            }
        }
        // 项目由所绑人员自动带出（项目账号不再手选）
        if ($role->scope === 'project') {
            $project = $bound ? $bound->project_name : trim((string) ($input['project'] ?? ''));
            if ($project === '') return response()->json(['ok' => false, 'error' => '项目账号必须绑定项目（或选择已绑定人员自动带出）'], 400);
        } else {
            $project = $bound ? $bound->project_name : null;
            // 老账号（未绑定人员）切到只读/员工自助等类型时保留原项目，避免丢失后无法改回项目账号
            if ($id && $project === null) {
                $oldProject = DB::table('payroll_accounts')->where('legacy_id', $id)->value('project_name');
                if ($oldProject) $project = $oldProject;
            }
        }
        $data = ['name' => $bound ? $bound->name : trim((string) ($input['name'] ?? '')), 'role' => $role->role_key, 'project' => $project];
        $password = (string) ($input['password'] ?? '');
        $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
        if ($id) {
            $old = DB::table('payroll_accounts')->where('legacy_id', $id)->first();
            if (!$old) return response()->json(['ok' => false, 'error' => '账号不存在'], 404);
            // 行内快速改类型等部分更新场景未传 enabled 时，保留原启用状态，避免把已停用账号意外启用
            if (!array_key_exists('enabled', $input)) $enabled = (bool) $old->enabled;
            $update = ['name' => $data['name'], 'role' => $data['role'], 'project_name' => $project,
                'staff_id' => $staffId, 'enabled' => $enabled, 'updated_at' => now()];
            if ($password !== '') {
                if (strlen($password) < 8) return response()->json(['ok' => false, 'error' => '密码至少8位'], 400);
                $update['password_hash'] = hash('sha256', 'gwxy_' . $password);
            }
            DB::table('payroll_accounts')->where('legacy_id', $id)->update($update);
            return response()->json(['ok' => true]);
        }
        if (strlen($password) < 8) return response()->json(['ok' => false, 'error' => '密码至少8位'], 400);
        if (DB::table('payroll_accounts')->where('username', $input['username'])->exists()) {
            return response()->json(['ok' => false, 'error' => '用户名已存在'], 409);
        }
        $newId = (int) (DB::table('payroll_accounts')->max('legacy_id') ?? 0) + 1;
        // 落库 data 前剔除明文密码，避免密码被持久化到 JSON 扩展字段
        $dataInput = $input; unset($dataInput['password']);
        DB::table('payroll_accounts')->insert(['legacy_id' => $newId, 'username' => $input['username'],
            'name' => $data['name'], 'role' => $data['role'], 'project_name' => $project,
            'staff_id' => $staffId, 'enabled' => $enabled,
            'password_hash' => hash('sha256', 'gwxy_' . $password), 'data' => json_encode($dataInput, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'id' => $newId]);
    }

    /** POST /api/users/status — 账号启用/停用 */
    public function setUserStatus(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        $id = (int) $request->input('id');
        if (DB::table('payroll_accounts')->where('legacy_id', $id)->doesntExist()) {
            return response()->json(['ok' => false, 'error' => '账号不存在'], 404);
        }
        DB::table('payroll_accounts')->where('legacy_id', $id)->update([
            'enabled' => (bool) $request->input('enabled', true), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function saveRoles(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '无权'], 403);
        foreach ((array) $request->input('roles', []) as $role) {
            $id = trim((string) ($role['id'] ?? ''));
            $name = trim((string) ($role['name'] ?? ''));
            if ($id === '' || $name === '') continue;
            DB::table('payroll_roles')->updateOrInsert(['role_key' => $id], [
                'name' => $name, 'scope' => in_array($role['scope'] ?? 'all', ['all', 'project'], true) ? $role['scope'] : 'all',
                'permissions' => json_encode($role['perms'] ?? [], JSON_UNESCAPED_UNICODE),
                'data' => json_encode($role, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $old = (string) $request->input('old'); $new = (string) $request->input('new');
        if (strlen($new) < 8) return response()->json(['ok' => false, 'error' => '新密码至少8位'], 400);
        if (!hash_equals((string) $account->password_hash, hash('sha256', 'gwxy_' . $old))) {
            return response()->json(['ok' => false, 'error' => '原密码错误'], 400);
        }
        DB::table('payroll_accounts')->where('id', $account->id)->update([
            'password_hash' => hash('sha256', 'gwxy_' . $new), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }
}
