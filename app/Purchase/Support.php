<?php
/** 采购模块运行支持：上下文注入、响应头采集、终止异常、PDO 连接、鉴权辅助。
 *  全局函数定义在尾部全局命名空间块，供无命名空间的 handler 文件直接调用。 */

namespace App\Purchase {

    /** 终止异常：携带响应数据（JSON payload 或二进制输出 + 响应头） */
    class PurchaseStop extends \Exception
    {
        public mixed $payload;
        public int $status;
        public array $headers;

        public function __construct(mixed $payload = null, int $status = 200, array $headers = [])
        {
            parent::__construct('purchase-stop', 0, null);
            $this->payload = $payload;
            $this->status = $status;
            $this->headers = $headers;
        }
    }

    class Support
    {
        private static array $ctx = [];
        private static array $headers = [];

        /** 注入当前平台用户映射后的采购上下文 */
        public static function setContext(array $ctx): void
        {
            self::$ctx = $ctx;
        }

        public static function context(): array
        {
            return self::$ctx;
        }

        /** 采集响应头（导出 Excel 用），由控制器取走 */
        public static function header(string $line): void
        {
            self::$headers[] = $line;
        }

        public static function takeHeaders(): array
        {
            $h = self::$headers;
            self::$headers = [];
            return $h;
        }
    }
}

namespace {

    use App\Purchase\PurchaseStop;
    use App\Purchase\Support;

