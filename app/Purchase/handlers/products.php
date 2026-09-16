<?php
/** 商品库接口 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

// 条线列表
function handle_products_lines() {
    $st = db()->query("SELECT DISTINCT line FROM products WHERE status=1 ORDER BY FIELD(line,'环境','绿化','工程','行政')");
    $lines = $st->fetchAll(PDO::FETCH_COLUMN);
    return ['ok' => true, 'data' => $lines];
}

// 分类列表
function handle_products_categories() {
    $line = $_GET['line'] ?? '';
    if ($line === '') return ['ok' => false, 'msg' => '缺少line参数'];
    $st = db()->prepare("SELECT DISTINCT category FROM products WHERE line=? AND status=1 ORDER BY category");
    $st->execute([$line]);
    return ['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_COLUMN)];
}

// 商品列表（含全部规格条目，支持搜索）
function handle_products_list() {
    $line = $_GET['line'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');
    $sql = "SELECT p.id, p.line, p.category, p.name, p.brand, p.spec, p.unit,
            COALESCE(GROUP_CONCAT(s.alias ORDER BY s.id SEPARATOR '|'), '') AS aliases
            FROM products p
            LEFT JOIN product_synonyms s ON s.product_id = p.id
            WHERE p.status=1";
    $params = [];
    if ($line !== '') { $sql .= " AND p.line=?"; $params[] = $line; }
    if ($category !== '') { $sql .= " AND p.category=?"; $params[] = $category; }
    if ($keyword !== '') {
        $sql .= " AND (p.name LIKE ? OR p.spec LIKE ? OR p.brand LIKE ?)";
        $kw = "%{$keyword}%";
        $params[] = $kw; $params[] = $kw; $params[] = $kw;
    }
    // 总数（与主查询同条件，参数顺序一致）
    $countSql = "SELECT COUNT(DISTINCT p.id) FROM products p WHERE p.status=1";
    if ($line !== '') { $countSql .= " AND p.line=?"; }
    if ($category !== '') { $countSql .= " AND p.category=?"; }
    if ($keyword !== '') { $countSql .= " AND (p.name LIKE ? OR p.spec LIKE ? OR p.brand LIKE ?)"; }
    $countSt = db()->prepare($countSql);
    $countSt->execute($params);
    $total = (int) $countSt->fetchColumn();
    // 分页
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(200, max(10, (int) ($_GET['page_size'] ?? 100)));
    $offset = ($page - 1) * $pageSize;
    $sql .= " GROUP BY p.id ORDER BY p.id LIMIT {$pageSize} OFFSET {$offset}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return ['ok' => true, 'total' => $total, 'data' => $st->fetchAll()];
}

// 商品名称+规格分组（填报联动：选名称后返回该名称下所有规格）
function handle_products_by_name() {
    $line = $_GET['line'] ?? '';
    $category = $_GET['category'] ?? '';
    $name = $_GET['name'] ?? '';
    if ($name === '') return ['ok' => false, 'msg' => '缺少name参数'];
    $sql = "SELECT id, line, category, name, brand, spec, unit FROM products WHERE status=1 AND name=?";
    $params = [$name];
    if ($line !== '') { $sql .= " AND line=?"; $params[] = $line; }
    if ($category !== '') { $sql .= " AND category=?"; $params[] = $category; }
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $brand = $rows[0]['brand'] ?? '';
    $unit = $rows[0]['unit'] ?? '';
    $specs = [];
    foreach ($rows as $r) {
        $specs[] = ['id' => (int)$r['id'], 'spec' => $r['spec'], 'brand' => $r['brand'], 'unit' => $r['unit']];
        if ($r['brand'] !== '') $brand = $r['brand'];
        if ($r['unit'] !== '') $unit = $r['unit'];
    }
    return ['ok' => true, 'data' => [
        'name' => $name, 'category' => $rows[0]['category'] ?? '',
        'line' => $rows[0]['line'] ?? $line,
        'brand' => $brand, 'unit' => $unit,
        'specs' => $specs,
    ]];
}

// 商品搜索（模糊，用于清单外匹配推荐）
function handle_products_search() {
    $kw = trim($_GET['keyword'] ?? '');
    if ($kw === '') return ['ok' => true, 'data' => []];
    // 名称命中优先于规格/品牌命中，避免按 id 排序把名称匹配项挤出 LIMIT
    $sql = "SELECT id, line, category, name, brand, spec, unit FROM products WHERE status=1 AND (name LIKE ? OR spec LIKE ? OR brand LIKE ?) ORDER BY (name LIKE ?) DESC, id LIMIT 50";
    $st = db()->prepare($sql);
    $like = "%{$kw}%";
    $st->execute([$like, $like, $like, $like]);
    return ['ok' => true, 'data' => $st->fetchAll()];
}

// 新增商品（管理员）
function handle_products_create() {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $line = trim($in['line'] ?? '');
    $category = trim($in['category'] ?? '');
    $name = trim($in['name'] ?? '');
    $brand = trim($in['brand'] ?? '');
    $spec = trim($in['spec'] ?? '');
    $unit = trim($in['unit'] ?? '');
    if ($line === '' || $name === '') return ['ok' => false, 'msg' => '条线和商品名称必填'];
    if ($spec === '') return ['ok' => false, 'msg' => '规格型号必填'];
    if ($unit === '') return ['ok' => false, 'msg' => '单位必填'];
    $st = db()->prepare("INSERT INTO products (line, category, name, brand, spec, unit) VALUES (?,?,?,?,?,?)");
    $st->execute([$line, $category, $name, $brand, norm_spec($spec), $unit]);
    log_action('新增', "商品库新增：{$name}({$spec}) {$unit} / {$line}-{$category}");
    return ['ok' => true, 'msg' => '已新增', 'data' => ['id' => (int)db()->lastInsertId()]];
}

// 编辑商品（管理员）
function handle_products_update($id) {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $fields = ['line', 'category', 'name', 'brand', 'spec', 'unit', 'status'];
    $sets = []; $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "{$f}=?";
            $v = $in[$f];
            if ($f === 'spec') { $v = norm_spec((string)$v); }
            elseif (is_int($v)) { /* 原样 */ }
            else { $v = trim((string)$v); }
            $params[] = $v;
        }
    }
    if (!$sets) return ['ok' => false, 'msg' => '无更新字段'];
    if (isset($in['spec']) && trim((string)$in['spec']) === '') return ['ok' => false, 'msg' => '规格型号必填'];
    if (isset($in['unit']) && trim((string)$in['unit']) === '') return ['ok' => false, 'msg' => '单位必填'];
    $params[] = $id;
    db()->prepare("UPDATE products SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    log_action('更新', "商品库更新：id={$id} " . json_encode(array_intersect_key($in, array_flip(['line', 'category', 'name', 'brand', 'spec', 'unit'])), JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'msg' => '已更新'];
}

