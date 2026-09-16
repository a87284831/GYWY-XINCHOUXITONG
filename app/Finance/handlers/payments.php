<?php
/** 财务管理 - 付款记录（关联费用）handlers
 *  结构：2026 年度 · 每项目不分月份 · 三状态区块（已确认数据/已支付/未支付） */

use App\Finance\FinanceStop;
use App\Finance\Support;

/** 付款记录矩阵：全部项目（或单项目）三状态区块，每块 项目×8类+合计 */
function handle_payments_matrix(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId > 0) fin_assert_project_access($projectId);

    $scope = $projectId > 0 ? [$projectId] : fin_accessible_projects();
    $types = fin_payment_types();
    $statuses = fin_payment_statuses();

    $in = implode(',', array_fill(0, count($scope), '?'));
    // 未支付为自动计算，不再从 DB 读取（仅读 confirmed/paid）
    $st = fdb()->prepare("SELECT project_id, status, type, amount FROM fin_payment_items WHERE status IN ('confirmed','paid') AND project_id IN ($in)");
    $st->execute($scope);
    $rows = $st->fetchAll();

    $byProjStatus = [];
    foreach ($rows as $r) {
        $byProjStatus[(int) $r['project_id']][$r['status']][$r['type']] = (float) $r['amount'];
    }

    // confirmed / paid 区块：从 DB 读取
    $blocks = [];
    foreach (['confirmed', 'paid'] as $sk) {
        $projects = [];
        $grand = 0.0;
        foreach (fin_projects() as $p) {
            if (!in_array((int) $p['id'], $scope, true)) continue;
            $typesArr = [];
            $total = 0.0;
            foreach (array_keys($types) as $tk) {
                $amt = (float) ($byProjStatus[(int) $p['id']][$sk][$tk] ?? 0);
                $typesArr[$tk] = $amt;
                $total += $amt;
            }
            $grand += $total;
            $projects[] = ['project_id' => (int) $p['id'], 'name' => $p['name'], 'types' => $typesArr, 'total' => $total];
        }
        $blocks[$sk] = ['status' => $sk, 'status_name' => $statuses[$sk], 'projects' => $projects, 'grand' => $grand];
    }

    // 未支付 = 已确认数据 − 已支付（自动计算，与原表公式一致）
    $blocks['unpaid'] = ['status' => 'unpaid', 'status_name' => $statuses['unpaid'], 'projects' => [], 'grand' => 0.0];
    foreach ($blocks['confirmed']['projects'] as $i => $pc) {
        $pid = $pc['project_id'];
        $pp = $blocks['paid']['projects'][$i] ?? null;
        $typesArr = [];
        $total = 0.0;
        foreach (array_keys($types) as $tk) {
            $amt = round((float) ($pc['types'][$tk] ?? 0) - (float) ($pp['types'][$tk] ?? 0), 2);
            if ($amt < 0) $amt = 0; // 不出现负未支付
            $typesArr[$tk] = $amt;
            $total += $amt;
        }
        $blocks['unpaid']['grand'] += $total;
        $blocks['unpaid']['projects'][] = ['project_id' => $pid, 'name' => $pc['name'], 'types' => $typesArr, 'total' => $total];
    }

    return ['ok' => true, 'year' => 2026, 'types' => $types, 'statuses' => $statuses, 'blocks' => $blocks, 'is_admin' => fin_is_admin()];
}

