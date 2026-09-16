<?php
/** 财务管理 - 历史数据导入 handlers（仅总部财务） */

use App\Finance\FinanceStop;
use App\Finance\Support;
use App\Finance\XlsxParser;

/** 线下 16 个应收项目 sheet 名（标准名，不含付款别名） */
const FIN_IMPORT_SHEETS = [
    '临沂蓝钻', '临沂花开', '日照蓝钻', '菏泽花开', '菏泽瑞府', '成武院子', '莒南花开',
    '莒南瑞府', '祥云大院', '罗庄花开', '沂州花开', '新沂花开', '济宁锦府', '淄博花开',
    '日照花开', '新沂锦府',
];

/** 线下 sheet 名 → 采购库项目名（16 个真实项目） */
const FIN_IMPORT_MAP = [
    '临沂蓝钻' => '临沂蓝钻庄园',
    '临沂花开' => '临沂万城花开',
    '日照蓝钻' => '五莲蓝钻庄园',
    '菏泽花开' => '菏泽万城花开',
    '菏泽瑞府' => '菏泽万城瑞府',
    '成武院子' => '成武中国院子',
    '莒南花开' => '莒南万城花开',
    '莒南瑞府' => '莒南万城瑞府',
    '祥云大院' => '罗庄祥云大院',
    '罗庄大院' => '罗庄祥云大院',
    '罗庄花开' => '罗庄春暖花开',
    '罗庄春暖' => '罗庄春暖花开',
    '沂州花开' => '罗庄沂州天珺',
    '新沂花开' => '新沂春暖花开',
    '济宁锦府' => '济宁祥云锦府',
    '淄博花开' => '淄博万城花开',
    '日照花开' => '日照万城花开',
    '新沂锦府' => '新沂祥云锦府',
];

/** 应收 sheet 列映射：列字母 => 类别key / 状态字段 */
const FIN_IMPORT_RECV_COLS = [
    'B' => 'promise_discount', 'C' => 'promise_defer', 'D' => 'unsold',
    'E' => 'handover_incomplete', 'F' => 'payment_incomplete', 'G' => 'policy_discount',
    'H' => 'repair_compensation', 'I' => 'first_delivery_defer',
    'M' => 'setup_fee', 'N' => 'deposit_deduction', 'O' => 'other',
];

/** 付款记录 sheet 列映射 */
const FIN_IMPORT_PAY_COLS = [
    'C' => 'setup_fee', 'D' => 'cleaning_fee', 'E' => 'acceptance_fee',
    'F' => 'property_settlement', 'G' => 'greening_fee', 'H' => 'cleaning_outsource',
    'I' => 'water_electric', 'J' => 'house_repair',
];

/** 解析月份文本 "2026.1月" / "2020年以前总计" / Excel日期序列号 */
function fin_import_parse_month(mixed $a): ?string
{
    if ($a === null || $a === '') return null;
    if (is_numeric($a) && !is_string($a)) {
        // Excel 1900 日期序列号
        $n = (float) $a;
        if ($n > 40000 && $n < 50000) {
            $ts = strtotime('1899-12-30') + (int) $n * 86400;
            return date('Y-m', $ts);
        }
        return null;
    }
    $s = trim((string) $a);
    if (preg_match('#^(\d{4})[.\-/年](\d{1,2})月?$#', $s, $m)) {
        $mm = (int) $m[2];
        if ($mm >= 1 && $mm <= 12) return sprintf('%04d-%02d', (int) $m[1], $mm);
    }
    return null;
}

/** 解析状态文本 "是/否" 或 0/1 */
function fin_import_parse_bool(mixed $v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_numeric($v)) return ((float) $v) ? 1 : 0;
    $s = trim((string) $v);
    if ($s === '是' || $s === 'yes' || $s === 'Y') return 1;
    if ($s === '否' || $s === 'no' || $s === 'N') return 0;
    return null;
}

