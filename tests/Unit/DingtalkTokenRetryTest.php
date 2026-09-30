<?php

namespace Tests\Unit;

use App\Services\DingtalkService;
use PHPUnit\Framework\TestCase;

/**
 * P1-3 修复：getAccessToken 失败自动重试 1 次（网络抖动不再导致整次同步白跑）。
 * fetchAccessToken 抽为 protected 以便测试注入失败序列。
 */
class DingtalkTokenRetryTest extends TestCase
{
    private string $cacheFile;
    private int $attempts = 0;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/dt_token_test_' . getmypid() . '.cache';
        @unlink($this->cacheFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    private function service(array $fetchResults): DingtalkService
    {
        $this->attempts = 0;
        $results = $fetchResults;
        $svc = new class($results, $this->cacheFile, function () {
            $this->attempts++;
        }) extends DingtalkService {
            public array $results;
            public string $path;
            public \Closure $onFetch;

            public function __construct(array $results, string $path, \Closure $onFetch)
            {
                $this->results = $results;
                $this->path = $path;
                $this->onFetch = $onFetch;
            }

            public function tokenCachePath(): string
            {
                return $this->path;
            }

            protected function fetchAccessToken(): ?string
            {
                ($this->onFetch)();
                $r = array_shift($this->results);
                return $r;
            }
        };
        return $svc;
    }

    public function test_retries_once_after_failure_then_succeeds(): void
    {
        $svc = $this->service([null, 'tok1234567890']);
        $this->assertSame('tok1234567890', $svc->getAccessToken());
        $this->assertSame(2, $this->attempts);
        // 成功后写入缓存文件
        $this->assertSame('tok1234567890', trim((string) file_get_contents($this->cacheFile)));
    }

    public function test_both_attempts_fail_returns_null(): void
    {
        $svc = $this->service([null, null]);
        $this->assertNull($svc->getAccessToken());
        $this->assertSame(2, $this->attempts);
    }

    public function test_first_attempt_success_no_retry(): void
    {
        $svc = $this->service(['tok1234567890']);
        $this->assertSame('tok1234567890', $svc->getAccessToken());
        $this->assertSame(1, $this->attempts);
    }

    public function test_uses_cached_token_without_fetching(): void
    {
        file_put_contents($this->cacheFile, 'cached_token_0001');
        touch($this->cacheFile, time() - 10);
        $svc = $this->service([]);
        $this->assertSame('cached_token_0001', $svc->getAccessToken());
        $this->assertSame(0, $this->attempts);
    }

    public function test_memory_token_reused_without_refetch(): void
    {
        $svc = $this->service(['tok1234567890']);
        $svc->getAccessToken();
        $again = $svc->getAccessToken();
        $this->assertSame('tok1234567890', $again);
        $this->assertSame(1, $this->attempts);
    }
}
