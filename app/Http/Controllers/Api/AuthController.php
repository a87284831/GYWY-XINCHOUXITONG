<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $account = DB::table('payroll_accounts')->where('username', $credentials['username'])->first();
        
        if (!$account) {
            $this->purchaseLog(0, $credentials['username'], '-', '登录失败', '账号或密码错误');
            return response()->json(['ok' => false, 'error' => '用户名或密码错误'], 400);
        }

        // 密码验证：支持旧版 SHA-256 和新版 bcrypt 两种格式
        $passwordValid = false;
        $needsRehash = false;
        $storedHash = (string) $account->password_hash;

        if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2b$')) {
            // bcrypt 格式：使用 password_verify
            $passwordValid = Hash::check($credentials['password'], $storedHash);
        } else {
            // 旧版 SHA-256 格式（64位十六进制）
            $expected = hash('sha256', 'gwxy_' . $credentials['password']);
            $passwordValid = hash_equals($storedHash, $expected);
            if ($passwordValid) {
                $needsRehash = true; // 登录成功后需要迁移到 bcrypt
            }
        }

        if (!$passwordValid) {
            $this->purchaseLog(0, $credentials['username'], '-', '登录失败', '账号或密码错误');
            return response()->json(['ok' => false, 'error' => '用户名或密码错误'], 400);
        }

        // 账号启停 + 绑定人员状态联动：停用/离职/黑名单禁止登录
        if (array_key_exists('enabled', (array) $account) && !(bool) $account->enabled) {
            $this->purchaseLog((int) $account->id, $account->username, (string) $account->role, '登录失败', '账号已停用');
            return response()->json(['ok' => false, 'error' => '账号已停用，请联系管理员'], 403);
        }
        if (!empty($account->staff_id)) {
            $bound = DB::table('payroll_staff')->where('legacy_id', $account->staff_id)->first();
            if ($bound) {
                $cat = \App\Services\StaffCategory::derive((array) $bound, date('Y-m-d'));
                if ($cat !== '在职') {
                    $this->purchaseLog((int) $account->id, $account->username, (string) $account->role, '登录失败', '账号对应人员已' . $cat);
                    return response()->json(['ok' => false, 'error' => '账号对应人员已' . $cat . '，登录已禁用'], 403);
                }
            }
        }

        // 如果使用的是旧版哈希，登录成功后迁移到 bcrypt
        if ($needsRehash) {
            $newHash = Hash::make($credentials['password']);
            DB::table('payroll_accounts')
                ->where('id', $account->id)
                ->update(['password_hash' => $newHash, 'updated_at' => now()]);
        }

        $role = DB::table('payroll_roles')->where('role_key', $account->role)->first();
        $projects = DB::table('payroll_projects')->where('status', '启用')->pluck('name')->values()->all();
        $token = $this->tokenFor($account);
        $this->purchaseLog((int) $account->id, $account->username, (string) $account->role, '登录', '登录成功（' . ($account->name ?: $account->username) . '）');
        return response()->json([
            'ok' => true,
            'token' => $token,
            'role' => $account->role,
            'username' => $account->username,
            'name' => $account->name ?: $account->username,
            'project' => $account->project_name ?: '',
            'roles' => $role ? [$role] : [],
            'projects' => $projects,
            'app_modules' => [],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = trim((string) $request->header('X-Token'));
        if ($token !== '') {
            $cacheKey = 'payroll_api_token:' . $token;
            $accountId = \Illuminate\Support\Facades\Cache::get($cacheKey);
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            if ($accountId) {
                $account = DB::table('payroll_accounts')->where('id', $accountId)->first();
                if ($account) {
                    $this->purchaseLog((int) $account->id, $account->username, (string) $account->role, '退出登录', '退出登录（' . ($account->name ?: $account->username) . '）');
                }
            }
        }
        return response()->json(['ok' => true]);
    }

    /**
     * 修改密码（使用 bcrypt 哈希）
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'max:200'],
        ]);

        $token = trim((string) $request->header('X-Token'));
        $accountId = \Illuminate\Support\Facades\Cache::get('payroll_api_token:' . $token);
        if (!$accountId) {
            return response()->json(['ok' => false, 'error' => '未登录'], 401);
        }

        $account = DB::table('payroll_accounts')->where('id', $accountId)->first();
        if (!$account) {
            return response()->json(['ok' => false, 'error' => '账号不存在'], 404);
        }

        // 验证旧密码（支持旧版和新版格式）
        $storedHash = (string) $account->password_hash;
        $oldPasswordValid = false;

        if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2b$')) {
            $oldPasswordValid = Hash::check($request->input('old_password'), $storedHash);
        } else {
            $expected = hash('sha256', 'gwxy_' . $request->input('old_password'));
            $oldPasswordValid = hash_equals($storedHash, $expected);
        }

        if (!$oldPasswordValid) {
            return response()->json(['ok' => false, 'error' => '原密码错误'], 400);
        }

        // 使用 bcrypt 保存新密码
        $newHash = Hash::make($request->input('new_password'));
        DB::table('payroll_accounts')
            ->where('id', $accountId)
            ->update(['password_hash' => $newHash, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'message' => '密码已修改']);
    }

    /**
     * 采购操作日志（op_logs 位于 gy_procurement 库，供采购模块日志页审计）
     */
    private function purchaseLog(int $uid, string $username, string $role, string $action, string $detail = ''): void
    {
        try {
            if (!function_exists('db')) {
                require_once app_path('Purchase/Support.php'); // 定义全局 db() 等
            }
            db()->prepare("INSERT INTO op_logs (user_id, username, role, action, detail) VALUES (?,?,?,?,?)")
                ->execute([$uid, $username, $role, $action, mb_substr($detail, 0, 500)]);
        } catch (\Throwable $e) {
            // 日志失败不影响登录主流程
        }
    }
}
