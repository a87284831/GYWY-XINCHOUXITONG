<?php
/** 财务管理模块运行支持：上下文注入、响应头采集、终止异常、PDO 连接、鉴权辅助。
 *  全局函数定义在尾部全局命名空间块，供无命名空间的 handler 文件直接调用。 */

namespace App\Finance {

    /** 终止异常：携带响应数据（JSON payload 或二进制输出 + 响应头） */
    class FinanceStop extends \Exception
    {
        public mixed $payload;
        public int $status;
        public array $headers;

        public function __construct(mixed $payload = null, int $status = 200, array $headers = [])
        {
            parent::__construct('finance-stop', 0, null);
            $this->payload = $payload;
            $this->status = $status;
            $this->headers = $headers;
        }
    }

    class Support
    {
        private static array $ctx = [];
        private static array $headers = [];

        public static function setContext(array $ctx): void
        {
            self::$ctx = $ctx;
        }

        public static function context(): array
        {
            return self::$ctx;
        }

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

    use App\Finance\FinanceStop;
    use App\Finance\Support;

    /** 数据库连接（gy_finance 独立库，PDO 单例） */
    function fdb(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $d = [
                'host'    => config('finance.host', '127.0.0.1'),
                'port'    => (int) config('finance.port', 3306),
                'dbname'  => config('finance.dbname', 'gy_finance'),
                'user'    => config('finance.user', 'payroll'),
                'pass'    => (string) config('finance.pass', '0ea0b4546a0b1e373d6a187990e420d3'),
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
                throw new FinanceStop(['ok' => false, 'msg' => '数据库连接失败: ' . $e->getMessage()], 500);
            }
        }
        return $pdo;
    }

    /** 当前财务用户（已由控制器注入平台映射上下文） */
    function fin_user(): ?array
    {
        $ctx = Support::context();
        return $ctx ?: null;
    }

    /** 应收费用 11 类 */
    function fin_receivable_categories(): array
    {
        return [
            'promise_discount'    => '承诺减免、优惠',
            'promise_defer'       => '承诺延期缴费',
            'unsold'              => '未售房',
            'handover_incomplete' => '整体交付手续不全',
            'payment_incomplete'  => '房款未结清、或手续不全',
            'policy_discount'     => '地区政策优惠',
            'repair_compensation' => '维修赔偿',
            'first_delivery_defer'=> '首交付延期',
            'setup_fee'           => '开办费',
            'deposit_deduction'   => '保证金及维修扣款',
            'other'               => '其他',
        ];
    }

    /** 付款记录 8 类（关联费用） */
    function fin_payment_types(): array
    {
        return [
            'setup_fee'          => '开办费',
            'cleaning_fee'       => '开荒保洁费',
            'acceptance_fee'     => '承接查验费',
            'property_settlement'=> '物业费结算',
            'greening_fee'       => '绿化费',
            'cleaning_outsource' => '保洁转包',
            'water_electric'     => '水电费',
            'house_repair'       => '房修合同',
        ];
    }

    /** 付款记录 3 个状态分类（与原表三区块一致） */
    function fin_payment_statuses(): array
    {
        return [
            'confirmed' => '已确认数据',
            'paid'      => '已支付',
            'unpaid'    => '未支付',
        ];
    }

    /** 财务项目清单（读 projects 兼容层表，排除重复/测试项目；2026-09-30 起与主库同库） */
    function fin_projects(): array
    {
        try {
            $st = fdb()->query("SELECT id, name, status FROM projects WHERE status = 1 ORDER BY sort_no, id");
            $rows = $st->fetchAll();
            $exclude = [17, 26]; // id=17 物业总部（管理端载体项目，非业务项目）；id=26 蓝钻庄园：与临沂/五莲蓝钻重复的历史遗留项目
            $out = [];
            foreach ($rows as $r) {
                $rid = (int) $r['id'];
                if (in_array($rid, $exclude, true)) continue;
                $out[] = ['id' => $rid, 'name' => (string) $r['name'], 'status' => (string) $r['status']];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 按名称找平台项目ID */
    function fin_project_id_by_name(string $name): ?int
    {
        foreach (fin_projects() as $p) {
            if ($p['name'] === $name) return $p['id'];
        }
        return null;
    }

    /** 当前用户是否为总部财务（admin / finance_admin） */
    function fin_is_admin(): bool
    {
        $u = fin_user();
        return $u && in_array($u['role'], ['admin', 'finance_admin'], true);
    }

    /** 当前用户可访问的项目ID列表：总部=全部，项目财务=绑定项目 */
    function fin_accessible_projects(): array
    {
        $u = fin_user();
        if (!$u) return [];
        if (fin_is_admin()) {
            return array_column(fin_projects(), 'id');
        }
        // 项目财务：仅绑定项目
        $pid = (int) ($u['project_id'] ?? 0);
        return $pid > 0 ? [$pid] : [];
    }

    /** 校验用户对某项目的访问权限（总部任意；项目财务仅绑定项目） */
    function fin_assert_project_access(int $projectId): int
    {
        $ids = fin_accessible_projects();
        if (!in_array($projectId, $ids, true)) {
            throw new FinanceStop(['ok' => false, 'msg' => '无权访问该项目数据'], 403);
        }
        return $projectId;
    }

    /** 校验月份格式 YYYY-MM，并限制在合理范围（2019-01 ~ 2035-12） */
    function fin_check_month(string $month): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new FinanceStop(['ok' => false, 'msg' => '月份格式错误'], 400);
        }
        [$y, $m] = explode('-', $month);
        if ((int) $y < 2019 || (int) $y > 2035 || (int) $m < 1 || (int) $m > 12) {
            throw new FinanceStop(['ok' => false, 'msg' => '月份超出允许范围'], 400);
        }
        return $month;
    }

    /** 年月数组（某年1-12月） */
    function fin_year_months(int $year): array
    {
        return array_map(fn($m) => sprintf('%04d-%02d', $year, $m), range(1, 12));
    }

    /** 解析 year 参数：返回 [是否全部, 年份字符串或null]；支持 all/空=全部年度 */
    function fin_parse_year(): array
    {
        $y = trim((string) ($_GET['year'] ?? date('Y')));
        if ($y === 'all' || $y === '0' || $y === '') return [true, null];
        $n = (int) $y;
        if ($n < 2019 || $n > 2035) throw new FinanceStop(['ok' => false, 'msg' => '年份超出范围'], 400);
        return [false, (string) $n];
    }

    /** 应收表全部有数据的月份（升序） */
    function fin_all_months(): array
    {
        $st = fdb()->query("SELECT DISTINCT month FROM fin_receivable_items ORDER BY month");
        $out = [];
        foreach ($st->fetchAll() as $r) $out[] = $r['month'];
        return $out;
    }

    /** 付款表全部有数据的月份（升序） */
    function fin_all_pay_months(): array
    {
        $st = fdb()->query("SELECT DISTINCT month FROM fin_payment_items ORDER BY month");
        $out = [];
        foreach ($st->fetchAll() as $r) $out[] = $r['month'];
        return $out;
    }
}
