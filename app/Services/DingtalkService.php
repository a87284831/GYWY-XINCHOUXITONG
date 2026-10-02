<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DingtalkService
{
    private string $appKey = '';
    private string $appSecret = '';
    private int $agentId = 0;
    private ?string $token = null;

    public function __construct()
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $cfg = [];
        try {
            foreach (DB::table('sys_dingtalk_config')->get() as $row) {
                $cfg[$row->config_key] = $row->config_value;
            }
        } catch (\Throwable $e) {
            return;
        }
        $this->appKey = $cfg['app_key'] ?? '';
        $this->appSecret = $cfg['app_secret'] ?? '';
        $this->agentId = (int) ($cfg['agent_id'] ?? 0);
    }

    public function configured(): bool
    {
        return $this->appKey !== '' && $this->appSecret !== '';
    }

    public function getAgentId(): int
    {
        return $this->agentId;
    }

    protected function tokenCachePath(): string
    {
        return storage_path('app/dt_token.cache');
    }

    /**
     * 取 accessToken：内存 → 文件缓存 → 远程获取（失败自动重试 1 次，P1-3）。
     */
    public function getAccessToken(): ?string
    {
        if ($this->token && strlen($this->token) > 10) return $this->token;

        $cacheFile = $this->tokenCachePath();
        if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 6000) {
            $cached = trim(file_get_contents($cacheFile));
            if (strlen($cached) > 10) {
                $this->token = $cached;
                return $this->token;
            }
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $token = $this->fetchAccessToken();
            if ($token) {
                $this->token = $token;
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                @file_put_contents($cacheFile, $this->token);
                return $this->token;
            }
            if ($attempt === 1) usleep(300000);
        }
        return null;
    }

    /** 单次远程获取 token（网络失败或响应无效返回 null），protected 以便测试注入 */
    protected function fetchAccessToken(): ?string
    {
        $ch = curl_init('https://api.dingtalk.com/v1.0/oauth2/accessToken');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'appKey' => $this->appKey,
                'appSecret' => $this->appSecret,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            Log::warning('DingTalk token request failed: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $resp = json_decode($raw, true);

        if (empty($resp['accessToken'])) return null;

        return $resp['accessToken'];
    }

    public function api(string $url, array $body = []): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['errcode' => -1, 'errmsg' => '获取token失败'];

        $isNewApi = str_starts_with($url, 'https://api.dingtalk.com');
        $fullUrl = $isNewApi ? $url : $url . '?access_token=' . $token;

        $headers = ['Content-Type: application/json'];
        if ($isNewApi) {
            $headers[] = 'x-acs-dingtalk-access-token: ' . $token;
        }

        // 钉钉共享 QPS 限流（90002/qps：整点/半点所有企业应用扎堆触发，平台级秒级
        // 窗口，1 秒即解除）自动退避重试，避免手动"立即同步"在拉部门第一步就整场失败
        $resp = ['errcode' => -1, 'errmsg' => '请求失败'];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$raw, $err] = $this->sendApiRequest($fullUrl, $headers, $body);
            if ($raw === false) {
                Log::warning("DingTalk API error [{$url}]: {$err}");
                return ['errcode' => -1, 'errmsg' => $err];
            }
            $resp = json_decode($raw, true) ?: ['errcode' => -1, 'errmsg' => '请求失败'];
            $rateLimited = (int) ($resp['errcode'] ?? 0) === 90002
                || (int) ($resp['subcode'] ?? 0) === 90002
                || str_contains((string) ($resp['errmsg'] ?? ''), 'qps');
            if (!$rateLimited || $attempt === 3) return $resp;
            $wait = $this->rateLimitBackoff($attempt);
            Log::warning("DingTalk API 共享QPS限流，退避 {$wait}s 后重试 " . ($attempt + 1) . "/3 [{$url}]: " . substr((string) ($resp['errmsg'] ?? ''), 0, 200));
            sleep($wait);
        }
        return $resp;
    }

    /** 限流退避秒数（attempt 从 1 起），protected 以便测试覆写为 0 */
    protected function rateLimitBackoff(int $attempt): int
    {
        return $attempt * 5;
    }

    /**
     * 单次 oapi/api 请求。protected 以便测试注入响应序列。
     * @return array{0:string|false,1:string} [rawBody, curlError]；curl 失败时 rawBody 为 false
     */
    protected function sendApiRequest(string $fullUrl, array $headers, array $body): array
    {
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $err = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        return [$raw, $err];
    }

    public function getAllDepartments(): array
    {
        $depts = [];
        $walk = function (int $parentId, int $level) use (&$walk, &$depts) {
            $r = $this->api('https://oapi.dingtalk.com/topapi/v2/department/listsub', [
                'dept_id' => $parentId,
                'language' => 'zh_CN',
            ]);
            if (isset($r['errcode']) && (int) $r['errcode'] !== 0) {
                throw new \RuntimeException('获取部门失败: ' . ($r['errmsg'] ?? '未知错误'));
            }
            foreach ($r['result'] ?? [] as $d) {
                $depts[$d['dept_id']] = [
                    'name' => $d['name'],
                    'parent_id' => $d['parent_id'],
                    'level' => $level,
                ];
                $walk($d['dept_id'], $level + 1);
            }
        };
        $walk(1, 1);
        return $depts;
    }

    public function getAllUsers(array $depts): array
    {
        $users = [];
        foreach ($depts as $dtId => $d) {
            $cursor = 0;
            do {
                $r = $this->api('https://oapi.dingtalk.com/topapi/v2/user/list', [
                    'dept_id' => $dtId,
                    'cursor' => $cursor,
                    'size' => 100,
                    'language' => 'zh_CN',
                ]);
                if (isset($r['errcode']) && (int) $r['errcode'] !== 0) {
                    throw new \RuntimeException("获取部门{$dtId}人员失败: " . ($r['errmsg'] ?? '未知错误'));
                }
                foreach ($r['result']['list'] ?? [] as $u) {
                    if (!isset($users[$u['userid']])) {
                        $users[$u['userid']] = $u;
                        $users[$u['userid']]['_dept_ids'] = $u['dept_id_list'] ?? [];
                    }
                }
                $cursor = $r['result']['next_cursor'] ?? 0;
                $hasMore = $r['result']['has_more'] ?? false;
            } while ($hasMore);
            usleep(100000);
        }
        return $users;
    }

    public function getDismissedUsers(): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $map = [];
        $nextToken = 0;
        $maxIter = 200;
        do {
            $ch = curl_init('https://api.dingtalk.com/v1.0/hrm/employees/dismissions?nextToken=' . $nextToken . '&maxResults=50');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['x-acs-dingtalk-access-token: ' . $token],
                CURLOPT_TIMEOUT => 30,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                Log::warning('DingTalk dismissed API error: ' . curl_error($ch));
                curl_close($ch);
                break;
            }
            curl_close($ch);
            $r = json_decode($raw, true);
            foreach ($r['userIdList'] ?? [] as $uid) {
                $map[$uid] = true;
            }
            $nextToken = $r['nextToken'] ?? 0;
            $hasMore = $r['hasMore'] ?? false;
        } while ($hasMore && --$maxIter > 0);
        return $map;
    }

    /**
     * Fetch full dimission info (name, real last-work date, main dept) for a set
     * of dismissed userIds via /v1.0/hrm/employees/dimissionInfos (max 50 per call).
     *
     * @return array uid => ['name'=>string, 'last_work_date'=>?string, 'main_dept_id'=>?int]
     */
    public function getDismissedUserInfos(array $userIds): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $out = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 50) as $batch) {
            // 官方文档要求：数组元素需为 JSON 引号字符串（双重编码），如 ["\"uid1\"","\"uid2\""]，
            // 普通编码 ["uid1","uid2"] 会被网关报 MissinguserIdList/JSON parsing error
            $encoded = json_encode(
                array_map(fn($u) => json_encode($u, JSON_UNESCAPED_UNICODE), $batch),
                JSON_UNESCAPED_UNICODE
            );
            $url = 'https://api.dingtalk.com/v1.0/hrm/employees/dimissionInfos?userIdList=' . urlencode($encoded);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'x-acs-dingtalk-access-token: ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                Log::warning('DingTalk dimissionInfos API error: ' . curl_error($ch));
                curl_close($ch);
                continue;
            }
            curl_close($ch);
            $r = json_decode($raw, true);
            foreach ($r['result'] ?? [] as $item) {
                $uid = $item['userId'] ?? '';
                if ($uid === '') continue;
                $lwd = $item['lastWorkDay'] ?? null;
                $lastWorkDate = null;
                if ($lwd) {
                    $ts = (int) $lwd;
                    if ($ts > 9999999999) $ts = intdiv($ts, 1000);
                    $lastWorkDate = date('Y-m-d', $ts);
                }
                $out[$uid] = [
                    'name' => (string) ($item['name'] ?? ''),
                    'last_work_date' => $lastWorkDate,
                    'main_dept_id' => isset($item['mainDeptId']) ? (int) $item['mainDeptId'] : null,
                ];
            }
            usleep(100000);
        }
        return $out;
    }

    public function getRosterData(array $userIds): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        // 钉钉要求 userIdList 元素必须是 JSON 字符串。dismissions 等接口返回的数字型
        // userId 经 json_decode 成为 int（且纯数字字符串做 PHP 数组键也会被自动转 int），
        // int 混入请求体会被网关拒绝：HTTP 400 MissingString "String is mandatory"，整批 50 人全部失败。
        // 在 API 边界统一 strval 归一化，杜绝类型泄漏。
        $nonString = array_filter($userIds, fn($u) => !is_string($u));
        if (!empty($nonString)) {
            Log::warning('钉钉花名册请求发现非 string 类型 userid（已自动归一化），来源需排查', [
                'uids' => array_map(fn($u) => var_export($u, true), array_values($nonString)),
            ]);
        }
        $userIds = array_values(array_map('strval', $userIds));

        [$httpCode, $raw, $curlErr] = $this->fetchRosterBatch($userIds, $token);
        if ($raw === false) {
            Log::warning('DingTalk roster API error: ' . $curlErr);
            return [];
        }
        if ($httpCode >= 400) {
            // 新版 API 失败时 body 是 {code,message} 且无 result 字段，必须显式记录，否则静默丢整批。
            // 仅 400（请求格式非法，如异常 userid）才二分降级：定位坏 uid 同时保住同批其余人员；
            // 401/403/429/5xx 属全局性错误，二分只会成倍放大请求量，快速失败交给上层重试
            if ($httpCode === 400 && count($userIds) > 1) {
                Log::warning("DingTalk roster API HTTP 400，批次(" . count($userIds) . "人)降级二分重试: " . substr((string) $raw, 0, 300));
                $mid = intdiv(count($userIds), 2);
                return $this->getRosterData(array_slice($userIds, 0, $mid))
                    + $this->getRosterData(array_slice($userIds, $mid));
            }
            Log::warning("DingTalk roster API 拒绝 userid 批次/单个 HTTP {$httpCode}: " . substr((string) $raw, 0, 300));
            return [];
        }
        $res = json_decode($raw, true);

        $result = [];
        foreach ($res['result'] ?? [] as $userInfo) {
            $uid = $userInfo['userId'];
            $fields = [];
            foreach ($userInfo['fieldDataList'] ?? [] as $field) {
                $name = $field['fieldName'];
                // label 非空字符串才优先取（?? 只跳过 null；数值型自定义字段 label 常为空串，
                // 若被采用将把金额/日期整字段丢掉），空串/null 回退 value
                $val = $field['fieldValueList'][0]['label'] ?? null;
                if ($val === null || $val === '') {
                    $val = $field['fieldValueList'][0]['value'] ?? '';
                }
                if ($name && $val) $fields[$name] = $val;
            }
            $result[(string) $uid] = $fields;
        }
        return $result;
    }

    /**
     * 单次花名册批次请求（≤50 人）。protected 以便测试注入响应序列。
     * @return array{0:int,1:string|false,2:string} [httpCode, rawBody, curlError]；curl 失败时 rawBody 为 false
     */
    protected function fetchRosterBatch(array $userIds, string $token): array
    {
        $ch = curl_init('https://api.dingtalk.com/v1.0/hrm/rosters/lists/query');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-acs-dingtalk-access-token: ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'userIdList' => $userIds,
                'appAgentId' => $this->agentId,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        return [$httpCode, $raw, $curlErr];
    }

    public function getUserDetail(string $userId): ?array
    {
        $r = $this->api('https://oapi.dingtalk.com/topapi/v2/user/get', [
            'userid' => $userId,
            'language' => 'zh_CN',
        ]);
        return $r['result'] ?? null;
    }

    /**
     * 查询离职记录列表（通讯录接口，覆盖管理员删除/注销/主动离职等全类型）
     * 含 name、leaveTime（ISO 8601）、mobile。dimissionInfos 查不到的离职人员可从这里兜底。
     * 注意：startTime 距今不能超过 365 天（钉钉限制），endTime 参数会触发 400 故不传。
     * @return array<string,array{ name:string, leave_time:string, mobile:string }>
     */
    public function getLeaveRecords(string $startIso): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];

        $out = [];
        $next = '0';
        do {
            $url = 'https://api.dingtalk.com/v1.0/contact/empLeaveRecords?startTime=' . urlencode($startIso)
                . '&nextToken=' . urlencode($next) . '&maxResults=50';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['x-acs-dingtalk-access-token: ' . $token],
                CURLOPT_TIMEOUT => 30,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $res = json_decode((string)$raw, true);
            foreach ($res['records'] ?? [] as $rec) {
                $uid = $rec['userId'] ?? '';
                if (!$uid) continue;
                $lt = $rec['leaveTime'] ?? '';
                // leaveTime 是 UTC ISO，转 PRC 日期
                try {
                    $d = new \DateTime($lt, new \DateTimeZone('Asia/Shanghai'));
                    $date = $d->format('Y-m-d');
                } catch (\Throwable) {
                    $date = substr($lt, 0, 10);
                }
                $out[$uid] = [
                    'name' => $rec['name'] ?? '',
                    'leave_time' => $date,
                    'mobile' => $rec['mobile'] ?? '',
                ];
            }
            $next = $res['nextToken'] ?? '';
            usleep(100000);
        } while ($next !== '' && $next !== null);

        return $out;
    }

    public function getDeptPath(int $deptId, array $depts): array
    {
        $names = [];
        $cur = $deptId;
        $guard = 0;
        while (isset($depts[$cur]) && $guard < 20) {
            array_unshift($names, $depts[$cur]['name']);
            $cur = $depts[$cur]['parent_id'];
            $guard++;
        }
        return $names;
    }

    public function saveConfig(array $config): void
    {
        $allowed = [
            'app_key', 'app_secret', 'agent_id',
            // 钉钉后台「事件订阅」配置的 Token 与数据加密密钥（AES），P0 回调验签解密所需
            'callback_token', 'callback_aes_key',
            'sync_status', 'last_sync_at', 'last_sync_count',
        ];
        foreach ($config as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            DB::table('sys_dingtalk_config')->updateOrInsert(
                ['config_key' => $key],
                ['config_value' => $value, 'updated_at' => now()]
            );
        }
        $this->loadConfig();
        @unlink($this->tokenCachePath());
    }

    public function getConfig(): array
    {
        $cfg = [];
        foreach (DB::table('sys_dingtalk_config')->get() as $row) {
            $cfg[$row->config_key] = $row->config_value;
        }
        return $cfg;
    }

    public function getSyncStatus(): array
    {
        $cfg = $this->getConfig();
        $staffTotal = DB::table('payroll_staff')->where('deleted', false)->count();
        $staffActive = DB::table('payroll_staff')->where('deleted', false)->where('status', '!=', '离职')->count();
        $staffResigned = DB::table('payroll_staff')->where('deleted', false)->where('status', '离职')->count();
        $staffBound = DB::table('payroll_staff')->where('deleted', false)->whereNotNull('dingtalk_userid')->count();
        $orgTotal = DB::table('org_nodes')->count();
        $orgBound = DB::table('org_nodes')->whereNotNull('dingtalk_dept_id')->count();

        return [
            'configured' => $this->configured(),
            'last_sync_at' => $cfg['last_sync_at'] ?? null,
            'last_sync_count' => (int) ($cfg['last_sync_count'] ?? 0),
            'last_sync_status' => $cfg['sync_status'] ?? 'never',
            'staff_total' => $staffTotal,
            'staff_active' => $staffActive,
            'staff_resigned' => $staffResigned,
            'staff_bound' => $staffBound,
            'org_total' => $orgTotal,
            'org_bound' => $orgBound,
        ];
    }
}
