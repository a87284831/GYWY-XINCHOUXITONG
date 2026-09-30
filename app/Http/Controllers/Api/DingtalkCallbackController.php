<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\DingtalkCrypto;
use App\Services\DingtalkCryptoException;
use App\Services\DingtalkService;

class DingtalkCallbackController extends ApiController
{
    public function __construct(private readonly DingtalkService $dt) {}

    public function status(): JsonResponse
    {
        $account = $this->requireAccount(request());
        if ($account instanceof JsonResponse) return $account;

        return response()->json(['ok' => true, 'data' => $this->dt->getSyncStatus()]);
    }

    public function getConfig(): JsonResponse
    {
        $account = $this->requireAccount(request());
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可操作'], 403);
        }

        $cfg = $this->dt->getConfig();
        if (!empty($cfg['app_secret'])) {
            $cfg['app_secret_masked'] = substr($cfg['app_secret'], 0, 6) . '****' . substr($cfg['app_secret'], -4);
            unset($cfg['app_secret']);
        }
        if (!empty($cfg['callback_aes_key'])) {
            // 43 字符 AES 密钥只展示首尾，避免完整泄露
            $cfg['callback_aes_key_masked'] = substr($cfg['callback_aes_key'], 0, 6) . '****' . substr($cfg['callback_aes_key'], -4);
            unset($cfg['callback_aes_key']);
        }
        return response()->json(['ok' => true, 'config' => $cfg]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可操作'], 403);
        }

