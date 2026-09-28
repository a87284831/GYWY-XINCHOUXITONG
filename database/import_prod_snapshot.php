<?php

/**
 * 将生产快照 JSON（prod_projects/prod_staff/prod_org.json）灌入本地 SQLite。
 * 来源：生产站浏览器 console 导出（只读接口），字段结构见 DirectoryController/OrgController。
 * 用法：php database/import_prod_snapshot.php <json目录>
 * 幂等：全量替换 projects/staff/org_nodes/results 四表，不影响 accounts/settings。
 */

$dir = $argv[1] ?? '';
if (!is_dir($dir)) { fwrite(STDERR, "用法: php import_prod_snapshot.php <json目录>\n"); exit(1); }

$read = function (string $f) use ($dir) {
    $j = json_decode(file_get_contents($dir . DIRECTORY_SEPARATOR . $f), true);
    if (!is_array($j) || ($j['ok'] ?? false) !== true) {
        fwrite(STDERR, "$f 无效（不是 ok:true 的接口导出）\n");
        exit(1);
    }
    return $j;
};
$projectsJson = $read('prod_projects.json');
$staffJson    = $read('prod_staff.json');
$orgJson      = $read('prod_org.json');

$pdo = new PDO('sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'local.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = OFF');

$now = date('Y-m-d H:i:s');

$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM payroll_results');
    $pdo->exec('DELETE FROM payroll_staff');
    $pdo->exec('DELETE FROM payroll_projects');
    $pdo->exec('DELETE FROM org_nodes');

    // ── 项目 ──
    $insP = $pdo->prepare('INSERT INTO payroll_projects (id,name,status,data,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    foreach ($projectsJson['projects'] as $p) {
        $insP->execute([
            (int)$p['id'], (string)$p['name'], (string)($p['status'] ?? '启用'),
            isset($p['data']) ? json_encode($p['data'], JSON_UNESCAPED_UNICODE) : null,
            $p['created_at'] ?? $now, $p['updated_at'] ?? $now,
        ]);
    }

    // ── 组织架构（展平树，保留原 id，staff.org_id 依赖它；path 为接口派生值不落库）──
    $insO = $pdo->prepare('INSERT INTO org_nodes (id,parent_id,type,name,code,manager_staff_id,sort_order,enabled,hidden,contact,phone,address,aliases,note,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $orgCount = 0;
    $walk = function (array $nodes) use (&$walk, $insO, &$orgCount, $now) {
        foreach ($nodes as $n) {
            $insO->execute([
                (int)$n['id'], isset($n['parent_id']) ? (int)$n['parent_id'] : null,
                $n['type'] ?? null, (string)$n['name'], $n['code'] ?? null,
                isset($n['manager_staff_id']) ? (int)$n['manager_staff_id'] : null,
                (int)($n['sort_order'] ?? 0),
                !empty($n['enabled']) ? 1 : 0, !empty($n['hidden']) ? 1 : 0,
                $n['contact'] ?? null, $n['phone'] ?? null, $n['address'] ?? null,
                isset($n['aliases']) ? json_encode($n['aliases'], JSON_UNESCAPED_UNICODE) : null,
                $n['note'] ?? null, $now, $now,
            ]);
            $orgCount++;
            if (!empty($n['children'])) $walk($n['children']);
        }
    };
    $walk($orgJson['tree']);

    // ── 人员 ──
    // API 返回 = data JSON 展开 + 基础列；基础列灌列，其余键原样回填 data JSON
    $baseCols = ['id','name','project','position','status','fixed_monthly','base_salary','deleted',
        'org_id','dept_path','leader_id','is_manager','is_case_field','person_type','person_type_since',
        'hire_date','regular_date','resign_date','category'];
    $insS = $pdo->prepare('INSERT INTO payroll_staff (legacy_id,name,project_name,position,status,fixed_monthly,base_salary,deleted,org_id,dept_path,leader_id,is_manager,is_case_field,person_type,person_type_since,hire_date,regular_date,resign_date,data,dingtalk_userid,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($staffJson['staff'] as $s) {
        $data = array_diff_key($s, array_flip($baseCols));
        $int = fn ($v) => ($v === null || $v === '') ? null : (int)$v;
        $insS->execute([
            (int)$s['id'], (string)$s['name'], (string)($s['project'] ?? ''), $s['position'] ?? null,
            (string)($s['status'] ?? '在职'),
            ($s['fixed_monthly'] ?? null) === null ? null : (float)$s['fixed_monthly'],
            ($s['base_salary'] ?? null) === null ? null : (float)$s['base_salary'],
            !empty($s['deleted']) ? 1 : 0,
            $int($s['org_id'] ?? null), $s['dept_path'] ?? null, $int($s['leader_id'] ?? null),
            !empty($s['is_manager']) ? 1 : 0, !empty($s['is_case_field']) ? 1 : 0,
            $s['person_type'] ?? 'staff', $s['person_type_since'] ?? null,
            $s['hire_date'] ?? null, $s['regular_date'] ?? null, $s['resign_date'] ?? null,
            json_encode($data, JSON_UNESCAPED_UNICODE), null, $now, $now,
        ]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '导入失败，已回滚: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach (['payroll_projects', 'org_nodes', 'payroll_staff'] as $t) {
    printf("%s: %d 行\n", $t, $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn());
}
$pg = $pdo->query("SELECT COUNT(*) FROM payroll_staff WHERE deleted=0 AND json_extract(data,'$.pay_grade') IS NOT NULL AND json_extract(data,'$.pay_grade')!=''")->fetchColumn();
printf("在职且有薪酬档位: %d 行\n", $pg);
echo "完成\n";
