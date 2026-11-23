<?php
/** 导出Excel / 导入存档 */

/** 导入报价前置校验：必须存在填报且全部已确认 */
function purchase_import_status_guard(int $total, int $confirmed): ?string {
    if ($total === 0) return '本月无填报记录，不能导入报价';
    if ($confirmed < $total) return '本月尚有 ' . ($total - $confirmed) . ' 条填报未确认，全部确认后才能导入报价';
    return null;
}

/**
 * 把报价存档行关联到填报明细 id（M3）。
 * 匹配键：项目 + 条线 + 商品名称 + 品牌 + 规格 + 单位；优先数量一致，且同一条填报只使用一次。
 * 返回带 item_id 的行；找不到对应填报时 item_id 为 null（保留旧数据兼容）。
 */
function purchase_match_item_ids(array $items, array $rows): array {
    $buckets = [];
    foreach ($items as $it) {
        $key = implode('|', [
            (int)($it['project_id'] ?? 0), (string)($it['line'] ?? ''),
            (string)($it['item_name'] ?? ''), (string)($it['brand'] ?? ''),
            (string)($it['spec'] ?? ''), (string)($it['unit'] ?? ''),
        ]);
        $buckets[$key][] = $it;
    }
    $used = [];
    foreach ($rows as &$r) {
        $key = implode('|', [
            (int)($r['project_id'] ?? 0), (string)($r['line'] ?? ''),
            (string)($r['item_name'] ?? ''), (string)($r['brand'] ?? ''),
            (string)($r['spec'] ?? ''), (string)($r['unit'] ?? ''),
        ]);
        $chosen = null; $fallback = null;
        foreach ($buckets[$key] ?? [] as $cand) {
            $cid = (int)$cand['id'];
            if (isset($used[$cid])) continue;
            if ($fallback === null) $fallback = $cand;
            if (abs((float)($cand['quantity'] ?? 0) - (float)($r['quantity'] ?? 0)) < 0.005) {
                $chosen = $cand;
                break;
            }
        }
        $chosen = $chosen ?: $fallback;
        if ($chosen !== null) {
            $r['item_id'] = (int)$chosen['id'];
            $used[(int)$chosen['id']] = true;
        } else {
            $r['item_id'] = null;
        }
    }
    unset($r);
    return $rows;
}


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// 公共样式：标题（深蓝底白字）、表头（蓝底白字）、数据
function style_title($ws, $range, $rowNum, $text, $size = 16) {
    $ws->getStyle($range)->getFont()->setBold(true)->setSize($size)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF366092');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension($rowNum)->setRowHeight(32);
}
function style_header($ws, $range, $rowNum, $rowHeight = 30) {
    $ws->getStyle($range)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getRowDimension($rowNum)->setRowHeight($rowHeight);
}
function style_data($ws, $range) {
    $ws->getStyle($range)->getFont()->setSize(10);
    $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// 导出：汇总表 + 5条线明细（单价/合计留空供填写）
// 参考价：同商品同规格最近一次采购单价（缓存）
function ref_price($item_name, $spec, $beforeMonth): float {
    static $cache = [];
    $key = $item_name . '|' . $spec;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = db()->prepare("SELECT price FROM archived_purchases WHERE item_name=? AND spec=? AND month<? AND price>0 ORDER BY month DESC, id DESC LIMIT 1");
    $st->execute([$item_name, $spec, $beforeMonth]);
    $v = (float)$st->fetchColumn();
    $cache[$key] = $v;
    return $v;
}

/**
 * 汇总收集表「主要采购内容」聚合：按 商品名+规格 合并当月明细（数量累加），
 * 排序分 = 数量×单价（无单价回退参考价，仍无价按数量兜底），金额大的排前面。
 * $priceOf 可注入以便单测；生产默认 ref_price()。
 */
function purchase_plan_aggregate_items(array $rows, string $month, ?callable $priceOf = null): array {
    $priceOf = $priceOf ?: fn($name, $spec) => ref_price($name, $spec, $month);
    $agg = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['item_name'] ?? ''));
        if ($name === '') continue;
        $spec = trim((string)($r['spec'] ?? ''));
        $key = $name . '|' . $spec;
        $qty = (float)($r['quantity'] ?? 0);
        $price = (float)($r['price'] ?? 0);
        if (!isset($agg[$key])) $agg[$key] = ['name' => $name, 'spec' => $spec, 'qty' => 0.0, 'amount' => 0.0];
        $agg[$key]['qty'] += $qty;
        $agg[$key]['amount'] += $price > 0 ? $qty * $price : $qty;
    }
    usort($agg, fn($a, $b) => ($b['amount'] <=> $a['amount']) ?: strcmp($a['name'] . $a['spec'], $b['name'] . $b['spec']));
    return array_values($agg);
}

/** 「主要采购内容」列文本：金额前 3 项（品名(规格)×数量），更多时追加「等共 N 项」；无明细留空 */
function purchase_plan_top_items(array $agg, int $limit = 3): string {
    $n = count($agg);
    if ($n === 0) return '';
    $parts = [];
    foreach (array_slice($agg, 0, $limit) as $it) {
        $qty = rtrim(rtrim(sprintf('%.2f', $it['qty']), '0'), '.');
        $parts[] = $it['name'] . ($it['spec'] !== '' ? '(' . $it['spec'] . ')' : '') . '×' . $qty;
    }
    $text = implode('、', $parts);
    if ($n > $limit) $text .= ' 等共 ' . $n . ' 项';
    return $text;
}