    /** 数据库连接（gy_procurement 独立库，PDO 单例） */
    function db(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $d = [
                'host'    => config('procurement.host', '127.0.0.1'),
                'port'    => (int) config('procurement.port', 3306),
                'dbname'  => config('procurement.dbname', 'gy_procurement'),
                'user'    => config('procurement.user', 'payroll'),
                'pass'    => (string) config('procurement.pass', ''),
                'charset' => 'utf8mb4',
            ];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['dbname'], $d['charset']);
            try {
                $pdo = new \PDO($dsn, $d['user'], $d['pass'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (\PDOException $e) {
                throw new PurchaseStop(['ok' => false, 'msg' => '数据库连接失败: ' . $e->getMessage()], 500);
            }
        }
        return $pdo;
    }

    /** 当前采购用户（已由控制器注入平台映射上下文） */
    function auth_user(): ?array
    {
        $ctx = Support::context();
        return $ctx ?: null;
    }

    function require_auth(): array
    {
        $user = auth_user();
        if (!$user) {
            throw new PurchaseStop(['ok' => false, 'msg' => '未登录或登录已过期'], 401);
        }
        return $user;
    }

    function require_admin(): array
    {
        $user = require_auth();
        if ($user['role'] !== 'admin') {
            throw new PurchaseStop(['ok' => false, 'msg' => '无权限'], 403);
        }
        return $user;
    }

    /** 该月是否已归档锁定（最终版） */
    function month_archived(string $month): bool
    {
        static $cache = [];
        if (array_key_exists($month, $cache)) return $cache[$month];
        $st = db()->prepare("SELECT COUNT(*) FROM monthly_archive WHERE month=?");
        $st->execute([$month]);
        return $cache[$month] = (int)$st->fetchColumn() > 0;
    }

    /** 项目列表（统一读主系统 payroll.payroll_projects 启用项目，与平台共用一套项目） */
    function projects_all(): array
    {
        $st = db()->query("SELECT id, name FROM payroll.payroll_projects WHERE status='启用' ORDER BY id");
        return $st->fetchAll();
    }

    /** 规格归一化：全角转半角 + 折叠多余空白 + 首尾去空格 */
    function norm_spec(?string $s): string
    {
        if ($s === null) return '';
        $s = mb_convert_kana((string)$s, 'as', 'UTF-8');
        $s = preg_replace('/[ \t　]+/u', ' ', trim($s));
        return $s === null ? '' : $s;
    }

    /**
     * 清单外「归入标准商品」前置校验（纯函数，不碰 DB，便于单元测试）：
     *  - 单位必须与标准商品一致（不一致要求管理员先改）
     *  - 同商品同规格已存在时返回 spec_exists，交由前端让管理员选择「删除此记录」或「修改规格型号」
     * @param array      $item     采购明细行（需含 spec/unit）
     * @param array      $prod     目标标准商品（需含 name/unit）
     * @param array|null $existing 已存在的同规格商品行，null 表示不存在
     */
    function map_guard(array $item, array $prod, ?array $existing = null): array
    {
        $itemUnit = norm_spec($item['unit'] ?? '');
        $prodUnit = norm_spec($prod['unit'] ?? '');
        if ($itemUnit !== $prodUnit) {
            return [
                'ok' => false,
                'code' => 'unit_mismatch',
                'msg' => "填报单位「{$itemUnit}」与标准商品单位「{$prodUnit}」不一致，请先修改",
                'item_unit' => $itemUnit,
                'product_unit' => $prodUnit,
            ];
        }
        if ($existing !== null) {
            $spec = norm_spec($existing['spec'] ?? $item['spec'] ?? '');
            return [
                'ok' => false,
                'code' => 'spec_exists',
                'msg' => "标准商品「" . ($prod['name'] ?? '') . "」已存在规格「{$spec}」",
                'existing' => [
                    'id' => (int)($existing['id'] ?? 0),
                    'spec' => $spec,
                    'unit' => norm_spec($existing['unit'] ?? ''),
                ],
            ];
        }
        return ['ok' => true, 'code' => '', 'msg' => ''];
    }

    /** 站内通知：插入 notifications（失败不影响主流程） */
    function notify_user(int $user_id, string $type, string $title, string $content = '', string $link = ''): void
    {
        try {
            db()->prepare("INSERT INTO notifications (user_id, type, title, content, is_read) VALUES (?,?,?,?,0)")
                ->execute([$user_id, $type, $title, mb_substr($content, 0, 500)]);
        } catch (Throwable $e) {
            // 通知失败不影响主流程
        }
        // 同步写平台消息（主页顶栏铃铛可见）；link 指向采购系统对应页面
        if ($link === '') {
            $link = match ($type) {
                'fill_submitted', 'budget_over' => '/app/purchase/overview',
                'returned', 'confirmed', 'price_imported' => '/app/purchase/fill',
                default => '',
            };
        }
        if ($link !== '') {
            try {
                $base = rtrim((string) (function_exists('config') ? config('app.url') : (getenv('APP_URL') ?: '')), '/');
                $full = ($base !== '' ? $base : 'https://oa.haoxuanyunshu.cn') . $link;
                db()->prepare("INSERT INTO payroll.app_messages (account_id, type, title, content, link, project_name, `read`, created_at, updated_at) VALUES (?,?,?,?,?,?,0,NOW(),NOW())")
                    ->execute([$user_id, $type, $title, mb_substr($content, 0, 500), $full, '']);
            } catch (Throwable $e) {}
        }
    }

    /** 该月指定项目的员工账号列表（平台 payroll_accounts 按项目名定向） */
    function project_user_ids(int $project_id): array
    {
        try {
            $name = project_name($project_id);
            $st = db()->prepare("SELECT id FROM payroll.payroll_accounts WHERE project_name=? AND enabled=1");
            $st->execute([$name]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 全部员工账号列表（平台 project/staff 角色） */
    function all_project_user_ids(): array
    {
        try {
            $st = db()->query("SELECT id FROM payroll.payroll_accounts WHERE role IN ('project','staff') AND enabled=1");
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 管理员账号列表（平台 admin 角色） */
    function admin_user_ids(): array
    {
        try {
            $st = db()->query("SELECT id FROM payroll.payroll_accounts WHERE role='admin' AND enabled=1");
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 该月该项目实际采购金额（archived_purchases 合计） */
    function month_project_actual(string $month, int $project_id): float
    {
        $st = db()->prepare("SELECT COALESCE(SUM(total),0) FROM archived_purchases WHERE month=? AND project_id=?");
        $st->execute([$month, $project_id]);
        return (float)$st->fetchColumn();
    }

    /** 项目名称 */
    function project_name(int $project_id): string
    {
        try {
            $st = db()->prepare("SELECT name FROM payroll.payroll_projects WHERE id=?");
            $st->execute([$project_id]);
            return (string)$st->fetchColumn();
        } catch (Throwable $e) {
            return '项目' . $project_id;
        }
    }

    /** 价格异常环比阈值（默认 30%，可配置） */
    function price_anomaly_threshold(): float
    {
        try {
            $st = db()->query("SELECT svalue FROM sys_settings WHERE skey='price_threshold' LIMIT 1");
            $v = (float)$st->fetchColumn();
            return $v > 0 ? $v : 30.0;
        } catch (Throwable $e) {
            return 30.0;
        }
    }
}
