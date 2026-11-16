<?php

namespace App\Invoice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InvoiceDelivery
{
    /**
     * @return array{ok:bool,error?:string,provider_msg_id?:string,channel:string}
     */
    public function send(object $request, object $accountSet, int $attempt = 1): array
    {
        $email = (string) $request->buyer_email;
        if ($email === '') {
            $this->log($request, 'email', '', 'failed', null, '购方邮箱为空', $attempt);
            return ['ok' => false, 'error' => '购方邮箱为空', 'channel' => 'email'];
        }

        // 默认本站 SMTP；诺诺代发需显式开启且平台确认免费
        if (($accountSet->email_provider ?? 'smtp') === 'nuonuo') {
            if (!config('invoice.nuonuo.allow_nuonuo_email')) {
                $msg = '诺诺代发未启用（需先确认是否收费，默认关闭）';
                $this->log($request, 'email', $email, 'failed', null, $msg, $attempt);
                return ['ok' => false, 'error' => $msg, 'channel' => 'email'];
            }
        }

        try {
            $this->applySmtpOverride($accountSet);

            $from = $accountSet->email_from ?: config('mail.from.address');
            $fromName = $accountSet->email_from_name ?: config('mail.from.name');
            $replyTo = $accountSet->email_reply_to;
            $subject = '电子发票已开具 - ' . $request->request_no;
            $body = implode("\n", [
                '您好，' . $request->payer_name . '：',
                '',
                '您申请的电子发票已开具。',
                '发票号码：' . ($request->digital_invoice_no ?: '（待回执）'),
                '开票金额：¥' . number_format((float) ($request->verified_amount ?: $request->declared_amount), 2),
                '开票主体：' . $request->seller_name_snapshot,
                '申请单号：' . $request->request_no,
                '',
                '发票 PDF 见附件；如附件缺失，可凭查询密钥在开票进度页下载。',
                '',
                '此邮件由系统自动发送，请勿直接回复。',
            ]);

            $mailer = $this->mailerName($accountSet);
            Mail::mailer($mailer)->raw($body, function ($message) use ($email, $from, $fromName, $replyTo, $subject, $request) {
                $message->to($email)->subject($subject);
                if ($from) {
                    $message->from($from, $fromName);
                }
                if ($replyTo) {
                    $message->replyTo($replyTo);
                }
                $path = $request->pdf_path
                    ? Storage::disk((string) config('invoice.storage.disk', 'local'))->path($request->pdf_path)
                    : null;
                if ($path && is_file($path)) {
                    $message->attach($path, ['as' => 'invoice-' . $request->request_no . '.pdf']);
                }
            });

            $this->log($request, 'email', $this->mask($email), 'sent', null, null, $attempt);
            return ['ok' => true, 'channel' => 'email', 'provider_msg_id' => null];
        } catch (\Throwable $e) {
            $this->log($request, 'email', $this->mask($email), 'failed', null, $e->getMessage(), $attempt);
            return ['ok' => false, 'error' => $e->getMessage(), 'channel' => 'email'];
        }
    }

    private function mailerName(object $accountSet): string
    {
        return $accountSet->smtp_host ? 'invoice_' . $accountSet->id : (string) config('mail.default', 'smtp');
    }

    private function applySmtpOverride(object $accountSet): void
    {
        if (empty($accountSet->smtp_host)) {
            return;
        }
        $name = 'invoice_' . $accountSet->id;
        config([
            'mail.mailers.' . $name => [
                'transport' => 'smtp',
                'host' => $accountSet->smtp_host,
                'port' => (int) ($accountSet->smtp_port ?: 465),
                'username' => $accountSet->smtp_username,
                'password' => $this->smtpPassword($accountSet),
                'encryption' => $accountSet->smtp_encryption ?: null,
                'timeout' => 20,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
        ]);
    }

    private function smtpPassword(object $accountSet): ?string
    {
        if (empty($accountSet->smtp_password_encrypted)) {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($accountSet->smtp_password_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function log(object $request, string $channel, string $target, string $result, ?string $msgId, ?string $error, int $attempt): void
    {
        DB::table('invoice_delivery_logs')->insert([
            'request_id' => $request->id,
            'account_set_id' => $request->account_set_id,
            'channel' => $channel,
            'target' => $target,
            'result' => $result,
            'provider_msg_id' => $msgId,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'attempt' => $attempt,
            'created_at' => now(),
        ]);
    }

    private function mask(string $email): string
    {
        if (!str_contains($email, '@')) {
            return mb_substr($email, 0, 3) . '***';
        }
        [$name, $domain] = explode('@', $email, 2);
        return mb_substr($name, 0, 2) . '***@' . $domain;
    }
}
