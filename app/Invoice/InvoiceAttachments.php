<?php

namespace App\Invoice;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 缴费凭证附件：私有磁盘存储、md5 索引、短时签名预览/下载，禁止公开直链。
 */
class InvoiceAttachments
{
    public function storeMany(array $files, object $request, string $accountSetCode): void
    {
        $dir = trim((string) config('invoice.storage.root', 'invoice'), '/')
            . '/' . $this->safeSegment($accountSetCode)
            . '/' . now()->format('Ym')
            . '/' . $request->id;

        foreach (array_values($files) as $i => $file) {
            /** @var UploadedFile $file */
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
            $name = now()->format('His') . '_' . ($i + 1) . '_' . substr(md5_file($file->getRealPath()), 0, 8) . '.' . $ext;
            $md5 = md5_file($file->getRealPath());
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $size = (int) $file->getSize();

            Storage::disk((string) config('invoice.storage.disk', 'local'))->putFileAs($dir, $file, $name);

            DB::table('invoice_attachments')->insert([
                'request_id' => $request->id,
                'account_set_id' => $request->account_set_id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $dir . '/' . $name,
                'mime' => $mime,
                'size' => $size,
                'md5' => $md5,
                'sort' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function forRequest(int $requestId): array
    {
        return DB::table('invoice_attachments')
            ->where('request_id', $requestId)
            ->orderBy('sort')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'file_name' => $row->file_name,
                'mime' => $row->mime,
                'size' => (int) $row->size,
                'md5' => $row->md5,
                'preview_url' => $this->signedUrl((int) $row->id),
            ])
            ->all();
    }

    public function signedUrl(int $attachmentId, ?int $minutes = null): string
    {
        $ttl = $minutes ?? (int) config('invoice.storage.preview_ttl_minutes', 5);
        $exp = time() + max(1, $ttl) * 60;
        $sig = hash_hmac('sha256', $attachmentId . '|' . $exp, (string) config('app.key'));
        return '/api/invoice/attachment/' . $attachmentId . '?exp=' . $exp . '&sig=' . $sig;
    }

    public function verify(int $attachmentId, int $exp, string $sig): bool
    {
        if ($exp < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $attachmentId . '|' . $exp, (string) config('app.key'));
        return hash_equals($expected, $sig);
    }

    public function find(int $attachmentId): ?object
    {
        return DB::table('invoice_attachments')->where('id', $attachmentId)->first();
    }

    public function absolutePath(object $attachment): ?string
    {
        $disk = Storage::disk((string) config('invoice.storage.disk', 'local'));
        return $disk->exists($attachment->file_path) ? $disk->path($attachment->file_path) : null;
    }

    public function deleteForRequest(int $requestId): void
    {
        $rows = DB::table('invoice_attachments')->where('request_id', $requestId)->get();
        $disk = Storage::disk((string) config('invoice.storage.disk', 'local'));
        foreach ($rows as $row) {
            $disk->delete($row->file_path);
        }
        DB::table('invoice_attachments')->where('request_id', $requestId)->delete();
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?: 'default';
    }
}
