<?php

namespace App\Http\Controllers\Api;

use App\Purchase\PurchaseStop;
use App\Purchase\Support;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 月度采购模块（整合自独立采购系统）：
 * 复用平台登录鉴权，handler 逻辑原样保留，输出原语统一走 App\Purchase\Support。
 * 路由：/api/purchase/{path}
 */
class PurchaseController extends ApiController
{
    private bool $loaded = false;

    public function index(Request $request): JsonResponse|Response
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        // 确保 Support 文件已加载（其内定义的全局函数 db() 等为 handler 与控制器共用）
        class_exists(\App\Purchase\Support::class);

        // 平台用户 → 采购上下文（角色映射 + 项目归属映射）
        // 项目 ID 统一采用平台 payroll.payroll_projects.id（采购库 projects 表仅作扩展资料/旧兼容）
        $projectId = null;
        if (!empty($account->project_name)) {
            try {
                $st = db()->prepare('SELECT id FROM payroll.payroll_projects WHERE name = ? LIMIT 1');
                $st->execute([$account->project_name]);
                $row = $st->fetch();
                $projectId = $row ? (int) $row['id'] : null;
            } catch (\Throwable $e) {
                $projectId = null; // 采购库暂不可用不阻断其余鉴权流程
            }
        }
        Support::setContext([
            'id' => (int) $account->id,
            'username' => (string) $account->username,
            'name' => (string) ($account->name ?: $account->username),
            'role' => $account->role === 'admin' ? 'admin' : 'staff',
            'project_id' => $projectId,
            'status' => 1,
        ]);

