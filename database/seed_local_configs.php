<?php

/**
 * 从生产导出的配置 JSON 恢复本地库 legacy_json_snapshots + 重建 data 目录。
 *
 * 用法：php database/seed_local_configs.php [导出目录]
 * 默认导出目录：C:\Users\87284\Documents\Downloads
 *
 * 来源文件（生产站浏览器 console 导出，带 "ok":true 响应外壳）：
 *   prod_calc_rules.json / prod_symbols.json / prod_settings.json / prod_payslip_config.json
 *
 * 说明：生产 calc_rules.json 无 pay_rules 段（9/28 本地新增功能），
 * 脚本补季度绩效法（quarter_grade）三档骨架：经理级=季度型、主管/专员级=月度型；
 * 档位类型可在「系统设置-薪酬设置」页面随时调整并保存。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$srcDir = $argv[1] ?? 'C:\Users\87284\Documents\Downloads';
$map = [
    'prod_calc_rules.json'     => 'calc_rules.json',
    'prod_symbols.json'        => 'symbols.json',
    'prod_settings.json'       => 'settings.json',
    'prod_payslip_config.json' => 'payslip_config.json',
];

// data 目录在 laravel-app 上级（.env LEGACY_DATA_PATH=../data）
$dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

// 季度绩效法：每档仅 mode（quarter 季度型 / monthly 月度型），无比例、无半年度
$qgLevels = [
    '专员级' => ['mode' => 'monthly'],
    '主管级' => ['mode' => 'monthly'],
    '经理级' => ['mode' => 'quarter'],
];
$payRulesSkeleton = [
    // 员工/案场固定按月全额，不开放编辑（与前端保存逻辑一致）
    'staff'   => ['cycle' => 'monthly', 'ratio' => 1.0],
    'case'    => ['cycle' => 'monthly', 'ratio' => 1.0],
    // 管理/总部整体季度绩效法：经理级季度末发、主管/专员级按月发
    'manager' => ['cycle' => 'quarter_grade', 'levels' => $qgLevels],
    'hq'      => ['cycle' => 'quarter_grade', 'levels' => $qgLevels],
];

$restored = 0;
foreach ($map as $src => $name) {
    $path = $srcDir . DIRECTORY_SEPARATOR . $src;
    if (!is_file($path)) { echo "跳过（不存在）：{$src}\n"; continue; }
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json) || ($json['ok'] ?? false) !== true) {
        echo "跳过（格式异常或未登录导出）：{$src}\n";
        continue;
    }
    unset($json['ok']); // 剥掉响应外壳，仅保留 payload

    if ($name === 'calc_rules.json') {
        $json['rules']['pay_rules'] = $payRulesSkeleton;
    }

    $payload = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // 1) 写 data 目录：以后重建库时 seeder 可直接复用，配置不再随目录丢失
    file_put_contents($dataDir . DIRECTORY_SEPARATOR . $name, $payload);

    // 2) 写本地库（权威存储）
    $now = date('Y-m-d H:i:s');
    DB::table('legacy_json_snapshots')->updateOrInsert(
        ['file_name' => $name],
        ['payload' => $payload, 'imported_at' => $now, 'created_at' => $now, 'updated_at' => $now]
    );
    echo "已恢复：{$name}（" . strlen($payload) . " 字节）\n";
    $restored++;
}

echo "共恢复 {$restored} 个配置文件；data 目录：{$dataDir}\n";
if ($restored < 4) {
    echo "警告：不足 4 个，请检查导出目录是否正确。\n";
    exit(1);
}