// 删除商品（管理员）
function handle_products_delete($id) {
    require_admin();
    $st = db()->prepare("SELECT name, spec, unit FROM products WHERE id=?");
    $st->execute([$id]);
    $p = $st->fetch();
    db()->prepare("UPDATE products SET status=0 WHERE id=?")->execute([$id]);
    db()->prepare("DELETE FROM product_synonyms WHERE product_id=?")->execute([$id]);
    log_action('删除', "商品库删除：id={$id} " . ($p ? "{$p['name']}({$p['spec']}) {$p['unit']}" : ''));
    return ['ok' => true, 'msg' => '已删除'];
}

// 商品库未绑定统计：存档中 product_id 为空的行（Q7：商品库页统计）
function handle_products_unbound() {
    require_admin();
    $sql = "SELECT ap.item_name, ap.spec, ap.unit, COUNT(*) AS rows_cnt, COALESCE(SUM(ap.total),0) AS amount, MAX(ap.month) AS last_month
            FROM archived_purchases ap
            LEFT JOIN products p ON ap.product_id = p.id
            WHERE ap.product_id IS NULL
            GROUP BY ap.item_name, ap.spec, ap.unit
            ORDER BY rows_cnt DESC, last_month DESC LIMIT 100";
    $st = db()->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll();
    // 逐一尝试按（名称+规格+单位）匹配商品库，判断"可自动绑定"与"商品库缺商品"
    $out = [];
    foreach ($rows as $r) {
        $st2 = db()->prepare("SELECT id FROM products WHERE status=1 AND name=? AND spec=? AND unit=? LIMIT 2");
        $st2->execute([$r['item_name'], norm_spec($r['spec']), $r['unit']]);
        $hits = $st2->fetchAll(PDO::FETCH_COLUMN);
        $bindable = count($hits) === 1;
        $st3 = db()->prepare("SELECT id FROM products WHERE status=1 AND name=? LIMIT 2");
        $st3->execute([$r['item_name']]);
        $nameHits = $st3->fetchAll(PDO::FETCH_COLUMN);
        $out[] = [
            'item_name' => $r['item_name'], 'spec' => $r['spec'], 'unit' => $r['unit'],
            'rows' => (int)$r['rows_cnt'], 'amount' => round((float)$r['amount'], 2),
            'last_month' => $r['last_month'],
            'bindable' => $bindable,
            'name_match' => count($nameHits) === 1,
            'missing' => count($nameHits) === 0,
        ];
    }
    $totalRows = 0; $totalAmount = 0;
    foreach ($out as $o) { $totalRows += $o['rows']; $totalAmount += $o['amount']; }
    return ['ok' => true, 'total_rows' => $totalRows, 'total_amount' => round($totalAmount, 2), 'data' => $out];
}