/** 解析上传的 xlsx 并返回预览统计（不写库） */
function handle_fin_import_parse(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可导入'], 403);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new FinanceStop(['ok' => false, 'msg' => '请上传 xlsx 文件'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    $orig = (string) $_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') throw new FinanceStop(['ok' => false, 'msg' => '仅支持 .xlsx 文件'], 400);

    $dir = storage_path('app/finance_import');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $save = $dir . '/import_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
    if (!move_uploaded_file($tmp, $save)) {
        throw new FinanceStop(['ok' => false, 'msg' => '文件保存失败'], 500);
    }

    try {
        $parser = new XlsxParser($save);
        $names = $parser->sheetNames();

        $projectRows = [];
        $recv = [];   // 应收预览
        $pay = [];    // 付款预览

        // ---- 应收：16 个项目 sheet ----
        foreach (FIN_IMPORT_SHEETS as $sheetName) {
            $gyName = FIN_IMPORT_MAP[$sheetName];
            if (!in_array($sheetName, $names, true)) {
                $projectRows[] = ['sheet' => $sheetName, 'project' => $gyName, 'mapped' => false, 'reason' => 'xlsx 中无此 sheet'];
                continue;
            }
            $pid = fin_project_id_by_name($gyName);
            if ($pid === null) {
                $projectRows[] = ['sheet' => $sheetName, 'project' => $gyName, 'mapped' => false, 'reason' => '系统项目库中不存在: ' . $gyName];
                continue;
            }
            $rows = $parser->readSheet($sheetName);
            $months = 0;
            $total = 0.0;
            $catCount = 0;
            foreach ($rows as $rnum => $cells) {
                if ($rnum <= 3) continue; // 跳过标题/表头/总计
                $a = $cells['A'] ?? null;
                $month = fin_import_parse_month($a);
                if ($month === null) continue;
                // 跳过合计行（如 2026年合计）→ 已由 parse_month 排除（无"年合计"匹配）
                $rowAmt = 0.0;
                foreach (FIN_IMPORT_RECV_COLS as $col => $key) {
                    $v = $cells[$col] ?? null;
                    if (is_numeric($v)) {
                        $rowAmt += (float) $v;
                        $catCount++;
                    }
                }
                // "2020年以前总计" A 列不匹配月份格式 → 特殊处理
                $months++;
                $total += $rowAmt;
            }
            $recv[] = ['sheet' => $sheetName, 'project_id' => $pid, 'project' => $gyName, 'months' => $months, 'total_yuan' => $total, 'cells' => $catCount];
            $projectRows[] = ['sheet' => $sheetName, 'project' => $gyName, 'mapped' => true, 'project_id' => $pid];
        }

        // ---- 付款记录：2026年关联费用汇总表（三状态区块） ----
        if (in_array('付款记录', $names, true)) {
            $rows = $parser->readSheet('付款记录');
            $curStatus = 'confirmed';
            $statusSeq = ['confirmed', 'paid', 'unpaid'];
            foreach ($rows as $rnum => $cells) {
                $projName = trim((string) ($cells['A'] ?? ''));
                if ($projName === '') continue;
                if (in_array($projName, fin_payment_statuses(), true)) {
                    $cur = array_search($projName, fin_payment_statuses(), true);
                    $idx = array_search($cur, $statusSeq, true);
                    if ($idx !== false && isset($statusSeq[$idx + 1])) $curStatus = $statusSeq[$idx + 1];
                    continue;
                }
                $gyName = FIN_IMPORT_MAP[$projName] ?? null;
                if ($gyName === null) {
                    $pay[] = ['project' => $projName, 'mapped' => false, 'reason' => '无映射'];
                    continue;
                }
                $pid = fin_project_id_by_name($gyName);
                if ($pid === null) {
                    $pay[] = ['project' => $projName, 'mapped' => false, 'reason' => '系统项目库中不存在: ' . $gyName];
                    continue;
                }
                $total = 0.0;
                $cnt = 0;
                foreach (FIN_IMPORT_PAY_COLS as $col => $key) {
                    $v = $cells[$col] ?? null;
                    if (is_numeric($v) && (float) $v != 0) {
                        $total += (float) $v;
                        $cnt++;
                    }
                }
                $pay[] = ['project' => $projName, 'project_id' => $pid, 'project_gy' => $gyName,
                    'status' => $curStatus, 'types' => $cnt, 'total_yuan' => $total];
            }
        }

        $recvGrand = array_sum(array_column($recv, 'total_yuan'));
        $payGrand = array_sum(array_column($pay, 'total_yuan'));

        return ['ok' => true, 'path' => basename($save),
            'project_mapping' => $projectRows,
            'receivable' => $recv, 'receivable_grand' => $recvGrand,
            'payment' => $pay, 'payment_grand' => $payGrand,
            'unit_note' => '线下表金额按元导入（表头"万元"为旧标注，实际数值为元）',
            'scope' => '应收导入 2020-2025（含2020以前累计），付款记录导入 2026 年度（记为2026-12）'];
    } catch (\Throwable $e) {
        @unlink($save);
        throw new FinanceStop(['ok' => false, 'msg' => '解析失败: ' . $e->getMessage()], 400);
    }
}