/** 付款记录保存：某项目某状态区块 8 类金额（upsert） */
function handle_payments_save(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $projectId = (int) ($in['project_id'] ?? 0);
    $status = (string) ($in['status'] ?? 'confirmed');
    $items = $in['items'] ?? [];
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);
    $statuses = fin_payment_statuses();
    if (!isset($statuses[$status])) throw new FinanceStop(['ok' => false, 'msg' => '状态不合法'], 400);
    if ($status === 'unpaid') throw new FinanceStop(['ok' => false, 'msg' => '未支付为自动计算（已确认数据−已支付），不可直接保存'], 400);
    if (!is_array($items) || count($items) > 15) throw new FinanceStop(['ok' => false, 'msg' => '数据项不合法'], 400);

    $types = fin_payment_types();
    $name = (string) ($u['name'] ?: $u['username']);
    $db = fdb();
    $sel = $db->prepare('SELECT id FROM fin_payment_items WHERE project_id = ? AND status = ? AND type = ? LIMIT 1');
    $upd = $db->prepare('UPDATE fin_payment_items SET amount=?, remark=?, updated_by=? WHERE id=?');
    $ins = $db->prepare('INSERT INTO fin_payment_items (project_id, month, status, type, amount, remark, updated_by) VALUES (?,?,?,?,?,?,?)');

    $db->beginTransaction();
    try {
        $saved = 0;
        foreach ($items as $it) {
            $type = (string) ($it['type'] ?? '');
            if (!isset($types[$type])) continue;
            $amount = round((float) ($it['amount'] ?? 0), 2);
            $remark = trim((string) ($it['remark'] ?? ''));
            if ($amount == 0 && $remark === '') {
                $db->prepare('DELETE FROM fin_payment_items WHERE project_id = ? AND status = ? AND type = ?')
                    ->execute([$projectId, $status, $type]);
                continue;
            }
            $sel->execute([$projectId, $status, $type]);
            $id = $sel->fetchColumn();
            if ($id) {
                $upd->execute([$amount, $remark, $name, (int) $id]);
            } else {
                $ins->execute([$projectId, '2026', $status, $type, $amount, $remark, $name]);
            }
            $saved++;
        }
        $db->commit();
        return ['ok' => true, 'saved' => $saved];
    } catch (\Throwable $e) {
        $db->rollBack();
        throw new FinanceStop(['ok' => false, 'msg' => '保存失败: ' . $e->getMessage()], 500);
    }
}

/** 付款记录附件列表（按状态块，month 固定 2026） */
function handle_payments_attachments(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $status = (string) ($_GET['status'] ?? 'confirmed');
    if ($projectId <= 0) throw new FinanceStop(['ok' => false, 'msg' => '缺少项目'], 400);
    fin_assert_project_access($projectId);
    $st = fdb()->prepare('SELECT id, file_name, file_size, uploaded_by, created_at FROM fin_payment_attachments WHERE project_id = ? AND status = ? ORDER BY id');
    $st->execute([$projectId, $status]);
    return ['ok' => true, 'attachments' => $st->fetchAll()];
}

/** 付款记录附件上传 */
function handle_payments_attachment_upload(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'confirmed');
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
    $dir = public_path('finance/uploads/payment/' . $projectId . '/' . $status);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $fname)) {
        throw new FinanceStop(['ok' => false, 'msg' => '文件保存失败'], 500);
    }
    $st = fdb()->prepare('INSERT INTO fin_payment_attachments (project_id, month, status, file_name, file_path, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$projectId, '2026', $status, $orig, 'finance/uploads/payment/' . $projectId . '/' . $status . '/' . $fname, $size, $u['name'] ?: $u['username']]);
    return ['ok' => true, 'id' => (int) fdb()->lastInsertId(), 'file_name' => $orig];
}

/** 付款记录附件删除 */
function handle_payments_attachment_delete(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) throw new FinanceStop(['ok' => false, 'msg' => '参数错误'], 400);
    $st = fdb()->prepare('SELECT project_id, file_path FROM fin_payment_attachments WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return ['ok' => true, 'deleted' => 0];
    fin_assert_project_access((int) $row['project_id']);
    fdb()->prepare('DELETE FROM fin_payment_attachments WHERE id = ?')->execute([$id]);
    $full = public_path($row['file_path']);
    if (is_file($full)) @unlink($full);
    return ['ok' => true, 'deleted' => 1];
}
