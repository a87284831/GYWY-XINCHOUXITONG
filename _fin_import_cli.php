<?php
/**
 * 财务管理 - 历史数据 CLI 导入（大文件专用通道）
 * 用法: php _fin_import_cli.php <xlsx路径> [--preview-only]
 * 只导入：应收 2020-2025（含2020以前累计）+ 付款记录 2026年度
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

require_once app_path('Finance/Support.php');
require_once app_path('Finance/handlers/import.php'); // 常量 FIN_IMPORT_MAP 等

use App\Finance\XlsxParser;

$file = $argv[1] ?? '';
$previewOnly = in_array('--preview-only', $argv, true);
$force = in_array('--force', $argv, true);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "文件不存在: $file\n");
    exit(1);
}
if (!$previewOnly && !$force) {
    fwrite(STDERR, "确认导入请加 --force 参数（将覆盖 2020-2025 应收与 2026-12 付款记录）\n");
    exit(1);
}

try {
    $parser = new XlsxParser($file);
    $names = $parser->sheetNames();

    // ---- 预览统计 ----
    $recvSummary = [];
    foreach (FIN_IMPORT_SHEETS as $sheetName) {
        $gyName = FIN_IMPORT_MAP[$sheetName];
        $pid = fin_project_id_by_name($gyName);
        if ($pid === null) {
            echo "  [映射失败] $sheetName -> $gyName (系统项目库无此项目)\n";
            continue;
        }
        if (!in_array($sheetName, $names, true)) {
            echo "  [缺失sheet] $sheetName\n";
            continue;
        }
        $rows = $parser->readSheet($sheetName);
        $months = 0;
        $total = 0.0;
        $cells = 0;
        foreach ($rows as $rnum => $c) {
            if ($rnum <= 3) continue;
            $a = $c['A'] ?? null;
            $aStr = is_scalar($a) ? trim((string) $a) : '';
            $month = fin_import_parse_month($a);
            if ($month === null) {
                if (str_contains($aStr, '2020') && (str_contains($aStr, '以前') || str_contains($aStr, '之前'))) {
                    $month = '2020-01';
                } else {
                    continue;
                }
            }
            if (str_contains($aStr, '合计') || $aStr === '总计') continue;
            $months++;
            foreach (FIN_IMPORT_RECV_COLS as $col => $key) {
                $v = $c[$col] ?? null;
                if (is_numeric($v) && (float) $v != 0) {
                    $total += (float) $v;
                    $cells++;
                }
            }
        }
        $recvSummary[] = ['sheet' => $sheetName, 'project' => $gyName, 'pid' => $pid, 'months' => $months, 'cells' => $cells, 'total' => $total];
        echo sprintf("  [应收] %-8s -> %-10s (id=%d) 月份行=%d 金额格=%d 合计=%.2f 元\n", $sheetName, $gyName, $pid, $months, $cells, $total);
    }
    $recvGrand = array_sum(array_column($recvSummary, 'total'));
    echo "应收历史合计: " . number_format($recvGrand, 2) . " 元\n";

    // 付款记录预览
    $payTotal = 0.0;
    $payRows = 0;
    if (in_array('付款记录', $names, true)) {
        $rows = $parser->readSheet('付款记录');
        foreach ($rows as $rnum => $c) {
            if ($rnum < 3 || $rnum > 18) continue;
            $proj = trim((string) ($c['A'] ?? ''));
            if ($proj === '') continue;
            $gy = FIN_IMPORT_MAP[$proj] ?? null;
            if ($gy === null) continue;
            $pid = fin_project_id_by_name($gy);
            if ($pid === null) continue;
            $t = 0.0;
            foreach (FIN_IMPORT_PAY_COLS as $col => $key) {
                $v = $c[$col] ?? null;
                if (is_numeric($v) && (float) $v != 0) $t += (float) $v;
            }
            echo sprintf("  [付款] %-8s -> %-10s (id=%d) 合计=%.2f 元\n", $proj, $gy, $pid, $t);
            $payTotal += $t;
            $payRows++;
        }
        echo "付款记录合计: " . number_format($payTotal, 2) . " 元（" . $payRows . " 项目，将记入 2026-12）\n";
    }

    if ($previewOnly) {
        echo "===== 预览完成，未写库（--preview-only）=====\n";
        exit(0);
    }

    // ---- 执行导入 ----
    $db = fdb();
    $recvCount = 0;
    $recvTotal = 0.0;
    foreach ($recvSummary as $s) {
        $pid = (int) $s['pid'];
        // 先清 2020-2025
        $db->prepare("DELETE FROM fin_receivable_items WHERE project_id = ? AND month < '2026-01'")->execute([$pid]);
        $db->prepare("DELETE FROM fin_receivable_attachments WHERE project_id = ? AND month < '2026-01'")->execute([$pid]);
        $rows = $parser->readSheet($s['sheet']);
        $ins = $db->prepare(
            'INSERT INTO fin_receivable_items (project_id, month, category, amount, confirm, contract, payment, discount_amount, discount_households, remark, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $rnum => $c) {
            if ($rnum <= 3) continue;
            $a = $c['A'] ?? null;
            $aStr = is_scalar($a) ? trim((string) $a) : '';
            $month = fin_import_parse_month($a);
            $remark = '';
            if ($month === null) {
                if (str_contains($aStr, '2020') && (str_contains($aStr, '以前') || str_contains($aStr, '之前'))) {
                    $month = '2020-01';
                    $remark = '2020年以前累计（线下导入）';
                } else {
                    continue;
                }
            }
            if (str_contains($aStr, '合计') || $aStr === '总计') continue;

            $confirm = fin_import_parse_bool($c['J'] ?? null);
            $contract = fin_import_parse_bool($c['K'] ?? null);
            $payment = fin_import_parse_bool($c['L'] ?? null);
            $discountAmount = isset($c['Q']) && is_numeric($c['Q']) ? round((float) $c['Q'], 2) : null;
            $households = null;
            if (isset($c['R']) && $c['R'] !== null && trim((string) $c['R']) !== '' && !is_numeric($c['R'])) {
                $households = trim((string) $c['R']);
            } elseif (isset($c['R']) && is_numeric($c['R'])) {
                $households = (string) (int) $c['R'];
            }

            foreach (FIN_IMPORT_RECV_COLS as $col => $key) {
                $v = $c[$col] ?? null;
                if (!is_numeric($v) || (float) $v == 0) continue;
                $amount = round((float) $v, 2);
                $ins->execute([$pid, $month, $key, $amount, $confirm, $contract, $payment, $discountAmount, $households, $remark, '历史导入']);
                $recvCount++;
                $recvTotal += $amount;
            }
        }
        echo "  [导入] $s[sheet] 完成\n";
    }

    $payCount = 0;
    $payTotal2 = 0.0;
    if (in_array('付款记录', $names, true)) {
        $rows = $parser->readSheet('付款记录');
        $insP = $db->prepare('INSERT INTO fin_payment_items (project_id, month, type, amount, remark, updated_by) VALUES (?,?,?,?,?,?)');
        foreach ($rows as $rnum => $c) {
            if ($rnum < 3 || $rnum > 18) continue;
            $proj = trim((string) ($c['A'] ?? ''));
            if ($proj === '') continue;
            $gy = FIN_IMPORT_MAP[$proj] ?? null;
            if ($gy === null) continue;
            $pid = fin_project_id_by_name($gy);
            if ($pid === null) continue;
            $db->prepare("DELETE FROM fin_payment_items WHERE project_id = ? AND month = '2026-12'")->execute([$pid]);
            foreach (FIN_IMPORT_PAY_COLS as $col => $key) {
                $v = $c[$col] ?? null;
                if (!is_numeric($v) || (float) $v == 0) continue;
                $amount = round((float) $v, 2);
                $insP->execute([$pid, '2026-12', $key, $amount, '2026年度关联费用（线下导入）', '历史导入']);
                $payCount++;
                $payTotal2 += $amount;
            }
        }
    }

    echo "\n===== 导入完成 =====\n";
    echo "应收: {$recvCount} 条 / " . number_format($recvTotal, 2) . " 元\n";
    echo "付款: {$payCount} 条 / " . number_format($payTotal2, 2) . " 元\n";
} catch (Throwable $e) {
    fwrite(STDERR, "导入异常: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
