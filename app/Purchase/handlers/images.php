<?php
/** 采购填报示例图片：每行一张，私有存储 + 缩略图 */

function purchase_image_table_ready(): bool {
    try {
        db()->query("SELECT 1 FROM purchase_item_images LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function purchase_image_dir(int $itemId): string {
    return storage_path('app/private/purchase_images/' . $itemId);
}

function purchase_image_record(int $itemId): ?array {
    if (!purchase_image_table_ready()) return null;
    $st = db()->prepare("SELECT * FROM purchase_item_images WHERE item_id=? LIMIT 1");
    $st->execute([$itemId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 批量取缩略图路径：item_id => 绝对路径 */
function purchase_image_map(array $itemIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($v) => $v > 0)));
    if (!$ids || !purchase_image_table_ready()) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT item_id, thumb_name, file_name FROM purchase_item_images WHERE item_id IN ($ph)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $dir = purchase_image_dir((int) $r['item_id']);
        $name = (string) ($r['thumb_name'] ?: $r['file_name']);
        $path = $dir . '/' . $name;
        if ($name !== '' && is_file($path)) $out[(int) $r['item_id']] = $path;
    }
    return $out;
}

/** 在单元格嵌入缩略图；返回是否成功 */
function purchase_image_embed($ws, string $coordinate, ?string $path, int $height = 56): bool {
    if (!$path || !is_file($path)) return false;
    try {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setPath($path);
        $drawing->setHeight($height);
        $drawing->setCoordinates($coordinate);
        $drawing->setOffsetX(2);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($ws);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function purchase_image_make_thumb(string $src, string $dst): bool {
    if (!function_exists('imagecreatefromstring')) return false;
    $raw = @file_get_contents($src);
    if ($raw === false) return false;
    $img = @imagecreatefromstring($raw);
    if (!$img) return false;
    $w = imagesx($img); $h = imagesy($img);
    if ($w <= 0 || $h <= 0) { imagedestroy($img); return false; }
    $scale = min(1, 240 / max($w, $h));
    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
    $dstImg = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dstImg, 255, 255, 255);
    imagefill($dstImg, 0, 0, $white);
    imagecopyresampled($dstImg, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dstImg, $dst, 80);
    imagedestroy($dstImg); imagedestroy($img);
    return (bool) $ok;
}

function handle_fill_image_upload($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) return ['ok' => false, 'msg' => '记录不存在'];
    if ($user['role'] !== 'admin' && (int) $item['project_id'] !== (int) $user['project_id']) {
        return ['ok' => false, 'msg' => '只能操作本项目的记录'];
    }
    if (!fill_line_allowed($user, (string) $item['line'])) return ['ok' => false, 'msg' => '无权操作该条线的记录'];
    if (month_archived($item['month'])) return ['ok' => false, 'msg' => '本月已归档锁定，不可修改图片'];
    if ($item['status'] === 'confirmed') return ['ok' => false, 'msg' => '已确认的记录不可修改图片'];
    if ($user['role'] !== 'admin' && $item['status'] === 'submitted') {
        return ['ok' => false, 'msg' => '已提交的记录不可修改图片，请先撤销提交'];
    }
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => '未收到图片或上传失败'];
    if ((int) $f['size'] <= 0 || (int) $f['size'] > 5 * 1024 * 1024) return ['ok' => false, 'msg' => '图片不能超过 5MB'];
    $info = @getimagesize($f['tmp_name']);
    $typeMap = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
    if (!$info || !isset($typeMap[$info[2]])) return ['ok' => false, 'msg' => '仅支持 JPG / PNG / WEBP 图片'];
    [$ext, $mime] = $typeMap[$info[2]];
    if (!purchase_image_table_ready()) return ['ok' => false, 'msg' => '图片表未初始化，请先执行数据库迁移'];

    $dir = purchase_image_dir((int) $id);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok' => false, 'msg' => '图片目录创建失败'];
    $base = 'item' . (int) $id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $fileName = $base . '.' . $ext;
    $thumbName = 'thumb_' . $base . '.jpg';
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fileName)) return ['ok' => false, 'msg' => '图片保存失败'];
    $thumbOk = purchase_image_make_thumb($dir . '/' . $fileName, $dir . '/' . $thumbName);
    if (!$thumbOk) { $thumbName = $fileName; }

    $old = purchase_image_record((int) $id);
    if ($old) {
        foreach (['file_name', 'thumb_name'] as $k) {
            $n = (string) ($old[$k] ?? '');
            if ($n !== '' && $n !== $fileName && $n !== $thumbName) @unlink($dir . '/' . $n);
        }
        db()->prepare("DELETE FROM purchase_item_images WHERE item_id=?")->execute([(int) $id]);
    }
    db()->prepare("INSERT INTO purchase_item_images (item_id, original_name, file_name, thumb_name, mime, size, uploaded_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([(int) $id, (string) ($f['name'] ?? ''), $fileName, $thumbName, $mime, (int) $f['size'], (int) $user['id']]);
    log_action('上传图片', "{$item['month']} 项目{$item['project_id']} {$item['item_name']} 示例图片");
    return ['ok' => true, 'msg' => '图片已上传', 'has_image' => true, 'version' => time()];
}

