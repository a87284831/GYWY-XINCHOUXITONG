<?php

namespace App\Http\Controllers\Api;

use App\Finance\FinanceStop;
use App\Finance\Support;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 财务管理模块控制器
 * 入口 /api/finance*（走平台登录态），把请求 dispatch 到 app/Finance/handlers/* 的全局函数
 */
class FinanceController extends ApiController
{
    /** 需要财务权限的 handler 名单（role: admin/finance_admin/finance） */
    private const HANDLERS = [
        'meta'                      => 'handle_fin_meta',
        'ledger'                    => 'handle_ledger_matrix',
        'ledger/save'               => 'handle_ledger_save',
        'ledger/attachments'        => 'handle_ledger_attachments',
        'ledger/attachment_upload'  => 'handle_ledger_attachment_upload',
        'ledger/attachment_delete'  => 'handle_ledger_attachment_delete',
        'fill'                      => 'handle_fill_page',
        'payments'                  => 'handle_payments_matrix',
        'payments/save'             => 'handle_payments_save',
        'payments/attachments'      => 'handle_payments_attachments',
        'payments/attachment_upload'=> 'handle_payments_attachment_upload',
        'payments/attachment_delete'=> 'handle_payments_attachment_delete',
        'summary/annual'            => 'handle_summary_annual',
        'summary/projects'          => 'handle_summary_projects',
        'summary/discount'          => 'handle_summary_discount',
        'summary/payments'          => 'handle_summary_payments',
        'chart/dashboard'           => 'handle_chart_dashboard',
        'accounts/list'             => 'handle_fin_accounts_list',
        'accounts/save'             => 'handle_fin_accounts_save',
        'accounts/status'           => 'handle_fin_accounts_status',
        'accounts/delete'           => 'handle_fin_accounts_delete',
        'import/parse'              => 'handle_fin_import_parse',
        'import/run'                => 'handle_fin_import_run',
        'export_summary'            => 'handle_export_summary',
        'export_ledger'             => 'handle_export_ledger',
        'export_payments'           => 'handle_export_payments',
    ];

    /** 写操作清单（仅完全授权角色/权限点可执行；finance_view 只读角色拒绝） */
    private const WRITE_ACTIONS = [
        'ledger/save', 'ledger/attachment_upload', 'ledger/attachment_delete',
        'payments/save', 'payments/attachment_upload', 'payments/attachment_delete',
        'import/parse', 'import/run',
        'accounts/save', 'accounts/status', 'accounts/delete',
    ];

    public function handle(Request $request, string $action = '')
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $role = (string) ($account->role ?? '');
        $fullRoles = ['admin', 'finance_admin', 'finance'];
        if (!in_array($role, $fullRoles, true)) {
            // 兼容系统设置→权限管理里按权限点授权（finance_view 只读 / finance_admin 完全）
            $rp = DB::table('payroll_roles')->where('role_key', $role)->value('permissions');
            $perms = is_string($rp) && $rp !== '' ? (json_decode($rp, true) ?: []) : [];
            $permOk = false;
            foreach ($perms as $p) {
                if ($p === '*' || $p === 'finance_admin' || $p === 'finance_view') { $permOk = true; break; }
            }
            if (!$permOk) {
                return response()->json(['ok' => false, 'error' => '无财务管理权限'], 403);
            }
            $isReadonly = !in_array('*', $perms, true) && !in_array('finance_admin', $perms, true);
            if ($isReadonly && in_array($action, self::WRITE_ACTIONS, true)) {
                return response()->json(['ok' => false, 'error' => '只读权限，不能执行此操作'], 403);
            }
        }

        $handler = self::HANDLERS[$action] ?? null;
        if ($handler === null) {
            return response()->json(['ok' => false, 'error' => '未知操作: ' . $action], 404);
        }
        if (!function_exists($handler)) {
            $prefix = explode('/', $action)[0];
            if (strpos($prefix, 'export_') === 0) $prefix = 'export';
            $file = app_path('Finance/handlers/' . $prefix . '.php');
            if (is_file($file)) {
                require_once app_path('Finance/Support.php');
                require_once $file;
            }
        }
        if (!function_exists($handler)) {
            return response()->json(['ok' => false, 'error' => '处理函数缺失: ' . $handler], 500);
        }

        Support::setContext([
            'id' => (int) $account->id,
            'username' => (string) $account->username,
            'name' => (string) ($account->name ?? $account->username),
            'role' => $role,
            'project_name' => (string) ($account->project_name ?? ''),
            'project_id' => $this->financeProjectId($account),
        ]);

        try {
            $result = call_user_func($handler);
            $this->finLog($account, $action, '成功');
            if (is_array($result) && ($result['__binary__'] ?? false) === true) {
                $fname = (string) ($result['filename'] ?? 'export.xlsx');
                $content = (string) ($result['content'] ?? '');
                return response($content, 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname),
                    'Content-Length' => (string) strlen($content),
                    'Cache-Control' => 'no-store',
                ]);
            }
            return response()->json($result);
        } catch (FinanceStop $e) {
            $this->finLog($account, $action, '失败: ' . (is_array($e->payload) ? ($e->payload['msg'] ?? '') : ''));
            return response()->json($e->payload ?? ['ok' => false, 'msg' => '操作失败'], $e->status);
        } catch (\Throwable $e) {
            $this->finLog($account, $action, '异常: ' . mb_substr($e->getMessage(), 0, 200));
            return response()->json(['ok' => false, 'msg' => '服务异常: ' . $e->getMessage()], 500);
        }
    }

    /** 项目财务的绑定项目ID（按采购库项目名解析） */
    private function financeProjectId(object $account): ?int
    {
        $projectName = trim((string) ($account->project_name ?? ''));
        if ($projectName === '') return null;
        try {
            if (!class_exists(Support::class)) return null;
            foreach (fin_projects() as $p) {
                if ($p['name'] === $projectName) return $p['id'];
            }
        } catch (\Throwable $e) {
            // 忽略
        }
        return null;
    }

    /** 财务操作日志（写入 gy_finance.fin_logs，失败不影响主流程） */
    private function finLog(object $account, string $action, string $result): void
    {
        try {
            if (!function_exists('fdb')) {
                require_once app_path('Finance/Support.php');
            }
            $st = fdb()->prepare("INSERT INTO fin_logs (user_id, username, role, action, result, created_at) VALUES (?,?,?,?,?, NOW())");
            $st->execute([(int) $account->id, (string) $account->username, (string) ($account->role ?? ''), $action, mb_substr($result, 0, 300)]);
        } catch (\Throwable $e) {
            // 忽略：日志表不存在或写入失败不影响主流程
        }
    }
}
