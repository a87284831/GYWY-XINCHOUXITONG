<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use RuntimeException;

/**
 * 组织架构主数据服务：组织树工具 + 老数据自动迁移（org_migrate）。
 * 树模型：company(根) → region(可选) → project(核算单元) → department(标准5部门) → team(可选)
 */
class OrgService
{
    public const DEPT_KEYWORDS = [
        '秩序部' => ['秩序', '保安', '门岗', '监控'],
        '环境部' => ['保洁', '绿化', '环境'],
        '工程部' => ['工程', '维修', '弱电', '电工', '水暖', '机电', '电梯', '维保'],
        '客服部' => ['客服', '管家', '前台', '收费'],
        '总经理办公室' => ['经理', '主任', '综管', '行政', '人事', '财务', '文员', '出纳', '会计', '薪酬', '库管'],
    ];

    /** 标准部门（固定顺序） */
    public const STANDARD_DEPTS = ['客服部', '工程部', '秩序部', '环境部', '总经理办公室'];

    /** 获取全部节点，按 id 索引 */
    public function all(): array
    {
        return DB::table('org_nodes')->orderBy('sort_order')->get()->keyBy('id')->all();
    }

    /** 某个节点的子孙 id 集合（含自身） */
    public function descendants(int $id): array
    {
        $ids = [$id];
        $nodes = DB::table('org_nodes')->get();
        $children = [];
        foreach ($nodes as $n) {
            if ($n->parent_id !== null) $children[(int)$n->parent_id][] = (int)$n->id;
        }
        $stack = [$id];
        while ($stack) {
            $cur = array_pop($stack);
            foreach ($children[$cur] ?? [] as $c) {
                $ids[] = $c;
                $stack[] = $c;
            }
        }
        return $ids;
    }

    /** 祖先链（从父到根），含自身 */
    public function ancestors(int $id, ?array $nodes = null): array
    {
        $nodes = $nodes ?? $this->all();
        $out = [];
        $cur = $nodes[$id] ?? null;
        $guard = 0;
        while ($cur && $guard++ < 100) {
            array_unshift($out, $cur);
            $cur = isset($cur->parent_id, $nodes[(int)$cur->parent_id]) ? $nodes[(int)$cur->parent_id] : null;
        }
        return $out;
    }

    /** 向上找到最近的 project 类型节点（人员由此继承项目） */
    public function projectOf(int $orgId): ?object
    {
        $nodes = $this->all();
        foreach ($this->ancestors($orgId, $nodes) as $node) {
            if ($node->type === 'project') return $node;
        }
        return null;
    }

    /** 生成 "项目/部门/班组" 路径显示（排除 company/region） */
    public function path(int $orgId): string
    {
        $names = [];
        foreach ($this->ancestors($orgId) as $node) {
            if (in_array($node->type, ['company', 'region'], true)) continue;
            $names[] = $node->name;
        }
        return implode('/', $names);
    }

    /** 校验父节点下允许创建的子类型 */
    public function allowedChildType(string $parentType): string
    {
        return match ($parentType) {
            'company' => 'region',          // 根下可建区域
            'region'  => 'project',         // 区域下建项目
            'project' => 'department',      // 项目下建部门
            'department' => 'team',         // 部门下建班组
            'team'    => 'team',
            default   => 'department',
        };
    }

    /** 判断某类型是否为末级可挂人员节点 */
    public static function isLeafType(string $type): bool
    {
        return in_array($type, ['department', 'team'], true);
    }

