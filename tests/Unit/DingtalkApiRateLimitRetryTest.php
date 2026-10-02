<?php

namespace Tests\Unit;

use App\Services\DingtalkService;
use Tests\TestCase;

/**
 * 2026-10-02 加固：手动"立即同步"在整点/半点撞钉钉共享 QPS 秒级限流（90002，
 * 平台级共享池 1200 次/秒，限流 1 秒即解除），拉部门第一步即整场失败。
 * api() 增加 90002/qps 自动退避重试（最多 3 次）；sendApiRequest 抽为
 * protected 注入响应序列，rateLimitBackoff 覆写为 0 加速测试。
 */
class DingtalkApiRateLimitRetryTest extends TestCase
{
    /** responses 每项为 [rawBody, curlError] 元组 */
    private function service(array $responses): DingtalkService
    {
        return new class($responses) extends DingtalkService {
            public array $calls = [];
            private int $cursor = 0;

            public function __construct(private readonly array $responses)
            {
            }

            public function getAccessToken(): ?string
            {
                return 'test-token';
            }

            protected function rateLimitBackoff(int $attempt): int
            {
                return 0;
            }

            protected function sendApiRequest(string $fullUrl, array $headers, array $body): array
            {
                $this->calls[] = $body;
                return $this->responses[$this->cursor++] ?? ['{}', ''];
            }
        };
    }

    public function test_rate_limit_then_success_retries(): void
    {
        $svc = $this->service([
            ['{"errcode":90002,"subcode":90002,"errmsg":"ding talk error{subcode=90002,submsg=超出了该接口承受的最大qps}"}', ''],
            ['{"errcode":0,"result":[]}', ''],
        ]);

        $r = $svc->api('https://oapi.dingtalk.com/topapi/v2/department/listsub', ['dept_id' => 1]);

        $this->assertSame(0, $r['errcode']);
        $this->assertCount(2, $svc->calls, '限流后应退避重试一次');
    }

    public function test_rate_limit_exhausts_three_attempts(): void
    {
        $svc = $this->service([
            ['{"errcode":90002,"errmsg":"qps limited"}', ''],
            ['{"errcode":90002,"errmsg":"qps limited"}', ''],
            ['{"errcode":90002,"errmsg":"qps limited"}', ''],
        ]);

        $r = $svc->api('https://oapi.dingtalk.com/topapi/v2/department/listsub');

        $this->assertSame(90002, $r['errcode'], '3 次后仍限流则原样返回给上层抛错');
        $this->assertCount(3, $svc->calls);
    }

    public function test_qps_in_errmsg_triggers_retry_even_with_other_errcode(): void
    {
        $svc = $this->service([
            ['{"errcode":-1,"errmsg":"当前所有钉钉应用调用该接口次数过多，超出了该接口承受的最大qps"}', ''],
            ['{"errcode":0,"result":[]}', ''],
        ]);

        $r = $svc->api('https://oapi.dingtalk.com/topapi/v2/user/list', ['dept_id' => 5]);

        $this->assertSame(0, $r['errcode']);
        $this->assertCount(2, $svc->calls, 'errmsg 含 qps 字样也应识别为限流（钉钉个别路径 errcode 不规范）');
    }

    public function test_non_rate_limit_error_no_retry(): void
    {
        $svc = $this->service([
            ['{"errcode":60011,"errmsg":"no privilege"}', ''],
        ]);

        $r = $svc->api('https://oapi.dingtalk.com/topapi/v2/department/listsub');

        $this->assertSame(60011, $r['errcode']);
        $this->assertCount(1, $svc->calls, '非限流错误不重试，避免掩盖真实故障');
    }

    public function test_curl_error_no_retry(): void
    {
        $svc = $this->service([
            [false, 'connection reset'],
        ]);

        $r = $svc->api('https://oapi.dingtalk.com/topapi/v2/department/listsub');

        $this->assertSame(-1, $r['errcode']);
        $this->assertSame('connection reset', $r['errmsg']);
        $this->assertCount(1, $svc->calls);
    }
}
