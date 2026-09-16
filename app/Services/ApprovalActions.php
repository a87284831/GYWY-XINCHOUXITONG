<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 审批通过后的业务联动动作 + 入职建档。
 *
 * 约定：审批表单字段 key 需遵循规范——
 *   录用 hire_approval：name/project/position/hire_date/fixed_monthly/base_salary/phone/recruit_channel
 *   转正 regular_approval：name/project/regular_date/position/fixed_monthly/base_salary
 *   离职 resign_approval：name/project/resign_date/resign_type/reason
 */
class ApprovalActions
{
    public static function handle(string $flowKey, array $formData, string $project, object $account, ?int $instanceId = null): array
    {
        return match ($flowKey) {
            'hire_approval' => self::createOnboardChecklist($formData, $project, $instanceId),
            'regular_approval' => self::applyRegular($formData, $project),
            'resign_approval' => self::applyResign($formData, $project),
            default => ['skipped' => 'unknown_flow'],
        };
    }

    /** 录用通过 → 生成入职办理清单（instanceId 由审批单显式传入，避免按姓名模糊匹配） */
    private static function createOnboardChecklist(array $formData, string $project, ?int $instanceId = null): array
    {
        $name = trim((string) ($formData['name'] ?? ''));
        if ($name === '') return ['error' => '缺少员工姓名'];
        $instanceId = $instanceId ?: (int) (DB::table('approval_instances')
            ->where('form_data', 'LIKE', '%"' . addcslashes($name, '"') . '"%')
            ->where('flow_key', 'hire_approval')
            ->orderByDesc('id')->value('id') ?? 0);
        $items = [
            ['key' => 'contract', 'label' => '签订劳动合同（含合同起止日）', 'done' => false, 'by' => ''],
            ['key' => 'physical', 'label' => '入职体检完成', 'done' => false, 'by' => ''],
            ['key' => 'supplies', 'label' => '工装/办公用品领用', 'done' => false, 'by' => ''],
            ['key' => 'policy', 'label' => '规章制度学习确认', 'done' => false, 'by' => ''],
        ];
        DB::table('onboard_checklists')->insert([
            'instance_id' => $instanceId ?: 0, 'staff_name' => $name,
            'project_name' => $project, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'extra' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 入职办理仅人力（admin）可操作，直接通知管理员
        $admins = DB::table('payroll_accounts')->where('role', 'admin')->where('enabled', true)->pluck('id')->all();
        MessageService::push($admins, 'notice', '新员工入职待办', "「{$name}」录用已通过，请到审批中心→入职办理 完成入职清单", 'approvalCenter', $project);
        return ['onboard' => true, 'name' => $name];
    }

    /** 解析联动目标员工：优先按表单携带的员工ID（name_id/staff_id）精确定位，杜绝同名误改；缺失时回退姓名+项目 */
    private static function resolveStaff(array $formData, string $project): ?object
    {
        $staffId = (int) ($formData['name_id'] ?? $formData['staff_id'] ?? 0);
        if ($staffId > 0) {
            $s = DB::table('payroll_staff')->where('legacy_id', $staffId)->where('deleted', false)->first();
            if ($s) return $s;
        }
        $name = trim((string) ($formData['name'] ?? ''));
        if ($name === '') return null;
        return DB::table('payroll_staff')->where('name', $name)
            ->when($project !== '', fn ($q) => $q->where('project_name', $project))
            ->where('deleted', false)->orderBy('legacy_id')->first();
    }

    /** 转正通过 → 按转正日期更新档案（精确关联员工） */
    private static function applyRegular(array $formData, string $project): array
    {
        $name = trim((string) ($formData['name'] ?? ''));
        $regularDate = trim((string) ($formData['regular_date'] ?? ''));
        if ($name === '' || $regularDate === '') return ['error' => '缺少姓名或转正日期'];
        $staff = self::resolveStaff($formData, $project);
        if (!$staff) return ['error' => '人员档案中未找到：' . $name];

        $payload = [
            'regular_date' => substr($regularDate, 0, 10),
            'status' => StaffStatus::derive($staff->resign_date, substr($regularDate, 0, 10), date('Y-m-d')),
            'updated_at' => now(),
        ];
        // 转正岗位：优先取「转正岗位」，未填则沿用现有岗位
        $newPos = trim((string) ($formData['new_position'] ?? ''));
        if ($newPos !== '') $payload['position'] = $newPos;
        elseif (!empty($formData['position'])) $payload['position'] = trim((string) $formData['position']);
        // 调薪判定：转正时填写的固定月薪/基本工资与档案现值不一致 → 记为「转正日生效」的薪资变更，
        // 写入 salary_history 供薪酬按转正日分段（转正前段沿用原工资且试用期不计绩效，转正后段按新工资）。
        $changedSalary = false;
        if (array_key_exists('fixed_monthly', $formData) && $formData['fixed_monthly'] !== '') {
            $newFixed = (float) $formData['fixed_monthly'];
            if (abs($newFixed - (float) $staff->fixed_monthly) > 0.001) $changedSalary = true;
            $payload['fixed_monthly'] = $newFixed;
        }
        if (array_key_exists('base_salary', $formData) && $formData['base_salary'] !== '') {
            $newBase = (float) $formData['base_salary'];
            if (abs($newBase - (float) $staff->base_salary) > 0.001) $changedSalary = true;
            $payload['base_salary'] = $newBase;
        }
        if ($changedSalary) {
            $data = json_decode((string) $staff->data, true) ?: [];
            // 追加转正生效段，并补全「入职定薪」基线，保证月中转正当月基本工资按段正确折算
            $data['salary_history'] = PayrollCalculator::appendSalarySegment(
                $data['salary_history'] ?? [],
                $staff->hire_date,
                (float) $staff->fixed_monthly, (float) $staff->base_salary,
                substr($regularDate, 0, 10),
                (float) ($payload['fixed_monthly'] ?? $staff->fixed_monthly),
                (float) ($payload['base_salary'] ?? $staff->base_salary),
                ['type' => '转正', 'note' => '转正调薪']
            );
            $payload['data'] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        // 转正时若调整了部门，同步更新组织关联（项目 + 部门名 + 岗位关键词解析到末级部门）
        $newDept = trim((string) ($formData['department'] ?? ''));
        if ($newDept !== '') {
            $orgSvc = new OrgService();
            $deptNode = $orgSvc->resolveDeptNode(
                $project,
                $newDept,
                (string) ($formData['new_position'] ?? $formData['position'] ?? '')
            );
            if ($deptNode) {
                $payload['org_id'] = $deptNode->id;
                $payload['dept_path'] = $orgSvc->path((int) $deptNode->id);
            }
        }
        DB::table('payroll_staff')->where('legacy_id', $staff->legacy_id)->update($payload);
        return ['regular' => true, 'staff_id' => $staff->legacy_id, 'regular_date' => substr($regularDate, 0, 10)];
    }

    /** 离职通过 → 档案离职 + 账号停用 */
    private static function applyResign(array $formData, string $project): array
    {
        $name = trim((string) ($formData['name'] ?? ''));
        $resignDate = trim((string) ($formData['resign_date'] ?? ''));
        if ($name === '' || $resignDate === '') return ['error' => '缺少姓名或离职日期'];
        $staff = self::resolveStaff($formData, $project);
        if (!$staff) return ['error' => '人员档案中未找到：' . $name];

        $payload = [
            'resign_date' => substr($resignDate, 0, 10),
            'status' => StaffStatus::derive(substr($resignDate, 0, 10), $staff->regular_date, date('Y-m-d')),
            'updated_at' => now(),
        ];
        DB::table('payroll_staff')->where('legacy_id', $staff->legacy_id)->update($payload);
        // 停用绑定账号
        DB::table('payroll_accounts')->where('staff_id', $staff->legacy_id)->update(['enabled' => false, 'updated_at' => now()]);
        return ['resign' => true, 'staff_id' => $staff->legacy_id, 'resign_date' => substr($resignDate, 0, 10)];
    }

    /** 入职清单完成 → 正式新增人员 + 可选开通账号 */
    public static function createStaff(array $formData, array $extra, string $project, bool $openAccount): array
    {
        $merged = array_merge($formData, $extra);
        $name = trim((string) ($merged['name'] ?? ''));
        if ($name === '' || $project === '') return ['error' => '缺少姓名或项目'];
        if (!DB::table('payroll_projects')->where('name', $project)->exists()) return ['error' => '项目不存在'];
        if (DB::table('payroll_staff')->where('name', $name)->where('project_name', $project)->where('deleted', false)->exists()) {
            return ['error' => '该项目下已存在同名人员'];
        }
        if (!empty($merged['id_card']) && !StaffProfile::validIdCard((string) $merged['id_card'])) return ['error' => '身份证号不合法'];
        foreach (['phone', 'emergency_phone'] as $pk) {
            if (!empty($merged[$pk]) && !StaffProfile::validPhone((string) $merged[$pk])) {
                return ['error' => ($pk === 'phone' ? '本人联系方式' : '紧急联系人电话') . '应为11位手机号'];
            }
        }

        return DB::transaction(function () use ($merged, $name, $project, $openAccount) {
            $data = $merged;
            unset($data['name'], $data['project'], $data['position'], $data['hire_date'], $data['fixed_monthly'], $data['base_salary'], $data['regular_date']);
            $regular = trim((string) ($merged['regular_date'] ?? ''));
            $regular = $regular === '' ? null : substr($regular, 0, 10);
            $hire = trim((string) ($merged['hire_date'] ?? ''));
            $hire = $hire === '' ? null : substr($hire, 0, 10);
            // 新员工默认试用；若表单已填转正日期则按日期派生
            $status = $regular ? StaffStatus::derive(null, $regular, date('Y-m-d')) : '试用';
            // 组织关联：按「项目 + 入职办理补录的部门(可空) + 岗位关键词」解析到末级部门，避免建档后组织/部门统计悬空
            $orgService = new OrgService();
            $deptNode = $orgService->resolveDeptNode(
                $project,
                (string) ($merged['department'] ?? ''),
                (string) ($merged['position'] ?? '')
            );
            $orgId = $deptNode?->id;
            $deptPath = $deptNode ? $orgService->path((int) $deptNode->id) : ($project . '/待分配');
            $id = (int) (DB::table('payroll_staff')->max('legacy_id') ?? 0) + 1;
            $payload = [
                'legacy_id' => $id, 'name' => $name, 'project_name' => $project,
                'position' => trim((string) ($merged['position'] ?? '')),
                'status' => $status,
                'fixed_monthly' => (float) ($merged['fixed_monthly'] ?? 0),
                'base_salary' => (float) ($merged['base_salary'] ?? 0),
                'hire_date' => $hire, 'regular_date' => $regular, 'resign_date' => null,
                'deleted' => false, 'org_id' => $orgId, 'leader_id' => null, 'dept_path' => $deptPath,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('payroll_staff')->insert($payload);

            $accountResult = null;
            if ($openAccount) {
                $accountResult = self::createAccount($name, $project, $id, $merged);
            }
            return ['staff_id' => $id, 'name' => $name, 'account' => $accountResult];
        });
    }

    /** 开通员工自助账号（role=staff，仅审批中心等自助能力） */
    private static function createAccount(string $name, string $project, int $staffId, array $merged): array
    {
        self::ensureStaffRole();
        $username = trim((string) ($merged['phone'] ?? ''));
        if ($username === '' || DB::table('payroll_accounts')->where('username', $username)->exists()) {
            $username = 'emp' . $staffId;
        }
        $newId = (int) (DB::table('payroll_accounts')->max('legacy_id') ?? 0) + 1;
        DB::table('payroll_accounts')->insert([
            'legacy_id' => $newId, 'username' => $username, 'name' => $name,
            'role' => 'staff', 'project_name' => $project,
            'password_hash' => hash('sha256', 'gwxy_123456'),
            'staff_id' => $staffId, 'enabled' => true, 'data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['username' => $username, 'initial_password' => '123456'];
    }

    private static function ensureStaffRole(): void
    {
        if (DB::table('payroll_roles')->where('role_key', 'staff')->exists()) return;
        DB::table('payroll_roles')->insert([
            'role_key' => 'staff', 'name' => '员工自助',
            'scope' => 'staff', 'permissions' => json_encode(['approval_center', 'perf'], JSON_UNESCAPED_UNICODE),
            'data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
