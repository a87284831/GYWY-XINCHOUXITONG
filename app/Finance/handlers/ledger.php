<?php
/** 财务管理 - 应收费用台账 handlers
 *  金额按 11 类别×月份 存 fin_receivable_items；
 *  状态（地产确认/合同签订/付款流程/减免金额/减免户数/备注）为月级，存 fin_receivable_month_status */

use App\Finance\FinanceStop;
use App\Finance\Support;

/** 台账矩阵：某项目某年 月份×11类金额 + 月级状态 + 附件数 */
function handle_ledger_matrix(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);

    $projectId = (int) ($_GET['project_id'] ?? 0);
    [$all, $year] = fin_parse_year();
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);

    $cats = fin_receivable_categories();
    $months = $all ? fin_all_months() : fin_year_months($year);
    $where = $all ? '' : ' AND month LIKE ?';
    $params = [$projectId];
    if (!$all) $params[] = $year . '-%';

    $st = fdb()->prepare(
        'SELECT month, category, amount FROM fin_receivable_items WHERE project_id = ?' . $where
    );
    $st->execute($params);
    $rows = $st->fetchAll();
    $byMonth = [];
    foreach ($rows as $r) {
        $byMonth[$r['month']][] = $r;
    }

    // 月级状态
    $st2 = fdb()->prepare('SELECT month, confirm, contract, payment, discount_amount, discount_households, remark FROM fin_receivable_month_status WHERE project_id = ?' . $where);
    $st2->execute($params);
    $statusByMonth = [];
    foreach ($st2->fetchAll() as $r) {
        $statusByMonth[$r['month']] = $r;
    }

    // 月份附件数
    $at = fdb()->prepare('SELECT month, COUNT(*) c FROM fin_receivable_attachments WHERE project_id = ?' . $where . ' GROUP BY month');
    $at->execute($params);
    $attCnt = [];
    foreach ($at->fetchAll() as $r) $attCnt[$r['month']] = (int) $r['c'];

    $matrix = [];
    foreach ($months as $m) {
        $row = ['month' => $m, 'total' => 0.0, 'attachments' => $attCnt[$m] ?? 0, 'categories' => []];
        $seen = [];
        foreach (($byMonth[$m] ?? []) as $r) {
            $row['categories'][$r['category']] = ['amount' => (float) $r['amount']];
            $row['total'] += (float) $r['amount'];
            $seen[] = $r['category'];
        }
        foreach (array_keys($cats) as $k) {
            if (!in_array($k, $seen, true)) {
                $row['categories'][$k] = ['amount' => 0.0];
            }
        }
        // 月级状态
        $stt = $statusByMonth[$m] ?? null;
        $row['status'] = [
            'confirm' => $stt && $stt['confirm'] !== null ? (int) $stt['confirm'] : null,
            'contract' => $stt && $stt['contract'] !== null ? (int) $stt['contract'] : null,
            'payment' => $stt && $stt['payment'] !== null ? (int) $stt['payment'] : null,
            'discount_amount' => $stt && $stt['discount_amount'] !== null ? (float) $stt['discount_amount'] : null,
            'discount_households' => $stt ? (string) $stt['discount_households'] : '',
            'remark' => $stt ? (string) $stt['remark'] : '',
        ];
        $matrix[] = $row;
    }

    return ['ok' => true, 'project_id' => $projectId, 'year' => $all ? 'all' : (int) $year, 'categories' => $cats,
        'months' => $matrix, 'is_admin' => fin_is_admin()];
}

