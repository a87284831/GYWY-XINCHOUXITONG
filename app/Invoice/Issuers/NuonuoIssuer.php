<?php

namespace App\Invoice\Issuers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 诺诺数电票适配器（开放平台）。
 * 真实字段以诺诺正式文档为准；凭据缺失时不会实例化（由 IssuerFactory 切到 FakeIssuer）。
 */
class NuonuoIssuer implements InvoiceIssuer
{
    public function name(): string
    {
        return 'nuonuo';
    }

    public function issue(array $request, object $accountSet): array
    {
        try {
            $token = $this->token($accountSet);
            $payload = [
                'orderNo' => $request['request_no'],
                'sellerName' => $request['seller_name_snapshot'],
                'sellerTaxNo' => $request['seller_tax_no_snapshot'],
                'buyerName' => $request['title'],
                'buyerTaxNo' => $request['buyer_tax_no'] ?: null,
                'buyerEmail' => $request['buyer_email'],
                'invoiceType' => 'digital',
                'totalAmount' => number_format((float) $request['verified_amount'], 2, '.', ''),
                'taxAmount' => number_format((float) $request['tax_amount'], 2, '.', ''),
                'amountWithoutTax' => number_format((float) $request['amount_ex_tax'], 2, '.', ''),
                'remark' => $request['fee_period'] ?: '',
                'items' => [[
                    'goodsName' => $accountSet->default_goods_name ?: '物业管理服务费',
                    'goodsCode' => $request['goods_code_snapshot'] ?: $accountSet->default_goods_code,
                    'taxRate' => (float) $request['tax_rate_snapshot'],
                    'quantity' => '1',
                    'price' => number_format((float) $request['verified_amount'], 2, '.', ''),
                ]],
            ];

            $resp = Http::timeout((int) config('invoice.nuonuo.timeout', 15))
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl() . config('invoice.nuonuo.invoice_path'), $payload);

            $json = $resp->json() ?: [];
            if (!$resp->successful() || (($json['code'] ?? '') !== '0000' && ($json['code'] ?? '') !== 0)) {
                return [
                    'ok' => false,
                    'fail_code' => (string) ($json['code'] ?? $resp->status()),
                    'fail_msg' => (string) ($json['msg'] ?? $json['message'] ?? '诺诺开票失败'),
                    'raw' => $json,
                ];
            }

            return [
                'ok' => true,
                'invoice_no' => (string) ($json['invoiceNo'] ?? $json['data']['invoiceNo'] ?? ''),
                'order_no' => (string) ($json['orderNo'] ?? $request['request_no']),
                'pdf_path' => null,
                'ofd_path' => null,
                'simulated' => false,
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::warning('nuonuo issue failed', ['request_no' => $request['request_no'], 'error' => $e->getMessage()]);
            return [
                'ok' => false,
                'fail_code' => 'NUONUO_ERROR',
                'fail_msg' => $e->getMessage(),
            ];
        }
    }

    public function redFlush(array $request, object $accountSet, string $reason): array
    {
        try {
            $token = $this->token($accountSet);
            $resp = Http::timeout((int) config('invoice.nuonuo.timeout', 15))
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl() . config('invoice.nuonuo.red_path'), [
                    'orderNo' => $request['request_no'],
                    'invoiceNo' => $request['digital_invoice_no'],
                    'reason' => $reason,
                ]);
            $json = $resp->json() ?: [];
            if (!$resp->successful() || (($json['code'] ?? '') !== '0000' && ($json['code'] ?? '') !== 0)) {
                return [
                    'ok' => false,
                    'fail_code' => (string) ($json['code'] ?? $resp->status()),
                    'fail_msg' => (string) ($json['msg'] ?? '诺诺红冲失败'),
                    'raw' => $json,
                ];
            }
            return [
                'ok' => true,
                'red_confirm_no' => (string) ($json['redConfirmNo'] ?? $json['data']['redConfirmNo'] ?? ''),
                'red_invoice_no' => (string) ($json['redInvoiceNo'] ?? $json['data']['redInvoiceNo'] ?? ''),
                'simulated' => false,
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fail_code' => 'NUONUO_ERROR', 'fail_msg' => $e->getMessage()];
        }
    }

    private function token(object $accountSet): string
    {
        $key = 'invoice:nuonuo:token:' . $accountSet->code . ':' . ($accountSet->nuonuo_env ?: 'sandbox');
        return Cache::remember($key, now()->addMinutes(25), function () use ($accountSet) {
            $resp = Http::timeout((int) config('invoice.nuonuo.timeout', 15))
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl() . config('invoice.nuonuo.token_path'), [
                    'appKey' => $accountSet->nuonuo_app_key,
                    'appSecret' => $this->secret($accountSet),
                    'orgId' => $accountSet->nuonuo_org_id,
                ]);
            $json = $resp->json() ?: [];
            $token = $json['access_token'] ?? $json['data']['access_token'] ?? $json['token'] ?? null;
            if (!$resp->successful() || !$token) {
                throw new \RuntimeException('诺诺鉴权失败：' . ($json['msg'] ?? $resp->status()));
            }
            return (string) $token;
        });
    }

    private function secret(object $accountSet): ?string
    {
        $overrideKey = config('invoice.nuonuo.secret_override_prefix') . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $accountSet->code));
        $override = env($overrideKey);
        if ($override) {
            return (string) $override;
        }
        if (empty($accountSet->nuonuo_app_secret_encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($accountSet->nuonuo_app_secret_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('invoice.nuonuo.base_url'), '/');
    }
}
