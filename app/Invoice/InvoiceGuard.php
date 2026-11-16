<?php

namespace App\Invoice;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 开票模块统一权限守卫：权限点 + 账套范围 + 项目范围。
 * 任何后台开票接口都必须先过这里，前端隐藏不作为安全边界。
 */
class InvoiceGuard
{
    public const PERMS = [
        'invoice_view',
        'invoice_review',
        'invoice_issue',
        'invoice_stats',
        'invoice_config',
    ];

    public function role(object $account): ?object
    {
        return DB::table('payroll_roles')->where('role_key', $account->role)->first();
    }

    public function permissions(object $account): array
    {
        if ($this->isAdmin($account)) {
            return ['*'];
        }
        $role = $this->role($account);
        if (!$role) {
            return [];
        }
        $decoded = json_decode((string) $role->permissions, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isAdmin(object $account): bool
    {
        if (($account->role ?? '') === 'admin') {
            return true;
        }
        $role = $this->role($account);
        if (!$role) {
            return false;
        }
        $decoded = json_decode((string) $role->permissions, true);
        return is_array($decoded) && in_array('*', $decoded, true);
    }

    public function scope(object $account): string
    {
        if ($this->isAdmin($account)) {
            return 'all';
        }
        $role = $this->role($account);
        $scope = (string) ($role->scope ?? 'project');
        return in_array($scope, ['all', 'account_set', 'project'], true) ? $scope : 'project';
    }

    public function has(object $account, string $perm): bool
    {
        if ($this->isAdmin($account)) {
            return true;
        }
        $perms = $this->permissions($account);
        return in_array('*', $perms, true) || in_array($perm, $perms, true);
    }

    /**
     * 可访问账套 ID 集合。scope=all 含停用账套（只读），其余仅启用账套。
     *
     * @return int[]
     */
    public function accessibleAccountSets(object $account): array
    {
        $scope = $this->scope($account);
        $query = DB::table('invoice_account_sets')->select('id');
        if ($scope !== 'all') {
            $query->where('status', 'enabled');
        }
        if ($scope === 'account_set') {
            $query->whereIn('id', function ($sub) use ($account) {
                $sub->select('account_set_id')
                    ->from('invoice_account_set_reviewers')
                    ->where('account_id', $account->id)
                    ->where('enabled', 1);
            });
        } elseif ($scope === 'project') {
            $projectName = (string) ($account->project_name ?? '');
            $query->whereIn('id', function ($sub) use ($projectName) {
                $sub->select('account_set_id')
                    ->from('invoice_project_bindings')
                    ->where('project_name', $projectName)
                    ->where('enabled', 1);
            });
        }
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function canAccessAccountSet(object $account, int $accountSetId): bool
    {
        return in_array($accountSetId, $this->accessibleAccountSets($account), true);
    }

    /**
     * 权限校验；返回 null 表示通过，否则返回 403 响应。
     */
    public function authorize(object $account, string $perm, ?int $accountSetId = null, ?Request $request = null): ?JsonResponse
    {
        if (!$this->has($account, $perm)) {
            $this->logDenied($accountSetId, 'permission_denied', ['perm' => $perm], $request, $account);
            return response()->json(['ok' => false, 'error' => '无此操作权限', 'code' => 'PERMISSION_DENIED'], 403);
        }
        if ($accountSetId !== null && !$this->canAccessAccountSet($account, $accountSetId)) {
            $this->logDenied($accountSetId, 'cross_account_set_denied', [
                'perm' => $perm,
                'path' => $request?->path(),
            ], $request, $account);
            return response()->json(['ok' => false, 'error' => '无权访问该账套', 'code' => 'ACCOUNT_SET_FORBIDDEN'], 403);
        }
        return null;
    }

    public function logDenied(?int $accountSetId, string $action, array $payload, ?Request $request, ?object $account): void
    {
        DB::table('invoice_request_logs')->insert([
            'request_id' => $payload['request_id'] ?? null,
            'account_set_id' => $accountSetId,
            'action' => $action,
            'from_status' => null,
            'to_status' => null,
            'operator_type' => $account ? 'staff' : 'anonymous',
            'operator_id' => $account->id ?? null,
            'operator_name' => $account->name ?? ($account->username ?? null),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'ua' => substr((string) $request?->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    public function audit(?int $requestId, ?int $accountSetId, string $action, array $payload, string $operatorType, ?object $account, ?string $from = null, ?string $to = null, ?Request $request = null): void
    {
        DB::table('invoice_request_logs')->insert([
            'request_id' => $requestId,
            'account_set_id' => $accountSetId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'operator_type' => $operatorType,
            'operator_id' => $account->id ?? null,
            'operator_name' => $account->name ?? ($account->username ?? 'system'),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'ua' => substr((string) $request?->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
