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

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            Log::warning("DingTalk API error [{$url}]: {$err}");
            return ['errcode' => -1, 'errmsg' => $err];
        }
        curl_close($ch);
        $resp = json_decode($raw, true);

        return $resp ?: ['errcode' => -1, 'errmsg' => '请求失败'];
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
        if ($raw === false) {
            Log::warning('DingTalk roster API error: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }
        curl_close($ch);
        $res = json_decode($raw, true);

        $result = [];
        foreach ($res['result'] ?? [] as $userInfo) {
            $uid = $userInfo['userId'];
            $fields = [];
            foreach ($userInfo['fieldDataList'] ?? [] as $field) {
                $name = $field['fieldName'];
                $val = $field['fieldValueList'][0]['label']
                    ?? $field['fieldValueList'][0]['value']
                    ?? '';
                if ($name && $val) $fields[$name] = $val;
            }
            $result[$uid] = $fields;
        }
        return $result;
    }

    public function getUserDetail(string $userId): ?array
    {
        $r = $this->api('https://oapi.dingtalk.com/topapi/v2/user/get', [
            'userid' => $userId,
            'language' => 'zh_CN',
        ]);
        return $r['result'] ?? null;
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
