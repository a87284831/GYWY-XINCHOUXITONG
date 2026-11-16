<?php

return [
    // 未定业务参数的推荐默认值（全部可由账套覆盖，不写死在逻辑里）
    'defaults' => [
        'amount_limit' => (float) env('INVOICE_AMOUNT_LIMIT', 50000),
        'house_no_regex' => env('INVOICE_HOUSE_NO_REGEX', '/^[0-9A-Za-z\x{4e00}-\x{9fa5}]+-[0-9A-Za-z\x{4e00}-\x{9fa5}]+-[0-9A-Za-z\x{4e00}-\x{9fa5}]+$/u'),
        'attachments_min' => (int) env('INVOICE_ATTACHMENTS_MIN', 1),
        'attachments_max' => (int) env('INVOICE_ATTACHMENTS_MAX', 3),
        'attachment_max_kb' => (int) env('INVOICE_ATTACHMENT_MAX_KB', 5120),
        'attachments_total_kb' => (int) env('INVOICE_ATTACHMENTS_TOTAL_KB', 12288),
        'red_flush_second_approval' => (bool) env('INVOICE_RED_FLUSH_SECOND_APPROVAL', false),
        'special_invoice_enabled' => (bool) env('INVOICE_SPECIAL_ENABLED', false),
        'legacy_backfill_enabled' => (bool) env('INVOICE_LEGACY_BACKFILL', false),
        'delivery_retry_minutes' => [1, 5, 15],
        'delivery_max_attempts' => 3,
        'issue_retry_minutes' => [1, 5, 15],
        'issue_max_attempts' => 3,
        'callback_timeout_minutes' => 5,
    ],

    // 诺诺开放平台：凭据按账套存库，base_url 走环境配置，不硬编码任何密钥
    'nuonuo' => [
        'base_url' => env('NUONUO_BASE_URL', 'https://open.nuonuo.com'),
        'token_path' => env('NUONUO_TOKEN_PATH', '/openApi/token'),
        'invoice_path' => env('NUONUO_INVOICE_PATH', '/openApi/invoice'),
        'red_path' => env('NUONUO_RED_PATH', '/openApi/red'),
        'query_path' => env('NUONUO_QUERY_PATH', '/openApi/query'),
        'timeout' => (int) env('NUONUO_TIMEOUT', 15),
        // appSecret 覆盖：NUONUO_SECRET_OVERRIDE_{CODE}，优先级高于库内密文
        'secret_override_prefix' => 'NUONUO_SECRET_OVERRIDE_',
        'allow_nuonuo_email' => (bool) env('NUONUO_EMAIL_DELIVERY', false),
    ],

    // 附件私有存储根目录（storage/app/private 下）
    'storage' => [
        'disk' => 'local',
        'root' => 'invoice',
        'preview_ttl_minutes' => 5,
        'download_ttl_minutes' => 10,
    ],

    // 假实现：凭据缺失时自动启用，并在单据上标记 simulated=1
    'fake_issuer' => [
        'enabled' => (bool) env('INVOICE_FAKE_ISSUER', true),
        'prefix' => env('INVOICE_FAKE_PREFIX', 'SIM'),
    ],
];