        // 清空早期输出缓冲（防止入口/require 阶段混入的 BOM 等意外字节污染后续二进制导出）
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        try {
            // 路由：剥离 /api/purchase 前缀
            $path = trim($request->path(), '/'); // api/purchase/xxx
            $uri = preg_replace('#^api/purchase#', '', $path);
            $uri = trim((string) $uri, '/');
            $method = $request->method();

            $this->loadHandlers();
            $this->syncProjectsThrottled();

            $result = $this->dispatch($uri, $method);
            $out = ob_get_clean();
            if ($result !== null) {
                return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
            }
            return $this->binaryResponse($out, 200);
        } catch (PurchaseStop $e) {
            $out = ob_get_clean();
            if ($e->payload !== null) {
                return response()->json($e->payload, $e->status, [], JSON_UNESCAPED_UNICODE);
            }
            return $this->binaryResponse($out, $e->status);
        } catch (\Throwable $e) {
            ob_get_clean();
            return response()->json(['ok' => false, 'msg' => '服务器错误: ' . $e->getMessage()], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    private function binaryResponse(string $body, int $status): Response
    {
        $resp = response($body, $status);
        $headers = Support::takeHeaders();
        foreach ($headers as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $resp->header(trim($parts[0]), trim($parts[1]));
            }
        }
        return $resp;
    }

    private function loadHandlers(): void
    {
        if ($this->loaded) {
            return;
        }
        foreach (['log', 'products', 'fill', 'admin', 'export_import', 'dashboard', 'system', 'budget', 'summary'] as $h) {
            require_once app_path("Purchase/handlers/{$h}.php");
        }
        $this->loaded = true;
    }

    /**
     * 项目同步：以平台人力组织架构（启用项目节点）为唯一权威源，完全同步。
     * 匹配策略：按名称匹配已有项目（避免同名重复）；组织架构中已不存在的旧项目一律删除（防止旧数据残留/复活）。
     * 业务项目 id 仍为平台 payroll_projects.id（由 handle_projects_list 映射），本表仅作兼容/展示层。
     */
    /**
     * 同步节流：gy_procurement.projects 仅为兼容/展示层，允许最多 5 分钟滞后。
     * 用原子 Cache::add 保证全局每 5 分钟最多执行一次完整同步，
     * 避免每个采购 API 请求都跑几十条 prepared statement（模块响应慢的主因之一）。
     */
    private function syncProjectsThrottled(): void
    {
        if (!Cache::add('purchase_sync_projects_tick', 1, 300)) {
            return;
        }
        $this->syncProjects();
    }

    private function syncProjects(): void
    {
        try {
            $orgs = DB::table('org_nodes')->where('type', 'project')->where('enabled', true)
                ->orderBy('sort_order')->get(['id', 'name', 'code', 'contact', 'phone', 'sort_order']);
        } catch (\Throwable $e) {
            return; // 平台库异常不阻断采购功能
        }
        $keepNames = [];
        $selByName = db()->prepare('SELECT id FROM projects WHERE name = ? LIMIT 1');
        $ins = db()->prepare('INSERT INTO projects (code, name, manager, phone, sort_no, status) VALUES (?, ?, ?, ?, ?, 1)');
        $upd = db()->prepare('UPDATE projects SET code = ?, manager = ?, phone = ?, sort_no = ?, status = 1 WHERE id = ?');
        foreach ($orgs as $o) {
            $name = (string) $o->name;
            if ($name === '') continue;
            $keepNames[] = $name;
            $code = (string) ($o->code ?: ('P' . (int) $o->id));
            $manager = (string) ($o->contact ?: '');
            $phone = (string) ($o->phone ?: '');
            $sortNo = (int) $o->sort_order;
            $selByName->execute([$name]);
            $id = $selByName->fetchColumn();
            if ($id) {
                $upd->execute([$code, $manager, $phone, $sortNo, (int) $id]);
            } else {
                $ins->execute([$code, $name, $manager, $phone, $sortNo]);
            }
        }
        // 删除组织架构中已不存在的旧项目
        if ($keepNames) {
            $ph = implode(',', array_fill(0, count($keepNames), '?'));
            $del = db()->prepare("DELETE FROM projects WHERE name NOT IN ({$ph})");
            $del->execute($keepNames);
        } else {
            db()->exec('DELETE FROM projects');
        }
    }

    private function dispatch(string $uri, string $method): mixed
    {
        if ($uri === '') {
            return ['ok' => true, 'msg' => '广盈物业采购管理系统 API 运行中', 'time' => date('Y-m-d H:i:s')];
        }

        // 商品库
        if ($uri === 'products/lines' && $method === 'GET') return handle_products_lines();
        if ($uri === 'products/categories' && $method === 'GET') return handle_products_categories();
        if ($uri === 'products/by-name' && $method === 'GET') return handle_products_by_name();
        if ($uri === 'products/search' && $method === 'GET') return handle_products_search();
        if ($uri === 'products/template' && $method === 'GET') return handle_products_import_template();
        if ($uri === 'products/import' && $method === 'POST') return handle_products_import();
        if ($uri === 'products/unbound' && $method === 'GET') return handle_products_unbound();
        if ($uri === 'products' && $method === 'GET') return handle_products_list();
        if ($uri === 'products' && $method === 'POST') return handle_products_create();
        if (preg_match('#^products/(\d+)$#', $uri, $m) && $method === 'PUT') return handle_products_update((int) $m[1]);
        if (preg_match('#^products/(\d+)$#', $uri, $m) && $method === 'DELETE') return handle_products_delete((int) $m[1]);
        if (preg_match('#^products/(\d+)/synonyms$#', $uri, $m) && $method === 'GET') return handle_synonyms_list((int) $m[1]);
        if ($uri === 'synonyms' && $method === 'POST') return handle_synonyms_create();
        if (preg_match('#^synonyms/(\d+)$#', $uri, $m) && $method === 'DELETE') return handle_synonyms_delete((int) $m[1]);

        // 填报
        if ($uri === 'fill/window' && $method === 'GET') return handle_fill_window();
        if ($uri === 'fill/items' && $method === 'GET') return handle_fill_items();
        if ($uri === 'fill/items' && $method === 'POST') return handle_fill_create();
        if (preg_match('#^fill/items/(\d+)$#', $uri, $m) && $method === 'PUT') return handle_fill_update((int) $m[1]);
        if (preg_match('#^fill/items/(\d+)$#', $uri, $m) && $method === 'DELETE') return handle_fill_delete((int) $m[1]);
        if (preg_match('#^fill/items/(\d+)/submit$#', $uri, $m) && $method === 'POST') return handle_fill_submit((int) $m[1]);
        if ($uri === 'fill/frequency' && $method === 'GET') return handle_fill_frequency();
        if ($uri === 'my/months' && $method === 'GET') return handle_my_months();
        if ($uri === 'my/month-items' && $method === 'GET') return handle_my_month_items();

        // 招采
        if ($uri === 'admin/overview' && $method === 'GET') return handle_admin_overview();
        if ($uri === 'admin/archive' && $method === 'POST') return handle_admin_archive();
        if ($uri === 'admin/unarchive' && $method === 'POST') return handle_admin_unarchive();
        if (preg_match('#^admin/items/(\d+)/confirm$#', $uri, $m) && $method === 'POST') return handle_admin_confirm_item((int) $m[1]);
        if (preg_match('#^admin/items/(\d+)/unconfirm$#', $uri, $m) && $method === 'POST') return handle_admin_unconfirm_item((int) $m[1]);
        if (preg_match('#^admin/items/(\d+)/return$#', $uri, $m) && $method === 'POST') return handle_admin_return_item((int) $m[1]);
        if ($uri === 'admin/return-project' && $method === 'POST') return handle_admin_return_project();
        if ($uri === 'admin/confirm-batch' && $method === 'POST') return handle_admin_confirm_batch();
        if ($uri === 'admin/customs' && $method === 'GET') return handle_admin_customs();
        if ($uri === 'admin/customs/map' && $method === 'POST') return handle_admin_map_custom();
        if ($uri === 'admin/customs/check-duplicate' && $method === 'POST') return handle_admin_customs_check_duplicate();
        if ($uri === 'admin/customs/save' && $method === 'POST') return handle_admin_customs_save();

        // 预算
        if ($uri === 'budgets' && $method === 'GET') return handle_budgets_list();
        if ($uri === 'budgets' && $method === 'POST') return handle_budgets_save();
        if ($uri === 'budgets/compare' && $method === 'GET') return handle_budget_compare();
        if ($uri === 'budgets/months' && $method === 'GET') return handle_budgets_months();
        if ($uri === 'budgets/template' && $method === 'GET') return handle_budgets_template();
        if ($uri === 'budgets/import' && $method === 'POST') return handle_budgets_import();

        // 操作日志
        if ($uri === 'oplogs' && $method === 'GET') return handle_oplogs();

        // 站内通知
        if ($uri === 'notifications' && $method === 'GET') return handle_notifications_list();
        if ($uri === 'notifications/unread-count' && $method === 'GET') return handle_notifications_unread_count();
        if ($uri === 'notifications/read-all' && $method === 'POST') return handle_notifications_read_all();
        if (preg_match('#^notifications/(\d+)/read$#', $uri, $m) && $method === 'POST') return handle_notifications_read_one((int) $m[1]);

        // 系统设置
        if ($uri === 'settings' && $method === 'GET') return handle_settings_get();
        if ($uri === 'settings' && $method === 'PUT') return handle_settings_put();

        // 导出导入
        if ($uri === 'export' && $method === 'GET') return handle_export_excel();
        if ($uri === 'export/year' && $method === 'GET') return handle_export_year();
        if ($uri === 'export/my-month' && $method === 'GET') return handle_export_my_month();
        if ($uri === 'import' && $method === 'POST') return handle_import_archive();
        if ($uri === 'import/batch' && $method === 'POST') return handle_import_batch();

        // 采购汇总查看
        if ($uri === 'summary/months' && $method === 'GET') return handle_summary_months();
        if ($uri === 'summary/search' && $method === 'GET') return handle_summary_search();
        if ($uri === 'summary/month' && $method === 'GET') return handle_summary_month();

        // 驾驶舱
        if ($uri === 'dashboard/all' && $method === 'GET') return handle_dashboard_all();
        if ($uri === 'dashboard/monthly' && $method === 'GET') return handle_dashboard_monthly();
        if ($uri === 'dashboard/compare' && $method === 'GET') return handle_dashboard_compare();
        if ($uri === 'dashboard/annual' && $method === 'GET') return handle_dashboard_annual();
        if ($uri === 'dashboard/annual-lines' && $method === 'GET') return handle_dashboard_annual_lines();
        if ($uri === 'dashboard/annual-projects' && $method === 'GET') return handle_dashboard_annual_projects();
        if ($uri === 'dashboard/top' && $method === 'GET') return handle_dashboard_top();
        if ($uri === 'dashboard/price-trend' && $method === 'GET') return handle_dashboard_price_trend();
        if ($uri === 'dashboard/price-anomalies' && $method === 'GET') return handle_dashboard_price_anomalies();
        if ($uri === 'dashboard/ytd' && $method === 'GET') return handle_dashboard_ytd();
        if ($uri === 'dashboard/yoy' && $method === 'GET') return handle_dashboard_yoy();
        if ($uri === 'dashboard/budget-exec' && $method === 'GET') return handle_dashboard_budget_exec();
        if ($uri === 'dashboard/fill-progress' && $method === 'GET') return handle_dashboard_fill_progress();
        if ($uri === 'dashboard/custom-ratio' && $method === 'GET') return handle_dashboard_custom_ratio();

        // 系统管理（用户账号开设不再提供——账号跟随平台）
        if ($uri === 'projects/options' && $method === 'GET') return handle_projects_options();
        if ($uri === 'projects' && $method === 'GET') return handle_projects_list();
        if ($uri === 'projects' && $method === 'POST') return handle_projects_create();
        if (preg_match('#^projects/(\d+)$#', $uri, $m) && $method === 'PUT') return handle_projects_update((int) $m[1]);
        if (preg_match('#^projects/(\d+)$#', $uri, $m) && $method === 'DELETE') return handle_projects_delete((int) $m[1]);
        if ($uri === 'windows' && $method === 'GET') return handle_windows_list();
        if ($uri === 'windows' && $method === 'POST') return handle_windows_upsert();
        if (preg_match('#^windows/(\d+)$#', $uri, $m) && $method === 'DELETE') return handle_windows_delete((int) $m[1]);
        if ($uri === 'windows/gen' && $method === 'POST') return handle_admin_gen_windows();

        return ['ok' => false, 'msg' => '接口不存在'];
    }
}