function handle_fill_image_delete($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) return ['ok' => false, 'msg' => '记录不存在'];
    if ($user['role'] !== 'admin' && (int) $item['project_id'] !== (int) $user['project_id']) {
        return ['ok' => false, 'msg' => '只能操作本项目的记录'];
    }
    if (!fill_line_allowed($user, (string) $item['line'])) return ['ok' => false, 'msg' => '无权操作该条线的记录'];
    if (month_archived($item['month'])) return ['ok' => false, 'msg' => '本月已归档锁定，不可修改图片'];
    if ($item['status'] === 'confirmed') return ['ok' => false, 'msg' => '已确认的记录不可修改图片'];
    if ($user['role'] !== 'admin' && $item['status'] === 'submitted') {
        return ['ok' => false, 'msg' => '已提交的记录不可修改图片，请先撤销提交'];
    }
    $old = purchase_image_record((int) $id);
    if ($old) {
        $dir = purchase_image_dir((int) $id);
        foreach (['file_name', 'thumb_name'] as $k) {
            $n = (string) ($old[$k] ?? '');
            if ($n !== '') @unlink($dir . '/' . $n);
        }
        db()->prepare("DELETE FROM purchase_item_images WHERE item_id=?")->execute([(int) $id]);
        log_action('删除图片', "{$item['month']} 项目{$item['project_id']} {$item['item_name']} 示例图片");
    }
    return ['ok' => true, 'msg' => '图片已删除', 'has_image' => false];
}

function handle_fill_image_download($id) {
    $user = require_auth();
    $item = fetch_item($id);
    if (!$item) throw new \App\Purchase\PurchaseStop(['ok' => false, 'msg' => '记录不存在'], 404);
    if ($user['role'] !== 'admin' && (int) $item['project_id'] !== (int) $user['project_id']) {
        throw new \App\Purchase\PurchaseStop(['ok' => false, 'msg' => '只能查看本项目的记录'], 403);
    }
    if (!fill_line_allowed($user, (string) $item['line'])) {
        throw new \App\Purchase\PurchaseStop(['ok' => false, 'msg' => '无权查看该条线的记录'], 403);
    }
    $rec = purchase_image_record((int) $id);
    if (!$rec) throw new \App\Purchase\PurchaseStop(['ok' => false, 'msg' => '图片不存在'], 404);
    $thumb = !empty($_GET['thumb']);
    $name = (string) (($thumb ? $rec['thumb_name'] : $rec['file_name']) ?: $rec['file_name']);
    $path = purchase_image_dir((int) $id) . '/' . $name;
    if ($name === '' || !is_file($path)) throw new \App\Purchase\PurchaseStop(['ok' => false, 'msg' => '图片文件不存在'], 404);
    $mime = $thumb ? 'image/jpeg' : ((string) ($rec['mime'] ?: 'application/octet-stream'));
    \App\Purchase\Support::header('Content-Type: ' . $mime);
    \App\Purchase\Support::header('Content-Disposition: inline; filename="' . rawurlencode((string) ($rec['original_name'] ?: $name)) . '"');
    \App\Purchase\Support::header('Cache-Control: private, max-age=300');
    echo file_get_contents($path);
    throw new \App\Purchase\PurchaseStop();
}