/** 汇总收集表表头：12 列（2026-11-23 删除「采购理由」列——存档月取不到 reason 恒为空，误导） */
function purchase_plan_summary_headers(): array {
    return ['序号', '采购项目名称', "环境条线\n(元)", "绿化条线\n(元)", "工程条线\n(元)", "秩序条线\n(元)", "办公用品\n(元)", "小计\n(元)", "预算金额\n(元)", "实际金额\n(元)", '预算执行率', '主要采购内容'];
}

function handle_export_excel() {
    require_admin();
    $month = $_GET['month'] ?? date('Y-m');
    $projects = array_map(fn($p) => ["id" => $p["id"], "name" => $p["name"], "manager" => "", "phone" => ""], projects_all());

    // 明细数据源：该月已导入存档 → 用存档（含本次实际单价）；未导入 → 用填报记录（含参考价）
    $stArch = db()->prepare("SELECT COUNT(*) FROM archived_purchases WHERE month=?");
    $stArch->execute([$month]);
    $hasArchive = (int)$stArch->fetchColumn() > 0;
    if ($hasArchive) {
        $st = db()->prepare(
            "SELECT ap.id, ap.item_id, ap.month, ap.project_id, ap.line, ap.product_id, ap.item_name, ap.brand, ap.spec, ap.unit,
                    ap.quantity, ap.price, 0 AS stock,
                    COALESCE(pi.reason, '') AS reason, COALESCE(pi.use_location, '') AS use_location, ap.remark,
                    p.name AS project_name
             FROM archived_purchases ap
             JOIN payroll.payroll_projects p ON ap.project_id=p.id
             LEFT JOIN purchase_items pi ON pi.id = ap.item_id
             WHERE ap.month=? ORDER BY FIELD(ap.line,'环境','绿化','工程','秩序','行政'), ap.project_id, ap.id"
        );
        $st->execute([$month]);
        $items = $st->fetchAll();
    } else {
        $st = db()->prepare(
            "SELECT pi.*, pi.id AS item_id, p.name AS project_name FROM purchase_items pi
             JOIN payroll.payroll_projects p ON pi.project_id=p.id
             WHERE pi.month=? AND pi.status IN ('submitted','confirmed')
             ORDER BY FIELD(pi.line,'环境','绿化','工程','秩序','行政'), pi.project_id, pi.id"
        );
        $st->execute([$month]);
        $items = $st->fetchAll();
    }
    $imageMap = purchase_image_map(array_column($items, 'item_id'));

    $spreadsheet = new Spreadsheet();

    // 该月各项目 × 条线实际金额（已导入存档）
    $stA = db()->prepare("SELECT project_id, line, SUM(total) AS amount FROM archived_purchases WHERE month=? GROUP BY project_id, line");
    $stA->execute([$month]);
    $actualByLine = [];
    foreach ($stA->fetchAll() as $r) $actualByLine[(int)$r['project_id']][$r['line']] = (float)$r['amount'];
    // 该月各项目预算
    $stB = db()->prepare("SELECT project_id, amount FROM budget_plan WHERE month=?");
    $stB->execute([$month]);
    $budgetMap = [];
    foreach ($stB->fetchAll() as $r) $budgetMap[(int)$r['project_id']] = (float)$r['amount'];

    // ========== Sheet1 汇总表（仿用户原表样式，含预算/执行率，标题带月份） ==========
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('月度采购计划汇总收集表');
    $titleMonth = sprintf('%d年%d月', (int)substr($month, 0, 4), (int)substr($month, 5, 2));
    $ws->mergeCells('A1:L1');
    $ws->setCellValue('A1', "广盈物业 {$titleMonth} 月度采购计划收集表");
    style_title($ws, 'A1:L1', 1, "广盈物业 {$titleMonth} 月度采购计划收集表");

    $ws->setCellValue('A2', '统计月份：' . $month);
    $ws->setCellValue('C2', '填报日期：');
    $ws->setCellValue('E2', '汇总人：');
    $ws->mergeCells('I2:L2');
    $ws->setCellValue('I2', '备注：请各项目于每月20-23日完成填报，招采确认后统一报价');
    $ws->getStyle('A2:H2')->getFont()->setSize(10);
    $ws->getStyle('I2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
    $ws->getStyle('A2:H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(2)->setRowHeight(24);

    // 表头（第4行）：序号/项目/5条线/小计/预算/实际/执行率/主要采购内容（12 列，无「采购理由」）
    $headers = purchase_plan_summary_headers();
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '4', $h);
    }
    style_header($ws, 'A4:L4', 4, 40);

    // 按项目归集明细
    $byProject = [];
    foreach ($items as $it) {
        $byProject[$it['project_id']][] = $it;
    }
    $row = 5;
    $no = 1;
    $totBudget = 0; $totActual = 0;
    foreach ($projects as $p) {
        $ws->setCellValue('A' . $row, $no);
        $ws->setCellValue('B' . $row, $p['name']);
        $lineAmounts = $actualByLine[$p['id']] ?? [];
        $lineSum = 0;
        foreach (['环境', '绿化', '工程', '秩序', '行政'] as $li => $lineName) {
            $v = $lineAmounts[$lineName] ?? 0;
            if ($v > 0) $ws->setCellValue(Coordinate::stringFromColumnIndex($li + 3) . $row, round($v, 2));
            $lineSum += $v;
        }
        if ($lineSum > 0) $ws->setCellValue('H' . $row, round($lineSum, 2));
        $budget = $budgetMap[$p['id']] ?? 0;
        $actual = round($lineSum, 2);
        $totBudget += $budget; $totActual += $actual;
        if ($budget > 0) $ws->setCellValue('I' . $row, round($budget, 2));
        if ($actual > 0) $ws->setCellValue('J' . $row, $actual);
        // 预算执行率
        if ($budget > 0) {
            $rate = round($actual / $budget * 100, 1);
            $rateTxt = number_format($rate, 1);
            $rateText = $actual > $budget ? "超支 {$rateTxt}%" : "预算内 {$rateTxt}%";
            $ws->setCellValue('K' . $row, $rateText);
            $ws->getStyle('K' . $row)->getFont()->getColor()->setARGB($actual > $budget ? 'FFC00000' : 'FF008000');
        } else {
            $ws->setCellValue('K' . $row, '--');
            $ws->getStyle('K' . $row)->getFont()->getColor()->setARGB('FF999999');
        }
        $lineItems = $byProject[$p['id']] ?? [];
        if (!$lineItems) {
            // 无填报记录（历史存档月）：从存档归集采购内容
            $stA2 = db()->prepare("SELECT item_name, spec, quantity, price FROM archived_purchases WHERE month=? AND project_id=? ORDER BY line, id");
            $stA2->execute([$month, $p['id']]);
            $lineItems = $stA2->fetchAll();
        }
        // 主要采购内容：金额前 3 项（品名(规格)×数量）+ 总数，避免整墙文字
        $ws->setCellValue('L' . $row, purchase_plan_top_items(purchase_plan_aggregate_items($lineItems, $month)));
        style_data($ws, "A{$row}:L{$row}");
        $ws->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $ws->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle('K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
        $ws->getRowDimension($row)->setRowHeight(22);
        $row++;
        $no++;
    }
    // 总计行
    $ws->setCellValue('A' . $row, '');
    $ws->setCellValue('B' . $row, '月度合计：');
    if ($totBudget > 0) $ws->setCellValue('I' . $row, round($totBudget, 2));
    if ($totActual > 0) $ws->setCellValue('J' . $row, round($totActual, 2));
    $tr = $totBudget > 0 ? round($totActual / $totBudget * 100, 1) : null;
    $rateText = $tr === null ? '--' : ($totActual > $totBudget ? '超支 ' . number_format($tr, 1) . '%' : '预算内 ' . number_format($tr, 1) . '%');
    $ws->setCellValue('K' . $row, $rateText);
    if ($totBudget > 0) $ws->getStyle('K' . $row)->getFont()->getColor()->setARGB($totActual > $totBudget ? 'FFC00000' : 'FF008000');
    $ws->getStyle("A{$row}:L{$row}")->getFont()->setBold(true);
    $ws->getStyle("A{$row}:L{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E2F3');
    style_data($ws, "A{$row}:L{$row}");

    foreach (['A' => 6, 'B' => 20, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 12, 'J' => 12, 'K' => 12, 'L' => 38] as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ws->freezePane('C5');
    // 数字格式
    $ws->getStyle("C5:K{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

    // ========== 条线明细表（美化） ==========
    $lineSheets = ['环境' => '环境类', '绿化' => '绿化类', '工程' => '工程类', '秩序' => '秩序部', '行政' => '行政办公类'];
    foreach ($lineSheets as $line => $title) {
        $lws = $spreadsheet->createSheet();
        $lws->setTitle($title);
        $isEng = ($line === '工程');
        $lheaders = ['序号', '项目名称', '商品名称', '品牌', '规格型号', '单位', '数量', '单价', '合计', '项目库存', '申购原因'];
        if ($isEng) $lheaders[] = '使用位置';
        $lheaders[] = '备注';
        $lheaders[] = '示例图片';
        $maxCol = Coordinate::stringFromColumnIndex(count($lheaders));
        $lws->mergeCells("A1:{$maxCol}1");
        $lws->setCellValue('A1', '广盈物业 ' . $month . ' ' . $title . '采购明细');
        style_title($lws, "A1:{$maxCol}1", 1, '广盈物业 ' . $month . ' ' . $title . '采购明细', 14);

        foreach ($lheaders as $i => $h) {
            $lws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '2', $h);
        }
        // 表头批注说明单价来源
        $priceNote = $hasArchive ? '单价列为该月本次实际采购单价，可直接沿用或修改' : '单价列已预填该商品最近一次采购价（参考价），可直接沿用或修改';
        $lws->getComment(Coordinate::stringFromColumnIndex(8) . '2')->getText()
            ->createTextRun($priceNote);
        style_header($lws, "A2:{$maxCol}2", 2, 30);

        $r = 3;
        $seq = 1;
        foreach ($items as $it) {
            if ($it['line'] !== $line) continue;
            $lws->setCellValue('A' . $r, $seq);
            $lws->setCellValue('B' . $r, $it['project_name']);
            $lws->setCellValue('C' . $r, $it['item_name']);
            $lws->setCellValue('D' . $r, $it['brand']);
            $lws->setCellValue('E' . $r, $it['spec']);
            $lws->setCellValue('F' . $r, $it['unit']);
            $lws->setCellValue('G' . $r, (float)$it['quantity']);
            // 单价：已导入 → 本次实际单价；未导入 → 参考价（黄色底纹）
            $hasPrice = $hasArchive && (float)$it['price'] > 0;
            if ($hasPrice) {
                $lws->setCellValue('H' . $r, (float)$it['price']);
                $lws->setCellValue('I' . $r, round((float)$it['quantity'] * (float)$it['price'], 2));
            } else {
                $refPrice = ref_price($it['item_name'], $it['spec'], $month);
                if ($refPrice > 0) {
                    $lws->setCellValue('H' . $r, $refPrice);
                    $lws->setCellValue('I' . $r, round((float)$it['quantity'] * $refPrice, 2));
                    $lws->getStyle('H' . $r)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
                }
            }
            $lws->setCellValue('J' . $r, (float)$it['stock']);
            $lws->setCellValue('K' . $r, $it['reason']);
            $col = 12;
            if ($isEng) {
                $lws->setCellValue('L' . $r, $it['use_location']);
                $col = 13;
            }
            $lws->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $it['remark']);
            $imgCol = Coordinate::stringFromColumnIndex($col + 1);
            $hasImg = purchase_image_embed($lws, $imgCol . $r, $imageMap[(int)($it['item_id'] ?? 0)] ?? null);
            style_data($lws, "A{$r}:{$maxCol}{$r}");
            $lws->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $lws->getStyle('B' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $lws->getStyle('C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $lws->getStyle('G' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $lws->getStyle('H' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $lws->getStyle('I' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $lws->getRowDimension($r)->setRowHeight($hasImg ? 60 : 20);
            $r++;
            $seq++;
        }
        foreach (['A' => 6, 'B' => 18, 'C' => 20, 'D' => 13, 'E' => 20, 'F' => 8, 'G' => 10, 'H' => 12, 'I' => 12, 'J' => 10, 'K' => 22, 'L' => 13, 'M' => 13] as $col => $w) {
            if (in_array($col, range('A', $maxCol))) $lws->getColumnDimension($col)->setWidth($w);
        }
        $lws->getColumnDimension($maxCol)->setWidth(14);
        $lws->getStyle("G2:{$maxCol}2")->getNumberFormat()->setFormatCode('#,##0.00');
        $lws->freezePane('A3');
    }

    // 输出
    $fileName = "广盈物业_{$month}_采购计划统计表.xlsx";
    $asciiName = 'purchase_plan_' . $month . '.xlsx';
    log_action('导出报价', "{$month} 导出采购计划统计表（{$fileName}）");
    \App\Purchase\Support::header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    \App\Purchase\Support::header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
        throw new \App\Purchase\PurchaseStop();
}

// 员工端导出：本项目当月明细（简化表：一行一条）
function handle_export_my_month() {
    $user = require_auth();
    $month = $_GET['month'] ?? date('Y-m');
    if ($user['role'] !== 'admin') {
        $project_id = (int)$user['project_id'];
    } else {
        $project_id = (int)($_GET['project_id'] ?? 0);
    }
    if ($project_id <= 0) return ['ok' => false, 'msg' => '项目缺失'];
    $pname = project_name($project_id);
    // 已导入：读存档（含实际单价金额）；未导入：读填报记录
    $st = db()->prepare("SELECT item_id, line, item_name, spec, brand, unit, quantity, price, total, remark FROM archived_purchases WHERE project_id=? AND month=? ORDER BY line, id");
    $st->execute([$project_id, $month]);
    $rows = $st->fetchAll();
    $imported = count($rows) > 0;
    if (!$imported) {
        $st = db()->prepare("SELECT id AS item_id, line, item_name, spec, brand, unit, quantity, stock, reason FROM purchase_items WHERE project_id=? AND month=? ORDER BY line, id");
        $st->execute([$project_id, $month]);
        $rows = $st->fetchAll();
    }
    $imageMap = purchase_image_map(array_column($rows, 'item_id'));

    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('填报明细');
    // 未导入报价时与管理端导出保持同一口径：展示参考价并明确标注，避免两份文件金额不一致
    $titleText = "广盈物业 {$month} {$pname} 采购填报明细" . ($imported ? '' : '（报价未导入，单价/金额为参考价）');
    $ws->mergeCells('A1:K1');
    $ws->setCellValue('A1', $titleText);
    style_title($ws, 'A1:K1', 1, $titleText, 14);

    $headers = ['条线', '商品名称', '规格型号', '品牌', '单位', '数量',
        $imported ? '单价（元）' : '单价（参考价）', $imported ? '金额（元）' : '金额（参考价）', '申购原因', '库存', '示例图片'];
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '2', $h);
    }
    style_header($ws, 'A2:K2', 2, 28);

    $r = 3;
    $totQty = 0; $totAmount = 0;
    foreach ($rows as $it) {
        $ws->setCellValue('A' . $r, $it['line']);
        $ws->setCellValue('B' . $r, $it['item_name']);
        $ws->setCellValue('C' . $r, $it['spec']);
        $ws->setCellValue('D' . $r, $it['brand']);
        $ws->setCellValue('E' . $r, $it['unit']);
        $ws->setCellValue('F' . $r, (float)$it['quantity']);
        $rowAmount = 0;
        if ($imported) {
            $rowAmount = round((float)$it['total'], 2);
            $ws->setCellValue('G' . $r, (float)$it['price']);
            $ws->setCellValue('H' . $r, $rowAmount);
        } else {
            $refPrice = ref_price((string)$it['item_name'], (string)($it['spec'] ?? ''), $month);
            if ($refPrice > 0) {
                $rowAmount = round((float)$it['quantity'] * $refPrice, 2);
                $ws->setCellValue('G' . $r, $refPrice);
                $ws->setCellValue('H' . $r, $rowAmount);
                $ws->getStyle('G' . $r . ':H' . $r)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
            } else {
                $ws->setCellValue('G' . $r, '');
                $ws->setCellValue('H' . $r, '');
            }
        }
        $ws->setCellValue('I' . $r, $imported ? ($it['remark'] ?? '') : ($it['reason'] ?? ''));
        $ws->setCellValue('J' . $r, $imported ? '' : (float)($it['stock'] ?? 0));
        $hasImg = purchase_image_embed($ws, 'K' . $r, $imageMap[(int)($it['item_id'] ?? 0)] ?? null);
        $totQty += (float)$it['quantity'];
        $totAmount += $rowAmount;
        style_data($ws, "A{$r}:K{$r}");
        $ws->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('B' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle('C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle('F' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('G' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('H' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight($hasImg ? 60 : 20);
        $r++;
    }
    // 合计行
    $ws->setCellValue('E' . $r, '合计');
    $ws->setCellValue('F' . $r, round($totQty, 2));
    if ($totAmount > 0) $ws->setCellValue('H' . $r, round($totAmount, 2));
    $ws->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
    $ws->getStyle("A{$r}:K{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E2F3');
    style_data($ws, "A{$r}:K{$r}");

    foreach (['A' => 10, 'B' => 24, 'C' => 20, 'D' => 14, 'E' => 8, 'F' => 10, 'G' => 12, 'H' => 12, 'I' => 26, 'J' => 10, 'K' => 14] as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ws->getStyle("F2:J{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $ws->freezePane('A3');

    $fileName = "{$pname}_{$month}_采购填报明细.xlsx";
    log_action('员工导出', "{$month} {$pname} 导出填报明细（{$fileName}）");
    \App\Purchase\Support::header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    \App\Purchase\Support::header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    throw new \App\Purchase\PurchaseStop();
}

// 导入存档：读取填价后的Excel → archived_purchases
function handle_import_archive() {
    require_admin();
    $month = $_POST['month'] ?? '';
    if ($month === '' || !isset($_FILES['file'])) {
        return ['ok' => false, 'msg' => '缺少月份或文件'];
    }
    if (month_archived($month)) return ['ok' => false, 'msg' => '本月已归档锁定，不可导入报价'];
    // 报价必须对应最终填报：存在草稿/已提交/已退回时先确认，避免存档与填报不一致
    $stC = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(status='confirmed'),0) cf FROM purchase_items WHERE month=?");
    $stC->execute([$month]);
    $cnt = $stC->fetch();
    $totalItems = (int)($cnt['c'] ?? 0); $confirmedItems = (int)($cnt['cf'] ?? 0);
    $statusBlock = purchase_import_status_guard($totalItems, $confirmedItems);
    if ($statusBlock !== null) {
        if ($confirmedItems < $totalItems) {
            $stU = db()->prepare("SELECT line, item_name, spec, status FROM purchase_items WHERE month=? AND status<>'confirmed' ORDER BY line, id LIMIT 8");
            $stU->execute([$month]);
            $names = array_map(fn($x) => $x['item_name'] . ($x['spec'] ? "({$x['spec']})" : '') . "[{$x['status']}]", $stU->fetchAll());
            $suffix = count($names) > 0 ? '，如：' . implode('、', $names) : '';
            return ['ok' => false, 'msg' => $statusBlock . $suffix];
        }
        return ['ok' => false, 'msg' => $statusBlock];
    }
    $tmp = $_FILES['file']['tmp_name'];
    if (!is_uploaded_file($tmp)) return ['ok' => false, 'msg' => '文件上传失败'];
    $reader = IOFactory::createReaderForFile($tmp);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmp);

    $db = db();
    $projects = [];
    foreach (projects_all() as $p) {
        $projects[$p['name']] = (int)$p['id'];
    }
    // 商品匹配缓存（规格归一化后匹配，P1-4/Q5）
    $productCache = [];
    $unboundList = [];
    $findProduct = function ($name, $spec, $unit) use ($db, &$productCache) {
        $nspec = norm_spec($spec);
        $key = $name . '|' . $nspec . '|' . $unit;
        if (array_key_exists($key, $productCache)) return $productCache[$key];
        $st = $db->prepare("SELECT id FROM products WHERE name=? AND spec=? AND unit=? AND status=1 LIMIT 1");
        $st->execute([$name, $nspec, $unit]);
        $id = $st->fetchColumn();
        if (!$id) {
            // 兜底：仅名称唯一匹配
            $st2 = $db->prepare("SELECT id FROM products WHERE name=? AND status=1 LIMIT 2");
            $st2->execute([$name]);
            $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
            $id = count($rows) === 1 ? $rows[0] : null;
        }
        $productCache[$key] = $id ? (int)$id : null;
        return $productCache[$key];
    };

    $linesMap = ['环境类' => '环境', '绿化类' => '绿化', '工程类' => '工程', '秩序部' => '秩序', '行政办公类' => '行政'];
    $scopeRows = []; $toInsert = []; $skipped = 0; $errors = [];
    foreach ($spreadsheet->getSheetNames() as $sheetName) {
        if (!isset($linesMap[$sheetName])) continue;
        $line = $linesMap[$sheetName];
        $ws = $spreadsheet->getSheetByName($sheetName);
        if (!$ws) continue;
        $highest = $ws->getHighestRow();
        for ($r = 3; $r <= $highest; $r++) {
            $pname = trim((string)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2) . $r)->getValue());
            if ($pname === '') continue;
            $project_id = $projects[$pname] ?? null;
            if (!$project_id) { $errors[] = "第{$r}行: 未知项目「{$pname}」"; $skipped++; continue; }
            $item_name = trim((string)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3) . $r)->getValue());
            if ($item_name === '') { $skipped++; continue; }
            $brand = trim((string)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4) . $r)->getValue());
            $spec = norm_spec((string)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5) . $r)->getValue());
            $unit = trim((string)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(6) . $r)->getValue());
            $qty = (float)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(7) . $r)->getValue();
            $price = (float)$ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(8) . $r)->getValue();
            // 有项目、有商品名即纳入本次覆盖范围（用于清理旧存档），空数量空单价的行不写入
            $scopeRows[] = ['project_id' => $project_id, 'line' => $line];
            if ($qty <= 0 && $price <= 0) { $skipped++; continue; }
            $product_id = $findProduct($item_name, $spec, $unit);
            if (!$product_id) {
                $ukey = $item_name . '|' . $spec . '|' . $unit;
                if (!isset($unboundList[$ukey])) $unboundList[$ukey] = ['name' => $item_name, 'spec' => $spec, 'unit' => $unit, 'rows' => 0];
                $unboundList[$ukey]['rows']++;
            }
            $toInsert[] = ['project_id' => $project_id, 'line' => $line, 'product_id' => $product_id,
                'item_name' => $item_name, 'brand' => $brand, 'spec' => $spec, 'unit' => $unit,
                'quantity' => $qty, 'price' => $price, 'total' => round($qty * $price, 2)];
        }
    }

    // M3：给每条存档关联填报明细 id，删除退回记录时按 id 精确删，不再靠名称猜
    $itemSt = db()->prepare("SELECT id, project_id, line, item_name, brand, spec, unit, quantity FROM purchase_items WHERE month=?");
    $itemSt->execute([$month]);
    $toInsert = purchase_match_item_ids($itemSt->fetchAll(PDO::FETCH_ASSOC), $toInsert);

    $result = purchase_import_archive_rows($month, $toInsert, $scopeRows);    if (empty($result['ok'])) {
        $result['msg'] = ($result['msg'] ?? '导入失败') . ($errors ? '；异常：' . implode('; ', array_slice($errors, 0, 10)) : '');
        return $result;
    }
    $inserted = (int)$result['inserted']; $deleted = (int)$result['deleted'];
    $scopeProjects = array_values(array_unique(array_column($scopeRows, 'project_id')));
    log_action('导入', json_encode(['month' => $month, 'projects' => $scopeProjects,
        'pairs' => $result['pairs'], 'inserted' => $inserted, 'deleted' => $deleted,
        'skipped' => $skipped, 'snapshot' => $result['snapshot'],
        'operator' => auth_user()['username'] ?? '-'], JSON_UNESCAPED_UNICODE));
    // 通知本次涉及项目的员工：报价导入完成
    try {
        foreach ($scopeProjects as $pid) {
            foreach (project_user_ids((int)$pid) as $uid) {
                notify_user((int)$uid, 'price_imported', "{$month}月 报价已导入", "本月采购报价已导入存档，可在「我的填报记录」查看最终单价与金额。", "/app/purchase/my-items?month=$month");
            }
        }
    } catch (Throwable $e) {}
    // 预算超支检查 → 通知管理员
    try {
        $stB = db()->prepare("SELECT project_id, amount FROM budget_plan WHERE month=?");
        $stB->execute([$month]);
        $over = [];
        foreach ($stB->fetchAll() as $b) {
            $actual = month_project_actual($month, (int)$b['project_id']);
            if ($actual > (float)$b['amount']) {
                $stN = db()->prepare("SELECT name FROM payroll.payroll_projects WHERE id=?");
                $stN->execute([(int)$b['project_id']]);
                $pn = (string)$stN->fetchColumn();
                $over[] = "{$pn} 预算 " . round((float)$b['amount'], 2) . " / 实际 " . round($actual, 2);
            }
        }
        if ($over) {
            foreach (admin_user_ids() as $uid) {
                notify_user((int)$uid, 'budget_over', "{$month}月 预算超支提醒", implode('；', $over), '/app/purchase/overview');
            }
            log_action('预算超支', "{$month} " . implode('；', $over));
        }
    } catch (Throwable $e) {}
    $msg = "导入完成：新增 {$inserted} 条，覆盖旧存档 {$deleted} 条，跳过 {$skipped} 条空行";
    if ($errors) $msg .= '；异常：' . implode('; ', array_slice($errors, 0, 10));
    if ($unboundList) $msg .= '；未绑定商品库商品 ' . count($unboundList) . ' 种（' . implode('、', array_slice(array_column($unboundList, 'name'), 0, 8)) . '）';
    return ['ok' => true, 'msg' => $msg, 'unbound' => array_values($unboundList),
        'scope' => ['projects' => $scopeProjects, 'inserted' => $inserted, 'deleted' => $deleted]];
}

