<?php

namespace App\Invoice\Issuers;

/**
 * 按账套选择开票实现：
 * - 有 appKey + appSecret（库内密文或环境变量覆盖）→ 诺诺真实实现；
 * - 缺任一凭据 → 本地假实现，并在申请单标记 simulated=1。
 * 绝不硬编码任何密钥。
 */
class IssuerFactory
{
    public function for(object $accountSet): InvoiceIssuer
    {
        $hasKey = !empty($accountSet->nuonuo_app_key);
        $hasSecret = !empty($accountSet->nuonuo_app_secret_encrypted)
            || (bool) env(config('invoice.nuonuo.secret_override_prefix') . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $accountSet->code)));

        if ($hasKey && $hasSecret) {
            return new NuonuoIssuer();
        }

        return new FakeIssuer();
    }
}