/** 台账保存：金额（按类别）+ 月级状态 一次落库 */
function handle_ledger_save(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $projectId = (int) ($in['project_id'] ?? 0);
    $month = fin_check_month((string) ($in['month'] ?? ''));
    $items = $in['items'] ?? [];
    $status = $in['status'] ?? null;
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);
    if (!is_array($items) || count($items) > 20) throw new FinanceStop(['ok' => false, 'msg' => '数据项不合法'], 400);

    $cats = fin_receivable_categories();
    $name = (string) ($u['name'] ?: $u['username']);
    $db = fdb();

    $db->beginTransaction();
    try {
        // 1) 金额按类别 upsert（金额为0且无减免信息 → 删格）
        $sel = $db->prepare('SELECT id FROM fin_receivable_items WHERE project_id = ? AND month = ? AND category = ? LIMIT 1');
        $upd = $db->prepare('UPDATE fin_receivable_items SET amount=?, updated_by=? WHERE id=?');
        $ins = $db->prepare('INSERT INTO fin_receivable_items (project_id, month, category, amount, updated_by) VALUES (?,?,?,?,?)');
        $saved = 0;
        foreach ($items as $it) {
            $cat = (string) ($it['category'] ?? '');
            if (!isset($cats[$cat])) continue;
            $amount = round((float) ($it['amount'] ?? 0), 2);
            if ($amount == 0) {
                $db->prepare('DELETE FROM fin_receivable_items WHERE project_id = ? AND month = ? AND category = ?')
                    ->execute([$projectId, $month, $cat]);
                continue;
            }
            $sel->execute([$projectId, $month, $cat]);
            $id = $sel->fetchColumn();
            if ($id) {
                $upd->execute([$amount, $name, (int) $id]);
            } else {
                $ins->execute([$projectId, $month, $cat, $amount, $name]);
            }
            $saved++;
        }

        // 2) 月级状态 upsert
        if (is_array($status)) {
            $confirm = isset($status['confirm']) && $status['confirm'] !== null && $status['confirm'] !== '' ? ((int) $status['confirm'] ? 1 : 0) : null;
            $contract = isset($status['contract']) && $status['contract'] !== null && $status['contract'] !== '' ? ((int) $status['contract'] ? 1 : 0) : null;
            $payment = isset($status['payment']) && $status['payment'] !== null && $status['payment'] !== '' ? ((int) $status['payment'] ? 1 : 0) : null;
            $discountAmount = isset($status['discount_amount']) && $status['discount_amount'] !== '' ? round((float) $status['discount_amount'], 2) : null;
            $households = isset($status['discount_households']) ? trim((string) $status['discount_households']) : '';
            $households = $households === '' ? null : $households;
            $remark = trim((string) ($status['remark'] ?? ''));

            $hasStatus = $confirm !== null || $contract !== null || $payment !== null
                || $discountAmount !== null || $households !== null || $remark !== '';
            $sSel = $db->prepare('SELECT id FROM fin_receivable_month_status WHERE project_id = ? AND month = ? LIMIT 1');
            $sSel->execute([$projectId, $month]);
            $sid = $sSel->fetchColumn();
            if (!$hasStatus) {
                if ($sid) {
                    $db->prepare('DELETE FROM fin_receivable_month_status WHERE id = ?')->execute([(int) $sid]);
                }
            } elseif ($sid) {
                $db->prepare('UPDATE fin_receivable_month_status SET confirm=?, contract=?, payment=?, discount_amount=?, discount_households=?, remark=?, updated_by=? WHERE id=?')
                    ->execute([$confirm, $contract, $payment, $discountAmount, $households, $remark, $name, (int) $sid]);
            } else {
                $db->prepare('INSERT INTO fin_receivable_month_status (project_id, month, confirm, contract, payment, discount_amount, discount_households, remark, updated_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$projectId, $month, $confirm, $contract, $payment, $discountAmount, $households, $remark, $name]);
            }
        }

        $db->commit();
        return ['ok' => true, 'saved' => $saved];
    } catch (\Throwable $e) {
        $db->rollBack();
        throw new FinanceStop(['ok' => false, 'msg' => '保存失败: ' . $e->getMessage()], 500);
    }
}

/** 月份附件列表 */
function handle_ledger_attachments(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $month = fin_check_month((string) ($_GET['month'] ?? ''));
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);

    $st = fdb()->prepare('SELECT id, file_name, file_size, uploaded_by, created_at FROM fin_receivable_attachments WHERE project_id = ? AND month = ? ORDER BY id');
    $st->execute([$projectId, $month]);
    return ['ok' => true, 'attachments' => $st->fetchAll()];
}