/** 执行导入（覆盖 2020-2025 应收 + 2026 付款记录） */
function handle_fin_import_run(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    if (!fin_is_admin()) throw new FinanceStop(['ok' => false, 'msg' => '仅总部财务可导入'], 403);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $fname = basename((string) ($in['path'] ?? ''));
    if (!preg_match('/^import_\d{14}_[a-f0-9]{8}\.xlsx$/', $fname)) {
        throw new FinanceStop(['ok' => false, 'msg' => '导入文件标识不合法'], 400);
    }
    $path = storage_path('app/finance_import/' . $fname);
    if (!is_file($path)) throw new FinanceStop(['ok' => false, 'msg' => '导入文件不存在，请重新上传'], 400);

    try {
        $parser = new XlsxParser($path);
        $names = $parser->sheetNames();
        $db = fdb();
        $name = (string) ($u['name'] ?: $u['username']);

        // 全量重导：清空应收 items（金额）、月级状态、付款 items
        $db->exec('DELETE FROM fin_receivable_items');
        $db->exec('DELETE FROM fin_receivable_month_status');
        $db->exec('DELETE FROM fin_payment_items');

        // 应收导入
        $recvCount = 0;
        $recvTotal = 0.0;
        foreach (FIN_IMPORT_SHEETS as $sheetName) {
            $gyName = FIN_IMPORT_MAP[$sheetName];
            if (!in_array($sheetName, $names, true)) continue;
            $pid = fin_project_id_by_name($gyName);
            if ($pid === null) continue;
            $rows = $parser->readSheet($sheetName);

            // 1) 删除该项目 2020-2025 既有应收（保证与线下一致；2026 不动）
            $db->prepare("DELETE FROM fin_receivable_items WHERE project_id = ? AND month < '2026-01'")->execute([$pid]);
            $db->prepare("DELETE FROM fin_receivable_attachments WHERE project_id = ? AND month < '2026-01'")->execute([$pid]);

            $ins = $db->prepare(
                'INSERT INTO fin_receivable_items (project_id, month, category, amount, updated_by)
                 VALUES (?,?,?,?,?)'
            );
            $msSel = $db->prepare('SELECT id FROM fin_receivable_month_status WHERE project_id = ? AND month = ? LIMIT 1');
            $msIns = $db->prepare('INSERT INTO fin_receivable_month_status (project_id, month, confirm, contract, payment, discount_amount, discount_households, remark, updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $msUpd = $db->prepare('UPDATE fin_receivable_month_status SET confirm=?, contract=?, payment=?, discount_amount=?, discount_households=?, remark=?, updated_by=? WHERE id=?');
            foreach ($rows as $rnum => $cells) {
                if ($rnum <= 3) continue;
                $a = $cells['A'] ?? null;
                $aStr = is_scalar($a) ? trim((string) $a) : '';
                $month = fin_import_parse_month($a);
                $remark = '';
                if ($month === null) {
                    // "2020年以前总计"
                    if (str_contains($aStr, '2020') && (str_contains($aStr, '以前') || str_contains($aStr, '之前'))) {
                        $month = '2020-01';
                        $remark = '2020年以前累计（线下导入）';
                    } else {
                        continue;
                    }
                }
                if (str_contains($aStr, '合计') || $aStr === '总计') continue;

                $confirm = fin_import_parse_bool($cells['J'] ?? null);
                $contract = fin_import_parse_bool($cells['K'] ?? null);
                $payment = fin_import_parse_bool($cells['L'] ?? null);
                $discountAmount = isset($cells['Q']) && is_numeric($cells['Q']) ? round((float) $cells['Q'], 2) : null;
                $households = null;
                if (isset($cells['R']) && $cells['R'] !== null && trim((string) $cells['R']) !== '' && !is_numeric($cells['R'])) {
                    $households = trim((string) $cells['R']);
                } elseif (isset($cells['R']) && is_numeric($cells['R'])) {
                    $households = (string) (int) $cells['R'];
                }

                foreach (FIN_IMPORT_RECV_COLS as $col => $key) {
                    $v = $cells[$col] ?? null;
                    if (!is_numeric($v) || (float) $v == 0) continue;
                    $amount = round((float) $v, 2);
                    $ins->execute([$pid, $month, $key, $amount, $name]);
                    $recvCount++;
                    $recvTotal += $amount;
                }

                // 月级状态（有任意状态/减免/备注才落）
                if ($confirm !== null || $contract !== null || $payment !== null || $discountAmount !== null || $households !== null || $remark !== '') {
                    $msSel->execute([$pid, $month]);
                    $msid = $msSel->fetchColumn();
                    if ($msid) {
                        $msUpd->execute([$confirm, $contract, $payment, $discountAmount, $households, $remark, $name, (int) $msid]);
                    } else {
                        $msIns->execute([$pid, $month, $confirm, $contract, $payment, $discountAmount, $households, $remark, $name]);
                    }
                }
            }
        }

        // 付款记录导入（2026年度 · 三状态区块）
        $payCount = 0;
        $payTotal = 0.0;
        if (in_array('付款记录', $names, true)) {
            $rows = $parser->readSheet('付款记录');
            $insP = $db->prepare(
                'INSERT INTO fin_payment_items (project_id, month, status, type, amount, remark, updated_by) VALUES (?,?,?,?,?,?,?)'
            );
            $curStatus = 'confirmed';
            $statusSeq = ['confirmed', 'paid', 'unpaid']; // 原表块序：已确认数据→已支付→未支付，状态标记行在每块末尾
            foreach ($rows as $rnum => $cells) {
                $projName = trim((string) ($cells['A'] ?? ''));
                if ($projName === '') continue;
                if (in_array($projName, fin_payment_statuses(), true)) {
                    // 块尾合计行：切到下一块状态
                    $cur = array_search($projName, fin_payment_statuses(), true);
                    $idx = array_search($cur, $statusSeq, true);
                    if ($idx !== false && isset($statusSeq[$idx + 1])) {
                        $curStatus = $statusSeq[$idx + 1];
                        if ($curStatus === 'unpaid') break; // 未支付为自动计算（已确认−已支付），不入库
                    }
                    continue;
                }
                $gyName = FIN_IMPORT_MAP[$projName] ?? null;
                if ($gyName === null) continue;
                $pid = fin_project_id_by_name($gyName);
                if ($pid === null) continue;
                foreach (FIN_IMPORT_PAY_COLS as $col => $key) {
                    $v = $cells[$col] ?? null;
                    if (!is_numeric($v) || (float) $v == 0) continue;
                    $amount = round((float) $v, 2);
                    $insP->execute([$pid, '2026', $curStatus, $key, $amount, '2026年度关联费用（线下导入）', $name]);
                    $payCount++;
                    $payTotal += $amount;
                }
            }
        }

        @unlink($path);
        return ['ok' => true, 'receivable_rows' => $recvCount, 'receivable_total' => $recvTotal,
            'payment_rows' => $payCount, 'payment_total' => $payTotal,
            'msg' => '导入完成：应收 ' . $recvCount . ' 条 / ' . number_format($recvTotal, 2) . ' 元；付款记录 ' . $payCount . ' 条 / ' . number_format($payTotal, 2) . ' 元'];
    } catch (\Throwable $e) {
        throw new FinanceStop(['ok' => false, 'msg' => '导入失败: ' . $e->getMessage()], 500);
    }
}
