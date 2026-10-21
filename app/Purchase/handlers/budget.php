<?php
/**
 * 项目月度预算
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// 预算列表（含各项目实际金额与超支标记、执行率）
function handle_budgets_list() {
    require_admin();
    $month = $_GET['month'] ?? date('Y-m');
    $projects = projects_all();
    $stB = db()->prepare("SELECT project_id, amount FROM budget_plan WHERE month=?");
    $stB->execute([$month]);
    $budgets = [];
    foreach ($stB->fetchAll() as $r) $budgets[(int)$r['project_id']] = (float)$r['amount'];
    $stA = db()->prepare("SELECT project_id, SUM(total) AS amount, COUNT(*) AS cnt FROM archived_purchases WHERE month=? GROUP BY project_id");
    $stA->execute([$month]);
    $actuals = [];
    foreach ($stA->fetchAll() as $r) $actuals[(int)$r['project_id']] = ['amount' => (float)$r['amount'], 'cnt' => (int)$r['cnt']];
    $rows = [];
    $totalBudget = 0; $totalActual = 0;
    foreach ($projects as $p) {
        $b = $budgets[$p['id']] ?? 0;
        $a = $actuals[$p['id']]['amount'] ?? 0;
        $totalBudget += $b;
        $totalActual += $a;
        $rows[] = [
            'project_id' => (int)$p['id'],
            'project_name' => $p['name'],
            'budget' => $b,
            'actual' => round($a, 2),
            'diff' => round($b - $a, 2),
            'over' => $b > 0 && $a > $b,
            'rate' => $b > 0 ? round($a / $b * 100, 1) : null,
            'items' => $actuals[$p['id']]['cnt'] ?? 0,
        ];
    }
    return ['ok' => true, 'data' => [
        'month' => $month,
        'rows' => $rows,
        'total_budget' => round($totalBudget, 2),
        'total_actual' => round($totalActual, 2),
        'total_rate' => $totalBudget > 0 ? round($totalActual / $totalBudget * 100, 1) : null,
    ]];
}

// 保存预算（批量 upsert）
function handle_budgets_save() {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $month = $in['month'] ?? '';
    $rows = $in['rows'] ?? [];
    if ($month === '' || !is_array($rows)) return ['ok' => false, 'msg' => '参数缺失'];
    $count = 0;
    foreach ($rows as $r) {
        $pid = (int)($r['project_id'] ?? 0);
        $amount = (float)($r['amount'] ?? 0);
        if ($pid <= 0) continue;
        db()->prepare("INSERT INTO budget_plan (month, project_id, amount, updated_by) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE amount=VALUES(amount), updated_by=VALUES(updated_by)")
            ->execute([$month, $pid, $amount, $in['user_id'] ?? null]);
        $count++;
    }
    log_action('预算设置', "{$month} 共{$count}个项目");
    return ['ok' => true, 'msg' => "已保存 {$count} 个项目预算"];
}

// 预算对比（导出导入页使用，按月返回各项目预算与实际）
function handle_budget_compare() {
    require_admin();
    $month = $_GET['month'] ?? date('Y-m');
    $res = handle_budgets_list();
    return $res;
}

// 某年已设置预算的月份列表（供预算管理页 12 月速览条打✓）
function handle_budgets_months() {
    require_admin();
    $year = (int)($_GET['year'] ?? date('Y'));
    $st = db()->prepare("SELECT DISTINCT month FROM budget_plan WHERE month>=? AND month<=? AND amount>0 ORDER BY month");
    $st->execute([$year . '-01', $year . '-12']);
    return ['ok' => true, 'data' => ['year' => $year, 'months' => $st->fetchAll(PDO::FETCH_COLUMN)]];
}

// ========== 全年预算导入模板 ==========
// 生成 Excel：项目 × 1-12月 矩阵，含合计行；并回填已设置的预算
function handle_budgets_template() {
    require_admin();
    $year = (int)($_GET['year'] ?? date('Y'));
    $projects = projects_all();
    $stB = db()->prepare("SELECT month, project_id, amount FROM budget_plan WHERE month>=? AND month<=?");
    $stB->execute([$year . '-01', $year . '-12']);
    $exists = [];
    foreach ($stB->fetchAll() as $r) $exists[$r['project_id']][(int)substr($r['month'], 5, 2)] = (float)$r['amount'];

    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('全年预算导入模板');

    // 标题（仿用户原表样式：深蓝底白字）
    $ws->mergeCells('A1:O1');
    $ws->setCellValue('A1', "广盈物业 {$year} 年度项目采购预算表");
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF366092');
    $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(32);

    // 说明行
    $ws->mergeCells('A2:O2');
    $ws->setCellValue('A2', "说明：填写每个项目每个月的采购预算金额（该项目环境/绿化/工程/秩序/行政全部条线合计），未设置的项目金额留空即可；填好后保存文件，在「预算管理 → 导入全年预算」上传。");
    $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $ws->getStyle('A2')->getFont()->setSize(10);
    $ws->getRowDimension(2)->setRowHeight(30);

    // 表头（第4行，蓝底白字加粗居中，仿原表）
    $headers = ['序号', '项目名称'];
    for ($m = 1; $m <= 12; $m++) $headers[] = $m . '月(元)';
    $headers[] = '全年合计(元)';
    foreach ($headers as $i => $h) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '4', $h);
    }
    $ws->getStyle('A4:O4')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle('A4:O4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $ws->getStyle('A4:O4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(4)->setRowHeight(30);

    // 数据行（第5行起）
    $row = 5;
    $no = 1;
    foreach ($projects as $p) {
        $ws->setCellValue('A' . $row, $no);
        $ws->setCellValue('B' . $row, $p['name']);
        $total = 0;
        for ($m = 1; $m <= 12; $m++) {
            $v = $exists[$p['id']][$m] ?? '';
            if ($v !== '') {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($m + 2) . $row, $v);
                $total += (float)$v;
            }
        }
        $ws->setCellValue('O' . $row, $total > 0 ? $total : '');
        $ws->getStyle("A{$row}:O{$row}")->getFont()->setSize(10);
        $ws->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $row++;
        $no++;
    }
    // 合计行
    $ws->setCellValue('A' . $row, '');
    $ws->setCellValue('B' . $row, '合计');
    for ($m = 1; $m <= 12; $m++) {
        $col = Coordinate::stringFromColumnIndex($m + 2);
        $ws->setCellValue($col . $row, "=SUM({$col}5:{$col}" . ($row - 1) . ")");
    }
    $ws->setCellValue('O' . $row, "=SUM(O5:O" . ($row - 1) . ")");
    $ws->getStyle("A{$row}:O{$row}")->getFont()->setBold(true);
    $ws->getStyle("A{$row}:O{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E2F3');
    $ws->getStyle("A{$row}:O{$row}")->getFont()->getColor()->setARGB('FF1F3864');

    // 边框 + 列宽
    $ws->getStyle("A4:O{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $ws->getColumnDimension('A')->setWidth(6);
    $ws->getColumnDimension('B')->setWidth(20);
    foreach (range('C', 'O') as $col) $ws->getColumnDimension($col)->setWidth(12);
    $ws->getStyle('C5:O' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $ws->freezePane('C5');

    $fileName = "广盈物业_{$year}年度采购预算导入模板.xlsx";
    \App\Purchase\Support::header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    \App\Purchase\Support::header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
        throw new \App\Purchase\PurchaseStop();
}

// ========== 全年预算导入 ==========
// 解析模板 Excel：第4行表头（1月..12月），第5行起 项目×金额
function handle_budgets_import() {
    require_admin();
    $year = (int)($_POST['year'] ?? 0);
    if ($year <= 0 || !isset($_FILES['file'])) {
        return ['ok' => false, 'msg' => '缺少年份或文件'];
    }
    $origName = $_FILES['file']['name'] ?? '';
    if (!preg_match('/\.(xlsx|xls)$/i', $origName)) {
        return ['ok' => false, 'msg' => '文件格式不正确，请上传系统下载的 Excel 模板（.xlsx/.xls）'];
    }
    $tmp = $_FILES['file']['tmp_name'];
    if (!is_uploaded_file($tmp)) return ['ok' => false, 'msg' => '文件上传失败'];
    try {
        $reader = IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '无法读取文件，请使用系统下载的模板: ' . $e->getMessage()];
    }
    $ws = $spreadsheet->getActiveSheet();
    // 校验模板结构（第4行表头含 项目名称/1月）
    $b4 = trim((string)$ws->getCell('B4')->getValue());
    $c4 = trim((string)$ws->getCell('C4')->getValue());
    if (strpos($b4, '项目名称') === false || strpos($c4, '月') === false) {
        return ['ok' => false, 'msg' => '文件内容不是预算导入模板（缺少项目名称/月份表头），请下载模板后填写上传'];
    }
    $db = db();
    $projects = [];
    foreach (projects_all() as $p) {
        $projects[trim($p['name'])] = (int)$p['id'];
    }
    $highest = $ws->getHighestRow();
    $updated = 0; $errors = [];
    $db->beginTransaction();
    try {
        for ($r = 5; $r <= $highest; $r++) {
            $pname = trim((string)$ws->getCell('B' . $r)->getValue());
            if ($pname === '' || $pname === '合计') continue;
            $pid = $projects[$pname] ?? null;
            if (!$pid) { $errors[] = "第{$r}行: 未知项目「{$pname}」"; continue; }
            $hasVal = false;
            for ($m = 1; $m <= 12; $m++) {
                $cellVal = $ws->getCell(Coordinate::stringFromColumnIndex($m + 2) . $r)->getValue();
                if ($cellVal === null || $cellVal === '') continue;
                $amount = (float)$cellVal;
                $month = sprintf('%04d-%02d', $year, $m);
                db()->prepare("INSERT INTO budget_plan (month, project_id, amount, updated_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE amount=VALUES(amount), updated_by=VALUES(updated_by)")
                    ->execute([$month, $pid, $amount, (int)($_POST['user_id'] ?? 0)]);
                $hasVal = true;
                $updated++;
            }
            if (!$hasVal) {
                // 整行空白：清除该年该项目的预算
                db()->prepare("DELETE FROM budget_plan WHERE project_id=? AND month>=? AND month<=?")
                    ->execute([$pid, $year . '-01', $year . '-12']);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'msg' => '导入失败: ' . $e->getMessage()];
    }
    log_action('全年预算导入', "{$year}年 共{$updated}个项目月");
    return ['ok' => true, 'msg' => "导入完成：写入 {$updated} 条项目×月预算" . ($errors ? '；异常：' . implode('; ', array_slice($errors, 0, 10)) : '')];
}