// 商品别名列表
function handle_synonyms_list($product_id) {
    $st = db()->prepare("SELECT id, alias FROM product_synonyms WHERE product_id=?");
    $st->execute([$product_id]);
    return ['ok' => true, 'data' => $st->fetchAll()];
}

// 新增别名
function handle_synonyms_create() {
    require_admin();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid = (int)($in['product_id'] ?? 0);
    $alias = trim($in['alias'] ?? '');
    if ($pid <= 0 || $alias === '') return ['ok' => false, 'msg' => '参数缺失'];
    try {
        db()->prepare("INSERT INTO product_synonyms (product_id, alias) VALUES (?,?)")->execute([$pid, $alias]);
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => '别名已存在'];
    }
    return ['ok' => true, 'msg' => '已添加'];
}

// 删除别名
function handle_synonyms_delete($id) {
    require_admin();
    db()->prepare("DELETE FROM product_synonyms WHERE id=?")->execute([$id]);
    return ['ok' => true, 'msg' => '已删除'];
}

// ========== 标准商品库批量导入 ==========

// 下载批量导入模板
function handle_products_import_template() {
    require_admin();
    $lines = ['环境', '绿化', '工程', '秩序', '行政'];
    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('商品导入模板');

    // 标题
    $ws->mergeCells('A1:F1');
    $ws->setCellValue('A1', '广盈物业标准商品库批量导入模板');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF366092');
    $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(30);

    // 说明
    $ws->mergeCells('A2:F2');
    $ws->setCellValue('A2', "必填：条线 / 一级分类 / 商品名称 / 规格型号 / 单位；品牌可空。相同（条线+分类+名称+品牌+规格）记录自动覆盖更新。从第 5 行开始填写，每行一条商品（同一商品多个规格分多行填写）。");
    $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle('A2')->getFont()->setSize(10);
    $ws->getRowDimension(2)->setRowHeight(34);

    // 表头（第4行）
    $headers = ['条线', '一级分类', '商品名称', '品牌', '规格型号', '单位'];
    foreach ($headers as $i => $h) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $ws->setCellValue($col . '4', $h);
    }
    $ws->getStyle('A4:F4')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle('A4:F4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $ws->getStyle('A4:F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(4)->setRowHeight(26);

    // 示例行（第5行，灰色标注"示例"）
    $ws->setCellValue('A5', '环境');
    $ws->setCellValue('B5', '保洁工具');
    $ws->setCellValue('C5', '示例商品（导入前请删除本行）');
    $ws->setCellValue('D5', '示例品牌');
    $ws->setCellValue('E5', '规格型号示例');
    $ws->setCellValue('F5', '个');
    $ws->getStyle('A5:F5')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA5B1');
    $ws->getStyle('A5:F5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F3F5');

    // 条线下拉（A5:A1000）
    $dv = new DataValidation();
    $dv->setType(DataValidation::TYPE_LIST)
       ->setFormula1('"' . implode(',', $lines) . '"')
       ->setAllowBlank(true)
       ->setShowDropDown(false) // 注意：PhpSpreadsheet 中 showDropDown=false 表示显示下拉箭头
       ->setShowErrorMessage(true)
       ->setErrorTitle('条线不合法')
       ->setError('请输入：环境 / 绿化 / 工程 / 秩序 / 行政');
    $ws->getDataValidation('A5:A1000')->setType($dv->getType())
        ->setFormula1($dv->getFormula1())
        ->setAllowBlank(true)
        ->setShowErrorMessage(true)
        ->setErrorTitle('条线不合法')
        ->setError('请输入：环境 / 绿化 / 工程 / 秩序 / 行政');

    // 边框 + 列宽
    $ws->getStyle('A4:F5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $ws->getStyle('A4:F1000')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (['A' => 12, 'B' => 18, 'C' => 34, 'D' => 18, 'E' => 30, 'F' => 10] as $col => $w) {
        $ws->getColumnDimension($col)->setWidth($w);
    }
    $ws->freezePane('A5');

    $fileName = '广盈物业_标准商品库批量导入模板.xlsx';
    $asciiName = 'standard_products_import_template.xlsx';
    \App\Purchase\Support::header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    \App\Purchase\Support::header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    throw new \App\Purchase\PurchaseStop();
}