/** 月份附件上传（multipart: file, project_id, month） */
function handle_ledger_attachment_upload(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $month = fin_check_month((string) ($_POST['month'] ?? ''));
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new FinanceStop(['ok' => false, 'msg' => '上传文件失败'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    $orig = (string) $_FILES['file']['name'];
    $size = (int) $_FILES['file']['size'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf'], true)) {
        throw new FinanceStop(['ok' => false, 'msg' => '仅支持图片或PDF'], 400);
    }
    if ($size > 20 * 1024 * 1024) {
        throw new FinanceStop(['ok' => false, 'msg' => '文件不能超过20MB'], 400);
    }
    $dir = public_path('finance/uploads/receivable/' . $projectId . '/' . $month);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $fname)) {
        throw new FinanceStop(['ok' => false, 'msg' => '文件保存失败'], 500);
    }
    $st = fdb()->prepare('INSERT INTO fin_receivable_attachments (project_id, month, file_name, file_path, file_size, uploaded_by) VALUES (?,?,?,?,?,?)');
    $st->execute([$projectId, $month, $orig, 'finance/uploads/receivable/' . $projectId . '/' . $month . '/' . $fname, $size, $u['name'] ?: $u['username']]);
    return ['ok' => true, 'id' => (int) fdb()->lastInsertId(), 'file_name' => $orig];
}

/** 月份附件删除 */
function handle_ledger_attachment_delete(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) throw new FinanceStop(['ok' => false, 'msg' => '参数错误'], 400);

    $st = fdb()->prepare('SELECT project_id, file_path FROM fin_receivable_attachments WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return ['ok' => true, 'deleted' => 0];
    fin_assert_project_access((int) $row['project_id']);

    fdb()->prepare('DELETE FROM fin_receivable_attachments WHERE id = ?')->execute([$id]);
    $full = public_path($row['file_path']);
    if (is_file($full)) @unlink($full);
    return ['ok' => true, 'deleted' => 1];
}

/** 填报页数据：某项目某月 11类现状 + 月级状态 + 附件 */
function handle_fill_page(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $month = fin_check_month((string) ($_GET['month'] ?? date('Y-m')));
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);

    $st = fdb()->prepare('SELECT category, amount FROM fin_receivable_items WHERE project_id = ? AND month = ?');
    $st->execute([$projectId, $month]);
    $rows = $st->fetchAll();
    $cats = fin_receivable_categories();
    $items = [];
    foreach (array_keys($cats) as $k) $items[$k] = ['amount' => 0.0];
    foreach ($rows as $r) {
        if (isset($items[$r['category']])) {
            $items[$r['category']] = ['amount' => (float) $r['amount']];
        }
    }

    $st2 = fdb()->prepare('SELECT confirm, contract, payment, discount_amount, discount_households, remark FROM fin_receivable_month_status WHERE project_id = ? AND month = ? LIMIT 1');
    $st2->execute([$projectId, $month]);
    $ms = $st2->fetch();
    $status = [
        'confirm' => $ms && $ms['confirm'] !== null ? (int) $ms['confirm'] : null,
        'contract' => $ms && $ms['contract'] !== null ? (int) $ms['contract'] : null,
        'payment' => $ms && $ms['payment'] !== null ? (int) $ms['payment'] : null,
        'discount_amount' => $ms && $ms['discount_amount'] !== null ? (float) $ms['discount_amount'] : null,
        'discount_households' => $ms ? (string) $ms['discount_households'] : '',
        'remark' => $ms ? (string) $ms['remark'] : '',
    ];

    $at = fdb()->prepare('SELECT id, file_name, file_size, uploaded_by, created_at FROM fin_receivable_attachments WHERE project_id = ? AND month = ? ORDER BY id');
    $at->execute([$projectId, $month]);

    return ['ok' => true, 'project_id' => $projectId, 'month' => $month, 'categories' => $cats,
        'items' => $items, 'status' => $status, 'attachments' => $at->fetchAll(), 'is_admin' => fin_is_admin()];
}