    /** 按岗位关键词匹配部门名（返回标准部门名或 null=待分配） */
    public static function matchDeptByPosition(?string $position): ?string
    {
        $position = (string)$position;
        if ($position === '') return null;
        foreach (self::DEPT_KEYWORDS as $dept => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($position, $kw) !== false) return $dept;
            }
        }
        return null;
    }

    /**
     * 批量导入/接口统一的部门解析：项目名 + 显式部门名(可空) + 岗位(可空) → 末级部门节点。
     * 规则：显式部门名（精确→包含）优先；否则按岗位关键词匹配；都不命中返回 null（待分配）。
     */
    public function resolveDeptNode(string $projectName, ?string $deptHint = null, ?string $position = null): ?object
    {
        $projectNode = DB::table('org_nodes')->where('type', 'project')->where('name', $projectName)->first();
        if (!$projectNode) return null;
        // 隐藏的部门不再参与自动归类（与人事档案选部门下拉保持一致：隐藏=停用不显示）
        $depts = DB::table('org_nodes')->where('parent_id', $projectNode->id)
            ->whereIn('type', ['department', 'team'])->where('hidden', 0)->get();
        $hint = trim((string)$deptHint);
        if ($hint !== '') {
            foreach ($depts as $d) { if ($d->name === $hint) return $d; }                 // 精确
            foreach ($depts as $d) {                                                     // 包含/被包含
                if (mb_strpos($d->name, $hint) !== false || mb_strpos($hint, $d->name) !== false) return $d;
            }
        }
        $deptName = self::matchDeptByPosition($position);
        if ($deptName) {
            foreach ($depts as $d) { if ($d->name === $deptName) return $d; }
        }
        return null;
    }

    /**
     * 老数据自动迁移（幂等）：
     * 1. 建根公司节点；2. 启用项目建 project 节点；3. 每项目建 5 标准部门；
     * 4. 人员按岗位关键词归部门；5. 账号按姓名/登录名绑定人员；
     * 6. 迁移前自动备份 zip；返回迁移报告。
     */
    public function migrate(?string $byUser = null): array
    {
        if (DB::table('org_nodes')->where('type', 'company')->exists()) {
            return ['ok' => false, 'error' => '组织架构已初始化，无需重复迁移（可到组织页微调）'];
        }

        // 迁移前自动备份（JSON 快照 + 关键业务表导出）
        $this->autoBackup();

        $report = ['company' => 0, 'projects' => 0, 'departments' => 0, 'staff_matched' => 0,
            'staff_pending' => 0, 'accounts_bound' => 0, 'accounts_pending' => 0];

        DB::transaction(function () use (&$report, $byUser) {
            $now = now();
            // 1. 根公司
            $companyId = DB::table('org_nodes')->insertGetId([
                'parent_id' => null, 'type' => 'company', 'name' => '广盈物业',
                'sort_order' => 1, 'enabled' => true,
                'data' => json_encode(['default' => true], JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $report['company'] = 1;

            // 2. 每个启用项目建 project 节点
            $projects = DB::table('payroll_projects')->orderBy('id')->get();
            $projectNodeId = [];
            foreach ($projects as $p) {
                $legacy = $this->jsonValue($p->data) ?: [];
                $nodeId = DB::table('org_nodes')->insertGetId([
                    'parent_id' => $companyId, 'type' => 'project', 'name' => $p->name,
                    'sort_order' => $p->id, 'enabled' => ($p->status ?? '启用') !== '停用',
                    'contact' => $legacy['contact'] ?? null, 'phone' => $legacy['phone'] ?? null,
                    'address' => $legacy['address'] ?? null,
                    'aliases' => json_encode($legacy['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
                    'note' => $legacy['note'] ?? null,
                    'data' => json_encode(['project' => true], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $projectNodeId[$p->name] = $nodeId;
                $report['projects']++;

                // 3. 每项目建 5 标准部门
                foreach (self::STANDARD_DEPTS as $i => $deptName) {
                    DB::table('org_nodes')->insertGetId([
                        'parent_id' => $nodeId, 'type' => 'department', 'name' => $deptName,
                        'sort_order' => $i + 1, 'enabled' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $report['departments']++;
                }
            }

            // 4. 人员按岗位关键词归部门
            $staff = DB::table('payroll_staff')->get();
            foreach ($staff as $s) {
                $pid = $projectNodeId[$s->project_name] ?? null;
                if (!$pid) continue; // 项目已停用/不存在则不挂
                $deptName = self::matchDeptByPosition($s->position);
                $deptNode = $deptName
                    ? DB::table('org_nodes')->where('parent_id', $pid)->where('name', $deptName)->first()
                    : null;
                $orgId = $deptNode?->id;
                $deptPath = $orgId ? $this->path($orgId) : ($s->project_name . '/待分配');
                DB::table('payroll_staff')->where('legacy_id', $s->legacy_id)->update([
                    'org_id' => $orgId, 'dept_path' => $deptPath, 'updated_at' => $now,
                ]);
                if ($deptNode) $report['staff_matched']++;
                else $report['staff_pending']++;
            }

            // 5. 账号按姓名/登录名绑定人员（admin 内置豁免）
            $accounts = DB::table('payroll_accounts')->get();
            foreach ($accounts as $acc) {
                if ($acc->username === 'admin') {
                    DB::table('payroll_accounts')->where('id', $acc->id)->update([
                        'enabled' => true, 'staff_id' => null, 'updated_at' => $now,
                    ]);
                    continue;
                }
                $staff = DB::table('payroll_staff')
                    ->where('name', $acc->name ?? '')
                    ->orWhere(function ($q) use ($acc) { $q->where('name', $acc->username); })
                    ->first();
                if ($staff) {
                    DB::table('payroll_accounts')->where('id', $acc->id)->update([
                        'staff_id' => $staff->legacy_id, 'enabled' => !(bool)$staff->deleted,
                        'project_name' => $staff->project_name, 'updated_at' => $now,
                    ]);
                    $report['accounts_bound']++;
                } else {
                    DB::table('payroll_accounts')->where('id', $acc->id)->update([
                        'enabled' => true, 'updated_at' => $now,
                    ]);
                    $report['accounts_pending']++;
                }
            }
        });

        return ['ok' => true, 'report' => $report];
    }

    /** 迁移前自动备份：JSON 快照 + 业务表导出为 SQL/JSON 放入 storage/app/private/backups */
    public function autoBackup(): string
    {
        $name = 'org_migrate_backup_' . now()->format('Ymd_His') . '.zip';
        $path = Storage::disk('local')->path('backups/' . $name);
        Storage::disk('local')->makeDirectory('backups');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        // JSON 快照
        foreach (DB::table('legacy_json_snapshots')->get() as $snapshot) {
            $zip->addFromString($snapshot->file_name,
                json_encode($this->jsonValue($snapshot->payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        // 业务表导出（新增 org 前的核心数据）
        foreach (['payroll_staff', 'payroll_projects', 'payroll_roles', 'payroll_accounts',
                     'payroll_attendance', 'payroll_results', 'payroll_budgets', 'payroll_salary_adjustments',
                     'maintenance_partners', 'maintenance_contracts', 'performance_plans'] as $table) {
            try {
                $rows = DB::table($table)->get()->map(fn($r) => (array)$r)->all();
                $zip->addFromString($table . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (\Throwable $e) {
                // 表可能不存在，跳过
            }
        }
        $zip->close();
        return $name;
    }

    private function jsonValue($v): ?array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : null; }
        return null;
    }
}
