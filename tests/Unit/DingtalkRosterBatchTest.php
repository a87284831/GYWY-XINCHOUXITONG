<?php

namespace Tests\Unit;

use App\Services\DingtalkService;
use Tests\TestCase;

/**
 * 2026-10-02 修复：花名册批次 400 整批丢失问题。
 * 根因：dismissions 等接口返回的数字型 userId 经 json_decode 成为 int，混入
 * userIdList 请求体后钉钉网关报 400 MissingString，整批 50 人全部静默丢失。
 * 修复点：getRosterData 边界 strval 归一化 + 仅 400 时二分降级重试。
 * fetchRosterBatch 抽为 protected 以便注入响应序列。
 */
class DingtalkRosterBatchTest extends TestCase
{
    /** 构造注入响应序列的服务：responses 每项为 [httpCode, rawBody]；记录每次请求的 uid 列表 */
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

            protected function fetchRosterBatch(array $userIds, string $token): array
            {
                $this->calls[] = $userIds;
                [$code, $body] = $this->responses[$this->cursor++] ?? [200, '{}'];
                return [$code, $body, ''];
            }
        };
    }

    private static function rosterBody(array $entries): string
    {
        return json_encode(['result' => $entries], JSON_UNESCAPED_UNICODE);
    }

    public function test_int_uids_normalized_to_strings_before_request(): void
    {
        $svc = $this->service([[200, '{}']]);

        $svc->getRosterData([40596860951123, 'manager001', '023414690765868569672']);

        $calls = $svc->calls;
        $this->assertCount(1, $calls);
        $this->assertSame(
            ['40596860951123', 'manager001', '023414690765868569672'],
            $calls[0],
            'int uid 必须在请求边界归一化为 string，否则钉钉网关 400 MissingString'
        );
        foreach ($calls[0] as $u) {
            $this->assertIsString($u);
        }
    }

    public function test_batch_400_splits_into_halves_and_merges_results(): void
    {
        $svc = $this->service([
            [400, '{"code":"InvalidParameter","message":"String is mandatory for this action"}'],
            [200, self::rosterBody([['userId' => 'u1', 'fieldDataList' => [
                ['fieldName' => '月度薪资标准', 'fieldValueList' => [['value' => '5000']]],
            ]]])],
            [200, self::rosterBody([['userId' => 'u2', 'fieldDataList' => [
                ['fieldName' => '职位', 'fieldValueList' => [['label' => '项目经理']]],
            ]]])],
        ]);

        $result = $svc->getRosterData(['u1', 'u2']);

        $this->assertSame([['u1', 'u2'], ['u1'], ['u2']], $svc->calls, '批次 400 应二分为两个单吊请求');
        $this->assertSame('5000', $result['u1']['月度薪资标准']);
        $this->assertSame('项目经理', $result['u2']['职位']);
    }

    public function test_single_400_returns_empty_without_recursion(): void
    {
        $svc = $this->service([[400, '{"code":"InvalidParameter"}']]);

        $this->assertSame([], $svc->getRosterData(['bad-uid']));
        $this->assertCount(1, $svc->calls, '单人批次 400 不应继续递归');
    }

    public function test_non_400_errors_fail_fast_without_split(): void
    {
        $svc = $this->service([[401, '{"code":"InvalidAuthentication"}']]);

        $this->assertSame([], $svc->getRosterData(['u1', 'u2', 'u3']));
        $this->assertCount(1, $svc->calls, '401/403/429/5xx 属全局性错误，二分只会放大请求量');
    }

    public function test_curl_error_returns_empty(): void
    {
        $svc = $this->service([[0, false]]);

        $this->assertSame([], $svc->getRosterData(['u1']));
    }

    public function test_label_preferred_and_empty_label_falls_back_to_value(): void
    {
        $svc = $this->service([[200, self::rosterBody([['userId' => 'u1', 'fieldDataList' => [
            ['fieldName' => '职位', 'fieldValueList' => [['label' => '项目经理', 'value' => 'pm_code']]],
            ['fieldName' => '月度薪资标准', 'fieldValueList' => [['label' => '', 'value' => '5000']]],
            ['fieldName' => '空字段', 'fieldValueList' => [['label' => '', 'value' => '']]],
        ]]])]]);

        $result = $svc->getRosterData(['u1']);

        $this->assertSame('项目经理', $result['u1']['职位'], '非空 label 优先');
        $this->assertSame('5000', $result['u1']['月度薪资标准'], 'label 为空串时回退取 value（金额/日期类字段）');
        $this->assertArrayNotHasKey('空字段', $result['u1'], '全空字段不产生条目');
    }
}