/** 本次上传覆盖的 (项目, 条线) 集合（去重） */
function purchase_import_scope_pairs(array $rows): array
{
    $pairs = [];
    foreach ($rows as $r) {
        $pid = (int)($r['project_id'] ?? 0);
        $line = (string)($r['line'] ?? '');
        if ($pid > 0 && $line !== '') $pairs[$pid . '|' . $line] = [$pid, $line];
    }
    ksort($pairs);
    return array_values($pairs);
}

/** 写入前快照（整月存档），返回文件路径；失败返回 null */
function purchase_import_snapshot(string $month, array $rows): ?string
{
    try {
        $dir = function_exists('storage_path')
            ? storage_path('app/purchase_import_snapshots')
            : rtrim(sys_get_temp_dir(), '/\\') . '/purchase_import_snapshots';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $file = rtrim($dir, '/\\') . '/' . $month . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.json';
        $payload = json_encode(['month' => $month, 'created_at' => date('c'), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        return @file_put_contents($file, $payload) === false ? null : $file;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 将解析后的报价行写入存档：
 *  - 只清理/覆盖本次上传涉及的 (项目, 条线)，其他项目其他条线保持不动；
 *  - 写库前生成整月快照，失败整体回滚并删除快照；
 *  - 返回影响范围统计，供日志与页面提示。
 */
function purchase_import_archive_rows(string $month, array $rows, ?array $scopeRows = null): array
{
    $scopeRows = $scopeRows ?? $rows;
    $scopePairs = purchase_import_scope_pairs($scopeRows);
    $scopeProjects = array_values(array_unique(array_map(fn($r) => (int)$r['project_id'], $scopeRows)));
    if (!$scopePairs) return ['ok' => false, 'msg' => '未找到可导入数据，未做任何修改'];
    $db = db();
    $snapshotRows = $db->prepare("SELECT * FROM archived_purchases WHERE month=? ORDER BY id");
    $snapshotRows->execute([$month]);
    $beforeRows = $snapshotRows->fetchAll(PDO::FETCH_ASSOC);
    $snapshot = purchase_import_snapshot($month, $beforeRows);
    if ($snapshot === null) return ['ok' => false, 'msg' => '快照生成失败，已取消导入（未修改任何数据）'];

    $inserted = 0; $deleted = 0;
    $db->beginTransaction();
    try {
        foreach ($scopePairs as [$pid, $line]) {
            $del = $db->prepare("DELETE FROM archived_purchases WHERE month=? AND project_id=? AND line=?");
            $del->execute([$month, $pid, $line]);
            $deleted += $del->rowCount();
        }
        foreach ($rows as $r) {
            $db->prepare("INSERT INTO archived_purchases (month, project_id, item_id, line, product_id, item_name, brand, spec, unit, quantity, price, total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$month, $r['project_id'], $r['item_id'] ?? null, $r['line'], $r['product_id'], $r['item_name'], $r['brand'], $r['spec'], $r['unit'], $r['quantity'], $r['price'], $r['total']]);
            $inserted++;
            // 价格回写填报记录（供退回修改时员工查看单价）；同一商品多行时一并回写
            if ((float)$r['price'] > 0) {
                $db->prepare("UPDATE purchase_items SET price=? WHERE month=? AND project_id=? AND item_name=? AND spec=? AND status IN ('submitted','confirmed','returned') AND (price IS NULL OR price=0)")
                    ->execute([$r['price'], $month, $r['project_id'], $r['item_name'], $r['spec']]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink($snapshot);
        log_action('导入失败', json_encode(['month' => $month, 'projects' => $scopeProjects, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        return ['ok' => false, 'msg' => '导入失败，已整体回滚：' . $e->getMessage()];
    }
    return ['ok' => true, 'inserted' => $inserted, 'deleted' => $deleted, 'projects' => $scopeProjects,
        'pairs' => count($scopePairs), 'snapshot' => $snapshot];
}

// 批量导入历史月份：多个文件（文件名需含月份，如 2026-01 或 2026年1月），逐个导入
function handle_import_batch() {
    require_admin();
    $out = ['ok' => true, 'msg' => '批量导入完成', 'files' => []];
    if (!isset($_FILES['files'])) return ['ok' => false, 'msg' => '未收到文件'];
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        $name = $_FILES['files']['name'][$i];
        if (!preg_match('/(20\d{2})[年\-.\/](\d{1,2})/', $name, $m)) {
            $out['files'][] = ['file' => $name, 'ok' => false, 'msg' => '文件名未识别到月份（需含 2026-01 或 2026年1月）'];
            continue;
        }
        $month = sprintf('%04d-%02d', (int)$m[1], (int)$m[2]);
        $_FILES['file'] = ['tmp_name' => $tmp, 'name' => $name];
        $_POST['month'] = $month;
        try {
            $r = handle_import_archive();
            $out['files'][] = ['file' => $name, 'month' => $month, 'ok' => true, 'msg' => $r['msg'] ?? 'ok'];
        } catch (Throwable $e) {
            $out['files'][] = ['file' => $name, 'month' => $month, 'ok' => false, 'msg' => '导入失败: ' . $e->getMessage()];
        }
    }
    log_action('批量导入历史', json_encode($out['files'], JSON_UNESCAPED_UNICODE));
    return $out;
}

// 导出当年全部采购明细（截至当前已存档数据）
function handle_export_year() {
    require_admin();
    $year = (int)($_GET['year'] ?? date('Y'));
    $st = db()->prepare(
        "SELECT ap.month, ap.item_id, p.name AS project_name, ap.line, ap.item_name, ap.brand, ap.spec, ap.unit,
                ap.quantity, ap.price, ap.total
         FROM archived_purchases ap
         JOIN payroll.payroll_projects p ON ap.project_id=p.id
         WHERE ap.month>=? AND ap.month<=?
         ORDER BY ap.month, FIELD(ap.line,'环境','绿化','工程','秩序','行政'), p.id, ap.id"
    );
    $st->execute([$year . '-01', $year . '-12']);
    $rows = $st->fetchAll();
    $imageMap = purchase_image_map(array_column($rows, 'item_id'));

    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('全年采购明细');
    $ws->mergeCells('A1:K1');
    $ws->setCellValue('A1', "广盈物业 {$year} 年度采购明细汇总（截至 " . date('Y-m-d') . '）');
    style_title($ws, 'A1:K1', 1, "广盈物业 {$year} 年度采购明细汇总（截至 " . date('Y-m-d') . '）', 14);

    $headers = ['月份', '项目名称', '条线', '商品名称', '品牌', '规格型号', '单位', '数量', '单价', '金额', '示例图片'];
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '2', $h);
    }
    style_header($ws, 'A2:K2', 2, 30);

    $r = 3;
    $totQty = 0; $totAmount = 0;
    foreach ($rows as $it) {
        $ws->setCellValue('A' . $r, $it['month']);
        $ws->setCellValue('B' . $r, $it['project_name']);
        $ws->setCellValue('C' . $r, $it['line']);
        $ws->setCellValue('D' . $r, $it['item_name']);
        $ws->setCellValue('E' . $r, $it['brand']);
        $ws->setCellValue('F' . $r, $it['spec']);
        $ws->setCellValue('G' . $r, $it['unit']);
        $ws->setCellValue('H' . $r, (float)$it['quantity']);
        $ws->setCellValue('I' . $r, (float)$it['price']);
        $ws->setCellValue('J' . $r, (float)$it['total']);
        $hasImg = purchase_image_embed($ws, 'K' . $r, $imageMap[(int)($it['item_id'] ?? 0)] ?? null);
        $totQty += (float)$it['quantity'];
        $totAmount += (float)$it['total'];
        style_data($ws, "A{$r}:K{$r}");
        $ws->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('B' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle('C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle('H' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('I' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('J' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight($hasImg ? 60 : 20);
        $r++;
    }
    // 合计行
    $ws->setCellValue('G' . $r, '合计');
    $ws->setCellValue('H' . $r, round($totQty, 2));
    $ws->setCellValue('J' . $r, round($totAmount, 2));
    $ws->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
    $ws->getStyle("A{$r}:K{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E2F3');
    style_data($ws, "A{$r}:K{$r}");

    foreach (['A' => 10, 'B' => 18, 'C' => 8, 'D' => 22, 'E' => 14, 'F' => 20, 'G' => 8, 'H' => 10, 'I' => 12, 'J' => 12, 'K' => 14] as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ws->getStyle("H2:J{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $ws->freezePane('A3');
    // 自动筛选
    $ws->setAutoFilter('A2:K' . max($r - 1, 2));

    $fileName = "广盈物业_{$year}年度采购明细汇总.xlsx";
    log_action('年度导出', "{$year} 年度采购明细汇总导出（{$fileName}）");
    \App\Purchase\Support::header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    \App\Purchase\Support::header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
        throw new \App\Purchase\PurchaseStop();
}
