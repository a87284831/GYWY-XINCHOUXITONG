<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 绩效考核测试基建：造人员/账号（X-Token 鉴权）/考核单 JSON。
 */
trait SeedsPerformancePlan
{
    private function seedStaff(int $legacyId, string $name = '测试员工', array $extra = []): void
    {
        DB::table('payroll_staff')->insert(array_merge([
            'legacy_id' => $legacyId, 'name' => $name, 'project_name' => '物业公司总部',
            'position' => '主管', 'status' => '正式',
            'fixed_monthly' => 8000, 'base_salary' => 6000,
            'hire_date' => '2025-01-01', 'regular_date' => '2025-02-01',
            'resign_date' => null, 'deleted' => false,
            'data' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** 造账号（含 staff_id/enabled 列），返回登录 token */
    private function seedAccount(int $legacyId, ?int $staffId, string $role = 'staff', bool $enabled = true, string $name = null): string
    {
        $id = DB::table('payroll_accounts')->insertGetId([
            'legacy_id' => $legacyId,
            'username' => 'user_' . $legacyId,
            'name' => $name ?: ('账号' . $legacyId),
            'role' => $role,
            'project_name' => null,
            'password_hash' => 'x',
            'staff_id' => $staffId,
            'enabled' => $enabled,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 确定性且全局唯一的 token（不可用 legacy_id 简单取模，会跨账号碰撞导致串号）
        $token = substr(hash('sha256', 'seed-token:' . $legacyId), 0, 64);
        Cache::put('payroll_api_token:' . $token, $id, now()->addHours(8));
        return $token;
    }

    /**
     * 插入一张考核单。$overrides 深度覆盖默认 plan（categories 等整体替换）。
     * 返回 legacy_id。
     */
    private function putPlan(array $overrides = []): int
    {
        $id = (int) (DB::table('performance_plans')->max('legacy_id') ?? 0) + 1;
        $plan = array_merge([
            'id' => $id,
            'employeeId' => 101,
            'employeeName' => '张三',
            'project' => '物业公司总部',
            'founderId' => 1001,
            'founderName' => '账号1001',
            'periodStart' => '2026-07-01',
            'periodEnd' => '2026-09-30',
            'year' => 2026,
            'status' => 'draft',
            'createdAt' => now()->toDateTimeString(),
            'approvers' => [['staffId' => 201, 'userId' => 2001, 'name' => '李经理']],
            'categories' => [],
            'logs' => [],
        ], $overrides);
        $plan['id'] = $id;
        DB::table('performance_plans')->insert([
            'legacy_id' => $id,
            'employee_id' => $plan['employeeId'],
            'employee_name' => $plan['employeeName'],
            'project_name' => $plan['project'],
            'year' => $plan['year'] ?? null,
            'quarter' => null,
            'status' => $plan['status'],
            'data' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    /** 构造一个指标项 */
    private function item(array $overrides = []): array
    {
        static $seq = 0;
        return array_merge([
            'id' => 'it' . (++$seq),
            'content' => '指标' . $seq,
            'definition' => '',
            'weight' => 100,
            'calcType' => 'manual',
            'calcParams' => [],
            'reporterId' => null,
            'reporterName' => '',
        ], $overrides);
    }

    private function category(string $name, array $items): array
    {
        return ['name' => $name, 'items' => $items];
    }

    private function planData(int $id): array
    {
        $row = DB::table('performance_plans')->where('legacy_id', $id)->first();
        return json_decode($row->data, true);
    }

    /** 在指标树中按 id 找项 */
    private function findItem(array $plan, string $itemId): ?array
    {
        foreach (($plan['categories'] ?? []) as $cat) {
            foreach (($cat['items'] ?? []) as $it) {
                if (($it['id'] ?? '') === $itemId) return $it;
            }
        }
        return null;
    }
}