        $allowed = ['app_key', 'app_secret', 'agent_id', 'callback_token', 'callback_aes_key'];
        $config = [];
        foreach ($allowed as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                $config[$key] = (string) $val;
            }
        }
        if (empty($config)) {
            return response()->json(['ok' => false, 'error' => '没有可保存的配置项'], 400);
        }

        $this->dt->saveConfig($config);
        return response()->json(['ok' => true, 'message' => '配置已保存']);
    }

    public function syncNow(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可操作'], 403);
        }

        if (!$this->dt->configured()) {
            return response()->json(['ok' => false, 'error' => '钉钉尚未配置 AppKey/AppSecret'], 400);
        }

        $lock = Cache::lock('dingtalk-sync', 600);
        if (!$lock->get()) {
            return response()->json(['ok' => false, 'error' => '同步任务正在执行中，请稍后再试'], 409);
        }

        try {
            $start = microtime(true);
            $report = $this->runFullSync();
            $elapsed = round(microtime(true) - $start, 1);

            $this->dt->saveConfig([
                'sync_status' => 'success',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_count' => (string) $report['dingtalk_users'],
            ]);

            return response()->json([
                'ok' => true,
                'message' => "同步完成，耗时 {$elapsed}s",
                'report' => $report,
                'elapsed' => $elapsed,
            ]);
        } catch (\Throwable $e) {
            $this->dt->saveConfig(['sync_status' => 'failed']);
            Log::error('钉钉同步失败: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'ok' => false,
                'error' => '同步失败: ' . $e->getMessage(),
            ], 500);
        } finally {
            $lock->release();
        }
    }

    /**
     * 钉钉回调入口（P0 安全修复 2026-09-30）。
     * 钉钉推送为 AES 加密报文（{"encrypt":...}），请求 query 携带签名；
     * 必须验签+解密后才分发事件，并以加密 success 应答（钉钉 1500ms 内校验）。
     * 验签失败/解密失败 → 403 拒绝并记日志；未配置回调密钥 → 503。
     */
    public function handle(Request $request): JsonResponse
    {
        $cfg = $this->dt->getConfig();
        $token = (string) ($cfg['callback_token'] ?? '');
        $aesKey = (string) ($cfg['callback_aes_key'] ?? '');
        $appKey = (string) ($cfg['app_key'] ?? '');
        if ($token === '' || $aesKey === '' || $appKey === '') {
            Log::warning('钉钉回调被拒绝: 回调配置不完整（需 callback_token/callback_aes_key/app_key）', ['ip' => $request->ip()]);
            return response()->json(['ok' => false, 'error' => 'callback not configured'], 503);
        }

        $signature = (string) ($request->query('msg_signature') ?? $request->query('signature') ?? '');
        $timestamp = (string) ($request->query('timestamp') ?? $request->query('timeStamp') ?? '');
        $nonce = (string) $request->query('nonce', '');
        $encrypt = (string) (json_decode($request->getContent(), true)['encrypt'] ?? '');
        if ($signature === '' || $timestamp === '' || $nonce === '' || $encrypt === '') {
            Log::warning('钉钉回调被拒绝: 缺少回调参数', ['ip' => $request->ip()]);
            return response()->json(['ok' => false, 'error' => 'missing callback params'], 400);
        }

        try {
            $crypto = new DingtalkCrypto($token, $aesKey, $appKey);
            $plain = $crypto->decryptMsg($signature, $timestamp, $nonce, $encrypt);
        } catch (DingtalkCryptoException $e) {
            Log::warning('钉钉回调被拒绝: ' . $e->getMessage(), ['code' => $e->getCode(), 'ip' => $request->ip()]);
            return response()->json(['ok' => false, 'error' => 'invalid callback'], 403);
        }

        $body = json_decode($plain, true) ?: [];
        $eventType = (string) ($body['EventType'] ?? '');

        try {
            switch ($eventType) {
                case 'check_url':
                    break; // URL 有效性验证，仅需加密 success 应答
                case 'user_add_org':
                case 'user_modify_org':
                    $this->handleUserChange($body);
                    break;
                case 'user_leave_org':
                    $this->handleUserLeave($body);
                    break;
                case 'org_dept_create':
                case 'org_dept_modify':
                case 'org_dept_remove':
                    Cache::forget('dingtalk_depts_tree'); // P1-4: 使部门树缓存失效
                    $this->handleDeptChange($body);
                    break;
                default:
                    Log::info('钉钉回调: 未订阅的事件类型', ['event' => $eventType]);
            }
        } catch (\Throwable $e) {
            // 业务处理失败不影响加密 success 应答；钉钉侧超时会重试，处理逻辑幂等
            Log::error('钉钉回调处理异常: ' . $e->getMessage(), ['event' => $eventType, 'trace' => $e->getTraceAsString()]);
        }

        return response()->json(json_decode($crypto->encryptMsg('success'), true));
    }

    // ─── 全量同步 ────────────────────────────────────────────

    public function runFullSync(): array
    {
        // P1-2: 先完成全部钉钉 API 拉取，再开事务写库；中途失败整体回滚，避免半同步状态。
        $stats = ['new' => 0, 'updated' => 0, 'skip' => 0, 'offboard' => 0, 'offboard_new' => 0, 'roster' => 0];
        $maxId = (int) DB::table('payroll_staff')->max('legacy_id');

        $depts = $this->dt->getAllDepartments();
        $users = $this->dt->getAllUsers($depts);
        $dismissed = $this->dt->getDismissedUsers();
        // dimInfos: uid => ['name','last_work_date','main_dept_id'] (real resignation data)
        $dimInfos = $this->dt->getDismissedUserInfos(array_keys($dismissed));

        DB::beginTransaction();
        try {
            $this->syncFullSyncInTransaction($stats, $maxId, $depts, $users, $dismissed, $dimInfos);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    private function syncFullSyncInTransaction(array &$stats, int $maxId, array $depts, array $users, array $dismissed, array $dimInfos): void
    {
        $changedUserIds = [];
        $newOffboardUids = [];

        $this->syncOrgTree($depts);

        foreach ($users as $uid => $u) {
            $result = $this->syncOneUser($uid, $u, $depts, $maxId);
            $stats[$result]++;
            if ($result !== 'skip') {
                $changedUserIds[$uid] = true;
            }
        }

        $today = date('Y-m-d');
        $resolveDimDept = function ($uid) use ($dimInfos, $depts) {
            $mdid = $dimInfos[$uid]['main_dept_id'] ?? null;
            if (!$mdid) return ['未分配项目', '', 0];
            $pathNames = $this->dt->getDeptPath($mdid, $depts);
            $orgLocalId = (int) (DB::table('org_nodes')->where('dingtalk_dept_id', (string) $mdid)->value('id') ?? 0);
            return [$pathNames[1] ?? '未分配项目', implode('/', $pathNames), $orgLocalId];
        };

        $existing = DB::table('payroll_staff')
            ->where('deleted', false)
            ->whereNotNull('dingtalk_userid')
            ->get(['id', 'name', 'dingtalk_userid', 'status']);

        $dtUserMap = array_flip(array_keys($users));

        $offboardUids = [];
        foreach ($existing as $row) {
            $uid = $row->dingtalk_userid;
            if (!isset($dtUserMap[$uid]) && isset($dismissed[$uid]) && $row->status !== '离职') {
                $realDate = $dimInfos[$uid]['last_work_date'] ?? null;
                $upd = [
                    'status' => '离职',
                    'resign_date' => $realDate,
                    'updated_at' => now(),
                ];
                $rname = $dimInfos[$uid]['name'] ?? '';
                if ($rname !== '' && $row->name === '待同步') $upd['name'] = $rname;
                [$proj, $deptPath, $orgLocalId] = $resolveDimDept($uid);
                if ($proj !== '未分配项目') {
                    $upd['project_name'] = $proj;
                    if ($deptPath) $upd['dept_path'] = $deptPath;
                    if ($orgLocalId) $upd['org_id'] = $orgLocalId;
                }
                DB::table('payroll_staff')->where('id', $row->id)->update($upd);
                $stats['offboard']++;
                $offboardUids[] = $uid;
            }
        }

        $existingUids = [];
        foreach ($existing as $row) {
            $existingUids[$row->dingtalk_userid] = true;
        }
        foreach ($dismissed as $uid => $_) {
            if (isset($existingUids[$uid]) || isset($dtUserMap[$uid])) continue;
            $maxId++;
            $info = $dimInfos[$uid] ?? [];
            $realName = $info['name'] ?? '';
            $realDate = $info['last_work_date'] ?? null;
            [$proj, $deptPath, $orgLocalId] = $resolveDimDept($uid);
            DB::table('payroll_staff')->insert([
                'legacy_id' => $maxId,
                'dingtalk_userid' => $uid,
                'name' => $realName !== '' ? $realName : '待同步',
                'project_name' => $proj,
                'dept_path' => $deptPath,
                'org_id' => $orgLocalId ?: null,
                'status' => '离职',
                'resign_date' => $realDate,
                'fixed_monthly' => 0,
                'base_salary' => 0,
                'deleted' => false,
                'is_manager' => false,
                'is_case_field' => false,
                'person_type' => 'staff',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stats['offboard_new']++;
            $newOffboardUids[] = $uid;
        }

        // 自愈修复：历史上因详情接口报错落成「待同步」占位的离职行，
        // 详情接口恢复正常后，重跑同步即补全姓名/离职日期/项目归属
        foreach ($existing as $row) {
            $uid = $row->dingtalk_userid;
            if ($row->name !== '待同步' || !isset($dimInfos[$uid])) continue;
            $info = $dimInfos[$uid];
            $upd = ['updated_at' => now()];
            if (($info['name'] ?? '') !== '') $upd['name'] = $info['name'];
            if (!empty($info['last_work_date'])) $upd['resign_date'] = $info['last_work_date'];
            [$proj, $deptPath, $orgLocalId] = $resolveDimDept($uid);
            if ($proj !== '未分配项目') {
                $upd['project_name'] = $proj;
                if ($deptPath) $upd['dept_path'] = $deptPath;
                if ($orgLocalId) $upd['org_id'] = $orgLocalId;
            }
            DB::table('payroll_staff')->where('id', $row->id)->update($upd);
            $stats['repair'] = ($stats['repair'] ?? 0) + 1;
        }

        // empLeaveRecords 兜底：dimissionInfos 查不到的离职人员（管理员删除/注销），
        // 走通讯录 empLeaveRecords 接口补全姓名+离职日期+手机号
        $stillPending = DB::table('payroll_staff')
            ->where('name', '待同步')->where('deleted', false)
            ->whereNotNull('dingtalk_userid')->where('dingtalk_userid', '!=', '')
            ->pluck('dingtalk_userid')->toArray();
        if (!empty($stillPending)) {
            // empLeaveRecords 的 startTime 距今不能超过 365 天（钉钉限制）
            // 从 365 天前开始拉取全量记录，用 uid 集合做命中过滤
            $pendingSet = array_flip($stillPending);
            $leaveRecords = [];
            // 从 365 天前开始，一次拉全量（分页 maxResults=50）
            $startIso = now()->subDays(360)->format('Y-m-d\T00:00:00\Z');
            $records = $this->dt->getLeaveRecords($startIso);
            foreach ($records as $uid => $rec) {
                if (isset($pendingSet[$uid])) {
                    $leaveRecords[$uid] = $rec;
                }
            }
            foreach ($stillPending as $uid) {
                if (!isset($leaveRecords[$uid])) continue;
                $rec = $leaveRecords[$uid];
                $upd = ['updated_at' => now()];
                if ($rec['name']) $upd['name'] = $rec['name'];
                if ($rec['leave_time']) $upd['resign_date'] = $rec['leave_time'];
                // 手机号写入 data JSON
                if ($rec['mobile']) {
                    $row = DB::table('payroll_staff')->where('dingtalk_userid', $uid)->first(['id', 'data']);
                    $dataArr = json_decode($row->data ?? '{}', true) ?: [];
                    $dataArr['mobile'] = $rec['mobile'];
                    $upd['data'] = json_encode($dataArr, JSON_UNESCAPED_UNICODE);
                }
                [$proj, $deptPath, $orgLocalId] = $resolveDimDept($uid);
                if ($proj !== '未分配项目') {
                    $upd['project_name'] = $proj;
                    if ($deptPath) $upd['dept_path'] = $deptPath;
                    if ($orgLocalId) $upd['org_id'] = $orgLocalId;
                }
                DB::table('payroll_staff')->where('dingtalk_userid', $uid)->where('deleted', false)->update($upd);
                $stats['leave_repair'] = ($stats['leave_repair'] ?? 0) + 1;
            }
        }

        $allActiveUids = DB::table('payroll_staff')
            ->where('deleted', false)->where('status', '!=', '离职')
            ->whereNotNull('dingtalk_userid')->where('dingtalk_userid', '!=', '')
            ->pluck('dingtalk_userid')->toArray();
        $rosterUids = array_values(array_unique(array_merge($allActiveUids, array_keys($changedUserIds), $newOffboardUids, $offboardUids)));
        if (!empty($rosterUids)) {
            $rosterStaff = DB::table('payroll_staff')
                ->whereIn('dingtalk_userid', $rosterUids)
                ->get(['id', 'dingtalk_userid']);
            $idMap = array_column($rosterStaff->all(), 'id', 'dingtalk_userid');

            foreach (array_chunk($rosterUids, 50) as $batch) {
                $roster = $this->dt->getRosterData($batch);
                foreach ($roster as $uid => $fields) {
                    $dbId = $idMap[$uid] ?? null;
                    if (!$dbId) continue;
                    $this->applyRosterFields($dbId, $fields);
                    $stats['roster']++;
                }
                usleep(100000);
            }
        }

        // (dimission info already applied at insert time via \$dimInfos above)

        $stats['dingtalk_users'] = count($users);
        $stats['dingtalk_depts'] = count($depts);
        $stats['dingtalk_dismissed'] = count($dismissed);
    }

    // ─── 组织树同步 ──────────────────────────────────────────

    private function syncOrgTree(array $depts): void
    {
        $dtNodeMap = [];
        foreach (DB::table('org_nodes')->whereNotNull('dingtalk_dept_id')->get() as $row) {
            $dtNodeMap[$row->dingtalk_dept_id] = ['id' => $row->id, 'name' => $row->name, 'parent_id' => $row->parent_id];
        }

        if (!isset($dtNodeMap[399655832])) {
            $id = DB::table('org_nodes')->insertGetId([
                'parent_id' => null,
                'type' => 'company',
                'name' => '万城服务',
                'dingtalk_dept_id' => '399655832',
                'sort_order' => 0,
                'enabled' => true,
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $dtNodeMap[399655832] = ['id' => $id, 'name' => '万城服务', 'parent_id' => null];
        }

        uasort($depts, fn($a, $b) => $a['level'] <=> $b['level']);
        foreach ($depts as $dtId => $d) {
            $parentDtId = $d['parent_id'];
            if (!isset($dtNodeMap[$parentDtId])) continue;

            $parentId = $dtNodeMap[$parentDtId]['id'];
            $type = match ($d['level']) {
                2 => 'project',
                default => 'department',
            };

            if (isset($dtNodeMap[$dtId])) {
                $node = $dtNodeMap[$dtId];
                $needsUpdate = $node['name'] !== $d['name'] || (int) $node['parent_id'] !== (int) $parentId;
                if ($needsUpdate) {
                    DB::table('org_nodes')->where('id', $node['id'])->update([
                        'name' => $d['name'],
                        'parent_id' => $parentId,
                        'updated_at' => now(),
                    ]);
                    $dtNodeMap[$dtId]['name'] = $d['name'];
                    $dtNodeMap[$dtId]['parent_id'] = $parentId;
                }
                continue;
            }

            $id = DB::table('org_nodes')->insertGetId([
                'parent_id' => $parentId,
                'type' => $type,
                'name' => $d['name'],
                'dingtalk_dept_id' => (string) $dtId,
                'sort_order' => 0,
                'enabled' => true,
                'hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $dtNodeMap[$dtId] = ['id' => $id, 'name' => $d['name'], 'parent_id' => $parentId];
        }
    }

    // ─── 共享辅助方法 ────────────────────────────────────────

    private function parseHireDate(mixed $raw): ?string
    {
        $raw = (int) ($raw ?: 0);
        $ts = intdiv($raw, 1000);
        return ($raw > 0 && $ts > 946684800) ? date('Y-m-d', $ts) : null;
    }

    private function resolveDeptContext(int $mainDeptId, array $depts): array
    {
        $pathNames = $mainDeptId ? $this->dt->getDeptPath($mainDeptId, $depts) : [];
        $orgLocalId = $mainDeptId ? (int) (DB::table('org_nodes')
            ->where('dingtalk_dept_id', (string) $mainDeptId)
            ->value('id') ?? 0) : 0;
        $pathStr = implode('/', $pathNames);
        return [
            'pathNames' => $pathNames,
            'pathStr' => $pathStr,
            'projectName' => $pathNames[1] ?? '未分配项目',
            'orgLocalId' => $orgLocalId,
            'isCase' => strpos($pathStr, '案场') !== false,
        ];
    }

    // ─── 单人员同步 ──────────────────────────────────────────

    private function syncOneUser(string $uid, array $u, array $depts, int &$maxId): string
    {
        $deptIds = $u['_dept_ids'] ?? [];
        if (empty($deptIds)) return 'skip';

        $ctx = $this->resolveDeptContext($deptIds[0], $depts);
        $hireDate = $this->parseHireDate($u['hired_date'] ?? 0);
        $status = !empty($u['actual_confirm_date']) ? '正式' : '试用';
        $name = $u['name'] ?? '';
        $title = $u['title'] ?? '';
        $deptPath = $ctx['pathStr'];
        $now = now();

        $existing = DB::table('payroll_staff')
            ->where('dingtalk_userid', $uid)
            ->first(['id', 'person_type', 'fixed_monthly', 'base_salary', 'regular_date',
                     'name', 'project_name', 'position', 'org_id', 'dept_path', 'hire_date', 'status']);

        if ($existing) {
            if ($existing->status === '离职') {
                DB::table('payroll_staff')->where('id', $existing->id)->update([
                    'status' => $status, 'resign_date' => null, 'updated_at' => $now,
                ]);
                return 'updated';
            }

            $changed = false;
            $updates = ['updated_at' => $now];
            $fields = [
                'name' => $name, 'project_name' => $ctx['projectName'], 'position' => $title,
                'org_id' => $ctx['orgLocalId'] ?: null, 'dept_path' => $deptPath,
                'status' => $status, 'resign_date' => null,
            ];
            if ($hireDate) $fields['hire_date'] = $hireDate;
            foreach ($fields as $k => $v) {
                if ((string) ($existing->$k ?? '') !== (string) $v) {
                    $changed = true;
                    $updates[$k] = $v;
                }
            }
            // mobile 不在主表列，存 data JSON；getUserDetail 的 mobile 字段更新
            $mobile = $u['mobile'] ?? '';
            if ($mobile) {
                $row = DB::table('payroll_staff')->where('id', $existing->id)->first(['data']);
                $dataArr = json_decode($row->data ?? '{}', true) ?: [];
                if (($dataArr['mobile'] ?? '') !== $mobile) {
                    $dataArr['mobile'] = $mobile;
                    $updates['data'] = json_encode($dataArr, JSON_UNESCAPED_UNICODE);
                    $changed = true;
                }
            }
            if (!$changed) return 'skip';
            DB::table('payroll_staff')->where('id', $existing->id)->update($updates);
            return 'updated';
        }

        $sameName = DB::table('payroll_staff')
            ->where('name', $name)->where('project_name', $ctx['projectName'])
            ->where('deleted', false)->first(['id']);

        if ($sameName) {
            DB::table('payroll_staff')->where('id', $sameName->id)->update([
                'dingtalk_userid' => $uid, 'org_id' => $ctx['orgLocalId'] ?: null,
                'dept_path' => $deptPath, 'status' => $status,
                'resign_date' => null, 'updated_at' => $now,
            ]);
            return 'updated';
        }

        $maxId++;
        DB::table('payroll_staff')->insert([
            'legacy_id' => $maxId, 'dingtalk_userid' => $uid,
            'name' => $name, 'project_name' => $ctx['projectName'],
            'position' => $title, 'status' => $status,
            'fixed_monthly' => 0, 'base_salary' => 0, 'hire_date' => $hireDate,
            'org_id' => $ctx['orgLocalId'] ?: null, 'dept_path' => $deptPath,
            'is_manager' => false, 'is_case_field' => $ctx['isCase'],
            'person_type' => $ctx['isCase'] ? 'case' : 'staff',
            'deleted' => false,
            'data' => json_encode(['mobile' => $u['mobile'] ?? ''], JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return 'new';
    }

    // ─── 花名册字段映射 ──────────────────────────────────────

    private function applyRosterFields(int $dbId, array $fields): void
    {
        $dataMap = [
            '性别' => 'gender', '证件号码' => 'id_card', '学历' => 'education',
            '银行卡号' => 'bank_card', '开户行' => 'bank_name', '政治面貌' => 'politics',
            '所学专业' => 'major', '毕业院校' => 'school', '民族' => 'nation',
            '婚姻状况' => 'marital', '住址' => 'home_addr', '紧急联系人' => 'emergency_contact',
            '紧急联系人电话' => 'emergency_phone', '联系人电话' => 'emergency_phone_alt',
            '手机号' => 'mobile', '招聘渠道' => 'recruit_channel', '籍贯' => 'hometown',
            // 薪酬档位：钉钉花名册单选（专员级/主管级/经理级），字段名需与钉钉逐字一致
            '薪酬档位' => 'pay_grade',
            // 补充花名册字段（2026-09-28）：人员档案所有字段均以钉钉同步为准，本地不再编辑
            '出生日期' => 'birth_date', '层级' => 'level',
            '劳动合同开始日期' => 'contract_start', '劳动合同结束日期' => 'contract_end',
            '毕业时间' => 'grad_date', '资格证书' => 'certificate',
        ];

        $systemMap = [
            '职位' => 'position', '实际转正日期' => 'regular_date', '入职时间' => 'hire_date',
            '月度薪资标准' => 'fixed_monthly', '月度基本工资' => 'base_salary', '离职日期' => 'resign_date',
        ];

        $dataUpdate = [];
        foreach ($dataMap as $dtField => $dbField) {
            if (!empty($fields[$dtField])) {
                $dataUpdate[$dbField] = $fields[$dtField];
            }
        }

        if ($dataUpdate) {
            $row = DB::table('payroll_staff')->where('id', $dbId)->first(['data']);
            $existing = json_decode($row->data ?? '{}', true) ?: [];
            $merged = array_merge($existing, $dataUpdate);
            DB::table('payroll_staff')->where('id', $dbId)->update([
                'data' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        $systemUpdate = [];
        foreach ($systemMap as $dtField => $dbField) {
            if (!empty($fields[$dtField])) {
                $systemUpdate[$dbField] = $fields[$dtField];
            }
        }

        if (isset($systemUpdate['resign_date'])) {
            $v = trim((string)$systemUpdate['resign_date']);
            if (ctype_digit($v) && strlen($v) >= 10) {
                $sec = (int)($v > 9999999999 ? $v / 1000 : $v);
                // 钉钉时间戳为 UTC 毫秒，须按 Asia/Shanghai 转日期（PHP 默认 UTC 会早一天）
                $d = new \DateTime('@' . $sec, new \DateTimeZone('Asia/Shanghai'));
                $systemUpdate['resign_date'] = $d->format('Y-m-d');
            } else {
                // ISO 8601 日期字符串同样按 PRC 解析截取
                $d = new \DateTime($v, new \DateTimeZone('Asia/Shanghai'));
                $systemUpdate['resign_date'] = $d->format('Y-m-d');
            }
        }

        if (!empty($fields['实际转正日期'])) {
            $systemUpdate['status'] = '正式';
        }
        if (!empty($fields['员工状态'])) {
            $es = trim($fields['员工状态']);
            if ($es === '正式' || $es === '试用') $systemUpdate['status'] = $es;
        }

        if (!empty($fields['岗位职级'])) {
            $systemUpdate['person_type'] = match ($fields['岗位职级']) {
                '管理人员' => 'manager',
                '案场人员' => 'case',
                '总部人员' => 'hq',
                default => 'staff',
            };
        }

        if ($systemUpdate) {
            $currentStatus = DB::table('payroll_staff')->where('id', $dbId)->value('status');
            if ($currentStatus === '离职') {
                unset($systemUpdate['status']);
            }
            if (!empty($systemUpdate)) {
                $systemUpdate['updated_at'] = now();
                DB::table('payroll_staff')->where('id', $dbId)->update($systemUpdate);
            }
        }
    }

    // ─── 回调事件处理 ────────────────────────────────────────

    private function handleUserChange(array $body): void
    {
        // 官方协议 user_add_org/user_modify_org 的 UserId 为数组（单事件多人）
        $userIds = $body['UserId'] ?? $body['userid'] ?? [];
        $userIds = is_array($userIds) ? $userIds : [$userIds];
        foreach ($userIds as $userId) {
            if ((string) $userId === '') continue;
            $this->syncOneUserByCallback((string) $userId);
        }
    }

    private function syncOneUserByCallback(string $userId): void
    {
        $detail = $this->dt->getUserDetail($userId);
        if (!$detail) return;

        // P1-4: 部门树缓存 30 分钟；部门变更回调时已 forget，全量同步不走缓存
        $depts = Cache::remember('dingtalk_depts_tree', 1800, fn () => $this->dt->getAllDepartments());
        $deptIds = $detail['dept_id_list'] ?? [];
        $ctx = $this->resolveDeptContext($deptIds[0] ?? 0, $depts);
        $hireDate = $this->parseHireDate($detail['hired_date'] ?? 0);
        $status = !empty($detail['actual_confirm_date']) ? '正式' : '试用';
        $name = $detail['name'] ?? '';
        $title = $detail['title'] ?? '';
        $deptPath = $ctx['pathStr'];
        $now = now();

        $updateData = [
            'name' => $name, 'project_name' => $ctx['projectName'],
            'position' => $title, 'org_id' => $ctx['orgLocalId'] ?: null,
            'dept_path' => $deptPath, 'status' => $status,
            'resign_date' => null, 'is_case_field' => $ctx['isCase'],
            'updated_at' => $now,
        ];

        $existing = DB::table('payroll_staff')
            ->where('dingtalk_userid', $userId)->where('deleted', false)
            ->first(['id', 'status']);

        if ($existing) {
            if ($existing->status === '离职') {
                unset($updateData['status'], $updateData['resign_date']);
            }
            DB::table('payroll_staff')->where('id', $existing->id)->update($updateData);
        } else {
            $sameName = DB::table('payroll_staff')
                ->where('name', $name)->where('project_name', $ctx['projectName'])
                ->where('deleted', false)->first(['id']);

            if ($sameName) {
                $updateData['dingtalk_userid'] = $userId;
                DB::table('payroll_staff')->where('id', $sameName->id)->update($updateData);
            } else {
                $maxId = (int) DB::table('payroll_staff')->max('legacy_id');
                DB::table('payroll_staff')->insert(array_merge($updateData, [
                    'legacy_id' => $maxId + 1, 'dingtalk_userid' => $userId,
                    'fixed_monthly' => 0, 'base_salary' => 0, 'hire_date' => $hireDate,
                    'is_manager' => false,
                    'person_type' => $ctx['isCase'] ? 'case' : 'staff',
                    'deleted' => false,
                    'data' => json_encode(['mobile' => $detail['mobile'] ?? ''], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                ]));
            }
        }

        $this->log("回调更新人员: {$name}", ['userid' => $userId]);
    }

    private function handleUserLeave(array $body): void
    {
        // 官方协议 user_leave_org 的 UserId 为数组
        $userIds = $body['UserId'] ?? $body['userid'] ?? [];
        $userIds = is_array($userIds) ? $userIds : [$userIds];
        foreach ($userIds as $userId) {
            if ((string) $userId === '') continue;
            $this->markUserLeft((string) $userId);
        }
    }

    private function markUserLeft(string $userId): void
    {
        $existing = DB::table('payroll_staff')
            ->where('dingtalk_userid', $userId)
            ->where('deleted', false)
            ->first(['id', 'name']);

        if ($existing) {
            DB::table('payroll_staff')->where('id', $existing->id)->update([
                'status' => '离职',
                'resign_date' => date('Y-m-d'),
                'updated_at' => now(),
            ]);
            $this->log("回调人员离职: {$existing->name}", ['userid' => $userId]);
        }
    }

    private function handleDeptChange(array $body): void
    {
        $this->log('部门变更事件，将在下次全量同步时更新', ['event' => $body['EventType'] ?? '']);
    }

    // ─── 日志 ────────────────────────────────────────────────

    private function log(string $message, array $data = []): void
    {
        Log::info("钉钉同步: {$message}", $data);
    }
}