// 批量导入（解析上传的模板 Excel）
function handle_products_import() {
    require_admin();
    if (!isset($_FILES['file'])) return ['ok' => false, 'msg' => '请选择要上传的模板文件'];
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
    // 校验表头
    $a4 = trim((string)$ws->getCell('A4')->getValue());
    $c4 = trim((string)$ws->getCell('C4')->getValue());
    if ($a4 !== '条线' || strpos($c4, '商品名称') === false) {
        return ['ok' => false, 'msg' => '文件内容不是商品库导入模板（缺少条线/商品名称表头），请下载模板后填写上传'];
    }
    $allowedLines = ['环境', '绿化', '工程', '秩序', '行政'];
    $db = db();
    $stmt = $db->prepare("INSERT INTO products (line, category, name, brand, spec, unit) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE brand=VALUES(brand), unit=VALUES(unit)");
    $highest = $ws->getHighestRow();
    $inserted = 0; $updated = 0; $errors = [];
    $db->beginTransaction();
    try {
        for ($r = 5; $r <= $highest; $r++) {
            $line = trim((string)$ws->getCell('A' . $r)->getValue());
            $category = trim((string)$ws->getCell('B' . $r)->getValue());
            $name = trim((string)$ws->getCell('C' . $r)->getValue());
            $brand = trim((string)$ws->getCell('D' . $r)->getValue());
            $spec = norm_spec((string)$ws->getCell('E' . $r)->getValue());
            $unit = trim((string)$ws->getCell('F' . $r)->getValue());
            if ($line === '' && $category === '' && $name === '' && $spec === '' && $unit === '') continue; // 空行跳过
            if ($line === '') { $errors[] = "第{$r}行: 条线必填"; continue; }
            if (!in_array($line, $allowedLines, true)) { $errors[] = "第{$r}行: 条线「{$line}」不在允许范围（环境/绿化/工程/秩序/行政）"; continue; }
            if ($category === '') { $errors[] = "第{$r}行: 一级分类必填"; continue; }
            if ($name === '') { $errors[] = "第{$r}行: 商品名称必填"; continue; }
            if ($spec === '') { $errors[] = "第{$r}行: 规格型号必填"; continue; }
            if ($unit === '') { $errors[] = "第{$r}行: 单位必填"; continue; }
            // 判断新增还是更新
            $ex = $db->prepare("SELECT id FROM products WHERE line=? AND category=? AND name=? AND brand=? AND spec=?");
            $ex->execute([$line, $category, $name, $brand, $spec]);
            $isNew = !$ex->fetchColumn();
            $stmt->execute([$line, $category, $name, $brand, $spec, $unit]);
            if ($isNew) $inserted++; else $updated++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'msg' => '导入失败: ' . $e->getMessage()];
    }
    log_action('商品库批量导入', "新增{$inserted}条 更新{$updated}条" . ($errors ? ' 异常' . count($errors) . '行' : ''));
    $msg = "导入完成：新增 {$inserted} 条，更新 {$updated} 条";
    if ($errors) $msg .= '；有 ' . count($errors) . ' 行未导入：' . implode('；', array_slice($errors, 0, 12));
    return ['ok' => true, 'msg' => $msg, 'inserted' => $inserted, 'updated' => $updated, 'errors' => array_slice($errors, 0, 50)];
}
