<?php

namespace App\Http\Controllers\Api;

use App\Services\ApprovalActions;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 通用审批流引擎：表单设计器 + 审批流配置 + 审批中心运行。
 *
 * 配置层：approval_flows（form_schema=表单字段，flow_config=节点+条件分支）
 * 运行层：approval_instances（发起时按条件分支解析出 node_chain，逐节点审批）
 * 联动层：通过后按 flow_key 触发 ApprovalActions（录用→入职清单 / 转正→更新档案 / 离职→停账号）
 */
class ApprovalFlowController extends ApiController
{
    // ---------------- 配置层：流程定义 CRUD（仅管理员） ----------------

    /** 花名册岗位列表（供审批节点配置"指定岗位"使用） */
    public function positions(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $rows = DB::table('payroll_staff')->where('deleted', 0)
            ->whereNotNull('position')->where('position', '<>', '')
            ->selectRaw('position, count(*) cnt')
            ->groupBy('position')->orderByDesc('cnt')->get();
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['position' => $r->position, 'count' => (int) $r->cnt];
        }
        return response()->json(['ok' => true, 'positions' => $list]);
    }

    public function flows(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可管理审批流程'], 403);
        }
        $flows = DB::table('approval_flows')->orderBy('id')->get()->map(fn ($f) => [
            'id' => $f->id, 'flow_key' => $f->flow_key, 'name' => $f->name,
            'form_schema' => $this->jsonValue($f->form_schema),
            'flow_config' => $this->jsonValue($f->flow_config),
            'enabled' => (bool) $f->enabled,
            'instance_count' => DB::table('approval_instances')->where('flow_key', $f->flow_key)->count(),
        ]);
        return response()->json(['ok' => true, 'flows' => $flows]);
    }

    public function flowSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可管理审批流程'], 403);
        }
        $id = (int) $request->input('id');
        $flowKey = trim((string) $request->input('flow_key'));
        $name = trim((string) $request->input('name'));
        $formSchema = $request->input('form_schema');
        $flowConfig = $request->input('flow_config');
        if ($flowKey === '' || $name === '' || !is_array($formSchema) || !is_array($flowConfig)) {
            return response()->json(['ok' => false, 'error' => '参数不完整'], 400);
        }
        $existing = DB::table('approval_flows')->where('flow_key', $flowKey)->first();
        if ($existing && $id !== (int) $existing->id) {
            return response()->json(['ok' => false, 'error' => '流程标识已存在'], 409);
        }
        $payload = [
            'name' => mb_substr($name, 0, 110),
            'form_schema' => json_encode($formSchema, JSON_UNESCAPED_UNICODE),
            'flow_config' => json_encode($flowConfig, JSON_UNESCAPED_UNICODE),
            'enabled' => $request->boolean('enabled', true),
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('approval_flows')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        } else {
            $payload['flow_key'] = $flowKey;
            $payload['created_at'] = now();
            $id = DB::table('approval_flows')->insertGetId($payload);
        }
        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function flowDelete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可操作'], 403);
        }
        $id = (int) $request->input('id');
        $flow = DB::table('approval_flows')->where('id', $id)->first();
        if (!$flow) return response()->json(['ok' => false, 'error' => '流程不存在'], 404);
        if (DB::table('approval_instances')->where('flow_key', $flow->flow_key)->exists()) {
            return response()->json(['ok' => false, 'error' => '该流程已有审批单，不允许删除（可停用）'], 409);
        }
        DB::table('approval_flows')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function flowToggle(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅管理员可操作'], 403);
        }
        $id = (int) $request->input('id');
        $flow = DB::table('approval_flows')->where('id', $id)->first();
        if (!$flow) return response()->json(['ok' => false, 'error' => '流程不存在'], 404);
        DB::table('approval_flows')->where('id', $id)->update(['enabled' => !(bool) $flow->enabled, 'updated_at' => now()]);
        return response()->json(['ok' => true, 'enabled' => !(bool) $flow->enabled]);
    }

    // ---------------- 运行层：发起 / 审批 / 查询 ----------------

    /** 可发起流程（对所有登录账号开放） */
    public function publicFlows(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $flows = DB::table('approval_flows')->where('enabled', true)->orderBy('id')->get()->map(fn ($f) => [
            'flow_key' => $f->flow_key, 'name' => $f->name,
            'form_schema' => $this->jsonValue($f->form_schema),
        ]);
        return response()->json(['ok' => true, 'flows' => $flows]);
    }

    /** 发起审批单 */
    public function create(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $flowKey = trim((string) $request->input('flow_key'));
        $built = $this->buildInstance($account, $flowKey, $request->input('form_data', []));
        if (!empty($built['error'])) return response()->json(['ok' => false, 'error' => $built['error']], 400);
        $this->notifyNode($built['nodeChain'][0], $built['project'], "【{$built['flow_name']}】待您审批", "{$account->name}发起了「{$built['title']}」，请及时处理");
        return response()->json(['ok' => true, 'id' => $built['id'], 'flow_no' => $built['flow_no']]);
    }

    /** 公共建单逻辑：校验+节点解析+生成唯一流程号+落库，供发起/草稿提交复用 */
    private function buildInstance(object $account, string $flowKey, $formDataIn): array
    {
        $flow = DB::table('approval_flows')->where('flow_key', $flowKey)->where('enabled', true)->first();
        if (!$flow) return ['error' => '流程不存在或已停用'];
        $schema = $this->jsonValue($flow->form_schema);
        $config = $this->jsonValue($flow->flow_config);
        $formData = $formDataIn;
        if (!is_array($formData)) return ['error' => '表单数据格式错误'];

        $err = $this->validateSchema($schema, $formData);
        if ($err) return ['error' => $err];

        $chainDef = $this->resolveTree($config, $formData);
        if (!$chainDef) return ['error' => '审批流程未配置节点'];

        $project = trim((string) ($formData['project'] ?? $account->project_name ?? ''));
        // 若表单项目字段为自动生成的 key（设计器新建流程），按类型/标签定位项目字段取值
        foreach (($schema ?: []) as $_f) {
            if (($_f['type'] ?? '') === 'project' || ($_f['label'] ?? '') === '项目') {
                $_k = (string) ($_f['key'] ?? '');
                if ($_k !== '' && !empty($formData[$_k])) { $project = trim((string) $formData[$_k]); break; }
            }
        }
        $nodeChain = [];
        $applicantStaff = $this->staffByAccount($account);
        foreach ($chainDef as $i => $nodeId) {
            $node = $config['nodes'][$nodeId] ?? null;
            if (!$node) continue;
            $approvers = $this->resolveApprovers($node, $account, $applicantStaff, $project);
            if (!$approvers) return ['error' => '节点「' . ($node['name'] ?? $nodeId) . '」未匹配到审批人，请联系管理员配置'];
            $nodeChain[] = [
                'nodeId' => $nodeId, 'name' => $node['name'] ?? '审批',
                'mode' => ($node['mode'] ?? 'any') === 'all' ? 'all' : 'any',
                'approvers' => $approvers,
            ];
        }
        if (!$nodeChain) return ['error' => '未解析出有效审批节点'];

        $title = $this->buildTitle($flow->name, $schema, $formData);
        $flowNo = $this->genFlowNo($flowKey);
        $instanceId = DB::table('approval_instances')->insertGetId([
            'flow_no' => $flowNo,
            'flow_key' => $flowKey, 'flow_name' => $flow->name, 'title' => $title,
            'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            'node_chain' => json_encode($nodeChain, JSON_UNESCAPED_UNICODE),
            'opinions' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'current_node' => 0,
            'applicant_id' => $account->id, 'applicant_name' => $account->name ?: $account->username,
            'project_name' => $project,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['id' => $instanceId, 'flow_no' => $flowNo, 'title' => $title, 'project' => $project,
            'flow_name' => $flow->name, 'nodeChain' => $nodeChain];
    }

    /** 生成唯一流程号：类型码(LS/ZZ/LZ/TG)+日期(YYYYMMDD)+3位序号 */
    private function genFlowNo(string $flowKey): string
    {
        $codeMap = ['hire_approval' => 'LS', 'regular_approval' => 'ZZ', 'resign_approval' => 'LZ'];
        $code = $codeMap[$flowKey] ?? 'TG';
        $date = date('Ymd');
        $seq = 1;
        $no = $code . $date . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        while (DB::table('approval_instances')->where('flow_no', $no)->exists()) {
            $seq++;
            if ($seq > 999) { $date = date('YmdHis'); $seq = 1; }
            $no = $code . $date . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        }
        return $no;
    }

    /** 我的审批单列表：mine=我发起 / approve=待我审 / done=我经手已结 / all=全部（管理员） */
    public function list(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $scope = (string) $request->input('scope', 'mine');
        $flowKey = trim((string) $request->input('flow_key'));
        $status = trim((string) $request->input('status'));
        $query = DB::table('approval_instances');
        if ($flowKey !== '') $query->where('flow_key', $flowKey);
        if ($status !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status)), fn ($s) => $s !== ''));
            if (count($statuses) === 1) $query->where('status', reset($statuses));
            elseif (count($statuses) > 1) $query->whereIn('status', $statuses);
        }

        if ($scope === 'mine') {
            $query->where('applicant_id', $account->id);
        } elseif ($scope === 'approve') {
            $query->where('status', 'pending');
            $query->where(function ($q) use ($account) {
                $all = DB::table('approval_instances')->where('status', 'pending')->get();
                $q->whereRaw('0 = 1');
                foreach ($all as $inst) {
                    $chain = $this->jsonValue($inst->node_chain);
                    $cur = $chain[$inst->current_node] ?? null;
                    if ($cur && $this->chainHasAccount($cur, $account)) {
                        $q->orWhere('id', $inst->id);
                    }
                }
            });
        } elseif ($scope === 'done') {
            $query->where('status', '!=', 'pending');
            $query->where(function ($q) use ($account) {
                $all = DB::table('approval_instances')->where('status', '!=', 'pending')->get();
                $q->whereRaw('0 = 1');
                foreach ($all as $inst) {
                    if ((int) $inst->applicant_id === (int) $account->id || $this->instanceTouchedBy($inst, $account)) {
                        $q->orWhere('id', $inst->id);
                    }
                }
            });
        } elseif ($scope === 'all') {
            if ((string) $account->role !== 'admin') {
                return response()->json(['ok' => false, 'error' => '仅管理员可查看全部'], 403);
            }
        }

        $items = $query->orderByDesc('id')->limit(300)->get()->map(function ($inst) use ($account) {
            $chain = $this->jsonValue($inst->node_chain);
            $cur = $chain[$inst->current_node] ?? null;
            $myState = 'none';
            if ($inst->status === 'pending' && $cur) {
                foreach ($cur['approvers'] ?? [] as $a) {
                    if ((int) $a['accountId'] === (int) $account->id) {
                        $myState = $a['state'] === 'approved' ? 'approved' : 'todo';
                    }
                }
            }
            return [
                'id' => $inst->id, 'flow_no' => $inst->flow_no, 'flow_key' => $inst->flow_key, 'flow_name' => $inst->flow_name,
                'title' => $inst->title, 'status' => $inst->status, 'project' => $inst->project_name,
                'applicant' => $inst->applicant_name, 'current' => $cur['name'] ?? '',
                'current_index' => (int) $inst->current_node, 'total_nodes' => count($chain),
                'my_state' => $myState, 'time' => $inst->created_at, 'finished_at' => $inst->finished_at,
            ];
        });
        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        $chain = $this->jsonValue($inst->node_chain);
        $opinions = $this->jsonValue($inst->opinions);
        $flow = DB::table('approval_flows')->where('flow_key', $inst->flow_key)->first();
        $schema = $flow ? $this->jsonValue($flow->form_schema) : [];
        return response()->json([
            'ok' => true,
            'instance' => [
                'id' => $inst->id, 'flow_no' => $inst->flow_no, 'flow_key' => $inst->flow_key, 'flow_name' => $inst->flow_name,
                'title' => $inst->title, 'form_data' => $this->jsonValue($inst->form_data),
                'form_schema' => $schema, 'status' => $inst->status, 'project' => $inst->project_name,
                'applicant' => $inst->applicant_name, 'applicant_id' => (int) $inst->applicant_id,
                'node_chain' => $chain, 'opinions' => $opinions,
                'current_index' => (int) $inst->current_node, 'result' => $this->jsonValue($inst->result),
                'time' => $inst->created_at, 'finished_at' => $inst->finished_at,
            ],
            'me' => ['id' => (int) $account->id, 'role' => $account->role],
        ]);
    }

    public function approve(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $opinion = trim((string) $request->input('opinion', ''));
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        if ($inst->status !== 'pending') return response()->json(['ok' => false, 'error' => '该审批单已结束'], 409);

        $chain = $this->jsonValue($inst->node_chain);
        $node = $chain[$inst->current_node] ?? null;
        if (!$node) return response()->json(['ok' => false, 'error' => '审批节点异常'], 409);
        // 防重复审批（先于权限判断，给出明确提示）
        $opinions = $this->jsonValue($inst->opinions);
        foreach (($opinions[$node['nodeId']] ?? []) as $o) {
            if ((int) ($o['accountId'] ?? 0) === (int) $account->id) {
                return response()->json(['ok' => false, 'error' => '您已审批过该节点'], 409);
            }
        }
        if (!$this->chainHasAccount($node, $account)) {
            return response()->json(['ok' => false, 'error' => '您不是当前节点的审批人'], 403);
        }

        $opinions[$node['nodeId']] = $opinions[$node['nodeId']] ?? [];
        $opinions[$node['nodeId']][] = [
            'accountId' => (int) $account->id, 'name' => $account->name ?: $account->username,
            'action' => 'approve', 'opinion' => $opinion, 'time' => now()->toDateTimeString(),
        ];
        foreach ($node['approvers'] as &$a) {
            if ((int) $a['accountId'] === (int) $account->id) $a['state'] = 'approved';
        }
        unset($a);
        $chain[$inst->current_node] = $node;

        // 判断节点是否通过：all=全员通过 / any=任一通过
        $nodeDone = $node['mode'] === 'all'
            ? count(array_filter($node['approvers'], fn ($a) => ($a['state'] ?? '') === 'approved')) === count($node['approvers'])
            : true;
        $nextIndex = (int) $inst->current_node;
        $newStatus = 'pending';
        if ($nodeDone) {
            $nextIndex++;
            if ($nextIndex >= count($chain)) {
                $newStatus = 'approved';
            }
        }

        DB::table('approval_instances')->where('id', $id)->update([
            'node_chain' => json_encode($chain, JSON_UNESCAPED_UNICODE),
            'opinions' => json_encode($opinions, JSON_UNESCAPED_UNICODE),
            'current_node' => $nextIndex,
            'status' => $newStatus,
            'finished_at' => $newStatus === 'approved' ? now() : null,
            'updated_at' => now(),
        ]);

        if ($newStatus === 'approved') {
            $result = ApprovalActions::handle($inst->flow_key, $this->jsonValue($inst->form_data), $inst->project_name, $account, (int) $inst->id);
            DB::table('approval_instances')->where('id', $id)->update(['result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            MessageService::push($inst->applicant_id, 'approval', "【{$inst->flow_name}】已通过", "您发起的「{$inst->title}」审批已全部通过", 'approvalCenter', $inst->project_name);
            // 通知下一节点审批人
        } elseif ($nodeDone && $nextIndex < count($chain)) {
            $this->notifyNode($chain[$nextIndex], $inst->project_name, "【{$inst->flow_name}】待您审批", "「{$inst->title}」已通过上一节点，等待您审批");
        }
        return response()->json(['ok' => true, 'status' => $newStatus]);
    }

    public function reject(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $opinion = trim((string) $request->input('opinion', ''));
        if ($opinion === '') return response()->json(['ok' => false, 'error' => '请填写驳回原因'], 400);
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        if ($inst->status !== 'pending') return response()->json(['ok' => false, 'error' => '该审批单已结束'], 409);
        $chain = $this->jsonValue($inst->node_chain);
        $node = $chain[$inst->current_node] ?? null;
        if (!$node || !$this->chainHasAccount($node, $account)) {
            return response()->json(['ok' => false, 'error' => '您不是当前节点的审批人'], 403);
        }
        $opinions = $this->jsonValue($inst->opinions);
        $opinions[$node['nodeId']] = $opinions[$node['nodeId']] ?? [];
        $opinions[$node['nodeId']][] = [
            'accountId' => (int) $account->id, 'name' => $account->name ?: $account->username,
            'action' => 'reject', 'opinion' => $opinion, 'time' => now()->toDateTimeString(),
        ];
        DB::table('approval_instances')->where('id', $id)->update([
            'opinions' => json_encode($opinions, JSON_UNESCAPED_UNICODE),
            'status' => 'rejected', 'finished_at' => now(), 'updated_at' => now(),
        ]);
        MessageService::push($inst->applicant_id, 'approval', "【{$inst->flow_name}】已被驳回", "「{$inst->title}」被驳回：{$opinion}", 'approvalCenter', $inst->project_name);
        return response()->json(['ok' => true, 'status' => 'rejected']);
    }

    /** 驳回后修改表单重新提交（保留原单） */
    public function resubmit(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        if ((int) $inst->applicant_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '仅发起人可重新提交'], 403);
        }
        if ($inst->status !== 'rejected') return response()->json(['ok' => false, 'error' => '仅驳回状态的审批单可重新提交'], 409);

        $flow = DB::table('approval_flows')->where('flow_key', $inst->flow_key)->where('enabled', true)->first();
        if (!$flow) return response()->json(['ok' => false, 'error' => '流程不存在或已停用'], 404);
        $schema = $this->jsonValue($flow->form_schema);
        $config = $this->jsonValue($flow->flow_config);
        $formData = $request->input('form_data', []);
        if (!is_array($formData)) return response()->json(['ok' => false, 'error' => '表单数据格式错误'], 400);
        $err = $this->validateSchema($schema, $formData);
        if ($err) return response()->json(['ok' => false, 'error' => $err], 400);

        $chainDef = $this->resolveTree($config, $formData);
        // 与 buildInstance 保持一致地解析项目，保证「指定岗位·发起人所在项目」作用域在重新提交时仍按本项目匹配
        $project = trim((string) ($formData['project'] ?? $account->project_name ?? $inst->project_name ?? ''));
        foreach (($schema ?: []) as $_f) {
            if (($_f['type'] ?? '') === 'project' || ($_f['label'] ?? '') === '项目') {
                $_k = (string) ($_f['key'] ?? '');
                if ($_k !== '' && !empty($formData[$_k])) { $project = trim((string) $formData[$_k]); break; }
            }
        }
        $applicantStaff = $this->staffByAccount($account);
        $nodeChain = [];
        foreach ($chainDef as $i => $nodeId) {
            $node = $config['nodes'][$nodeId] ?? null;
            if (!$node) continue;
            $approvers = $this->resolveApprovers($node, $account, $applicantStaff, $project);
            if (!$approvers) return response()->json(['ok' => false, 'error' => '节点「' . ($node['name'] ?? $nodeId) . '」未匹配到审批人'], 400);
            $nodeChain[] = ['nodeId' => $nodeId, 'name' => $node['name'] ?? '审批', 'mode' => ($node['mode'] ?? 'any') === 'all' ? 'all' : 'any', 'approvers' => $approvers];
        }
        if (!$nodeChain) return response()->json(['ok' => false, 'error' => '未解析出有效审批节点'], 400);
        DB::table('approval_instances')->where('id', $id)->update([
            'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            'node_chain' => json_encode($nodeChain, JSON_UNESCAPED_UNICODE),
            'opinions' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'current_node' => 0, 'finished_at' => null,
            'project_name' => $project !== '' ? $project : $inst->project_name,
            'updated_at' => now(),
        ]);
        $this->notifyNode($nodeChain[0], $inst->project_name, "【{$flow->name}】待您审批", "「{$inst->title}」已修改重新提交，请审批");
        return response()->json(['ok' => true]);
    }

    /** 撤回（发起人，第一节点未审时） */
    public function withdraw(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        if ((int) $inst->applicant_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '仅发起人可撤回'], 403);
        }
        if ($inst->status !== 'pending') return response()->json(['ok' => false, 'error' => '当前状态不可撤回'], 409);
        $chain = $this->jsonValue($inst->node_chain);
        $cur = $chain[$inst->current_node] ?? null;
        if ($cur && $this->nodeHasAnyApproved($cur)) {
            return response()->json(['ok' => false, 'error' => '当前节点已有审批人处理，无法撤回'], 409);
        }
        DB::table('approval_instances')->where('id', $id)->update(['status' => 'withdrawn', 'finished_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** 作废（发起人 / 管理员） */
    public function void(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        $isAdmin = (string) $account->role === 'admin';
        if (!($isAdmin || (int) $inst->applicant_id === (int) $account->id)) {
            return response()->json(['ok' => false, 'error' => '仅发起人或管理员可作废'], 403);
        }
        if (in_array($inst->status, ['withdrawn', 'voided'], true)) {
            return response()->json(['ok' => false, 'error' => '当前状态不可作废'], 409);
        }
        DB::table('approval_instances')->where('id', $id)->update(['status' => 'voided', 'finished_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    // ---------------- 草稿（保存未提交） ----------------

    /** 保存草稿：draft_id 有值则更新，否则新建并生成流程号 */
    public function draftSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $flowKey = trim((string) $request->input('flow_key'));
        $flow = DB::table('approval_flows')->where('flow_key', $flowKey)->first();
        if (!$flow) return response()->json(['ok' => false, 'error' => '流程不存在'], 404);
        $formData = $request->input('form_data', []);
        if (!is_array($formData)) return response()->json(['ok' => false, 'error' => '表单数据格式错误'], 400);
        $schema = $this->jsonValue($flow->form_schema);
        $title = $this->buildTitle($flow->name, $schema, $formData);
        $project = trim((string) ($formData['project'] ?? $account->project_name ?? ''));
        $draftId = (int) $request->input('draft_id', 0);

        if ($draftId) {
            $draft = DB::table('approval_drafts')->where('id', $draftId)->first();
            if (!$draft || (int) $draft->user_id !== (int) $account->id) {
                return response()->json(['ok' => false, 'error' => '草稿不存在'], 404);
            }
            DB::table('approval_drafts')->where('id', $draftId)->update([
                'flow_key' => $flowKey, 'title' => $title, 'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
                'project_name' => $project, 'updated_at' => now(),
            ]);
            return response()->json(['ok' => true, 'draft_id' => $draftId, 'flow_no' => $draft->flow_no]);
        }

        $flowNo = $this->genDraftFlowNo($flowKey);
        $newId = DB::table('approval_drafts')->insertGetId([
            'user_id' => $account->id, 'flow_key' => $flowKey, 'flow_no' => $flowNo,
            'title' => $title, 'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            'project_name' => $project, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'draft_id' => $newId, 'flow_no' => $flowNo]);
    }

    /** 我的草稿列表 */
    public function draftList(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $items = DB::table('approval_drafts')->where('user_id', $account->id)
            ->orderByDesc('id')->limit(200)->get()->map(fn ($d) => [
                'id' => $d->id, 'flow_no' => $d->flow_no, 'flow_key' => $d->flow_key,
                'title' => $d->title, 'project' => $d->project_name,
                'time' => $d->updated_at ?: $d->created_at,
            ]);
        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** 草稿详情（继续编辑时回填） */
    public function draftDetail(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $d = DB::table('approval_drafts')->where('id', $id)->first();
        if (!$d || (int) $d->user_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '草稿不存在'], 404);
        }
        $flow = DB::table('approval_flows')->where('flow_key', $d->flow_key)->first();
        return response()->json(['ok' => true, 'draft' => [
            'id' => $d->id, 'flow_no' => $d->flow_no, 'flow_key' => $d->flow_key,
            'title' => $d->title, 'form_data' => $this->jsonValue($d->form_data),
            'form_schema' => $flow ? $this->jsonValue($flow->form_schema) : [],
            'project' => $d->project_name, 'time' => $d->updated_at ?: $d->created_at,
        ]]);
    }

    /** 删除草稿 */
    public function draftDelete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $d = DB::table('approval_drafts')->where('id', $id)->first();
        if (!$d || (int) $d->user_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '草稿不存在'], 404);
        }
        DB::table('approval_drafts')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** 草稿提交：复用建单逻辑，成功后删除草稿 */
    public function draftSubmit(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $d = DB::table('approval_drafts')->where('id', $id)->first();
        if (!$d || (int) $d->user_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '草稿不存在'], 404);
        }
        $built = $this->buildInstance($account, $d->flow_key, $this->jsonValue($d->form_data));
        if (!empty($built['error'])) return response()->json(['ok' => false, 'error' => $built['error']], 400);
        $this->notifyNode($built['nodeChain'][0], $built['project'], "【{$built['flow_name']}】待您审批", "{$account->name}发起了「{$built['title']}」，请及时处理");
        DB::table('approval_drafts')->where('id', $id)->delete();
        return response()->json(['ok' => true, 'id' => $built['id'], 'flow_no' => $built['flow_no']]);
    }

    /** 草稿流程号（独立序号，不与正式单冲突） */
    private function genDraftFlowNo(string $flowKey): string
    {
        $codeMap = ['hire_approval' => 'LS', 'regular_approval' => 'ZZ', 'resign_approval' => 'LZ'];
        $code = $codeMap[$flowKey] ?? 'TG';
        $date = date('Ymd');
        $seq = 1;
        $no = $code . $date . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        while (DB::table('approval_drafts')->where('flow_no', $no)->exists()
            || DB::table('approval_instances')->where('flow_no', $no)->exists()) {
            $seq++;
            if ($seq > 999) { $date = date('YmdHis'); $seq = 1; }
            $no = $code . $date . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        }
        return $no;
    }

    // ---------------- 收件（他人发送给我查看） ----------------

    /** 我的收件列表 */
    public function inbox(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $items = DB::table('approval_shares')->where('to_user_id', $account->id)
            ->orderByDesc('id')->limit(200)->get()->map(function ($s) {
                $inst = DB::table('approval_instances')->where('id', $s->instance_id)->first();
                return [
                    'id' => $s->id, 'instance_id' => $s->instance_id, 'flow_no' => $s->flow_no,
                    'flow_name' => $inst->flow_name ?? '', 'title' => $inst->title ?? '',
                    'from_name' => $s->from_name, 'to_name' => $s->to_name,
                    'time' => $s->created_at,
                ];
            });
        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** 删除我的收件 */
    public function inboxDelete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $s = DB::table('approval_shares')->where('id', $id)->first();
        if (!$s || (int) $s->to_user_id !== (int) $account->id) {
            return response()->json(['ok' => false, 'error' => '收件不存在'], 404);
        }
        DB::table('approval_shares')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** 发送流程给人员（仅已完结流程；从人员档案选，自动匹配账号） */
    public function send(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $id = (int) $request->input('id');
        $staffId = (int) $request->input('staff_id');
        $inst = DB::table('approval_instances')->where('id', $id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '审批单不存在'], 404);
        if (!in_array($inst->status, ['approved', 'rejected'], true)) {
            return response()->json(['ok' => false, 'error' => '仅已完结（已通过/已驳回）的流程可发送'], 409);
        }
        $staff = DB::table('payroll_staff')->where('legacy_id', $staffId)->where('deleted', false)->first();
        if (!$staff) return response()->json(['ok' => false, 'error' => '所选人员不存在'], 404);
        $toAcc = DB::table('payroll_accounts')->where('staff_id', $staffId)->where('enabled', true)->first();
        if (!$toAcc) return response()->json(['ok' => false, 'error' => "「{$staff->name}」未开通系统账号，无法发送"], 400);
        if ((int) $toAcc->id === (int) $account->id) return response()->json(['ok' => false, 'error' => '不能发送给自己'], 400);
        // 防重复发送
        $dup = DB::table('approval_shares')->where('instance_id', $id)->where('to_user_id', $toAcc->id)->exists();
        if ($dup) return response()->json(['ok' => false, 'error' => "已发送给「{$staff->name}」"], 409);

        DB::table('approval_shares')->insert([
            'instance_id' => $id, 'flow_no' => $inst->flow_no ?: '',
            'from_user_id' => $account->id, 'from_name' => $account->name ?: $account->username,
            'to_user_id' => $toAcc->id, 'to_name' => $toAcc->name ?: $toAcc->username,
            'created_at' => now(),
        ]);
        MessageService::push($toAcc->id, 'notice', '有流程发送给您查看', "{$account->name}向您发送了流程「{$inst->title}」（{$inst->flow_no}），请在我的待办-收件中查看", 'approvalCenter', $inst->project_name);
        return response()->json(['ok' => true]);
    }

    /** 发送目标人员（人员档案→账号匹配情况） */
    public function sendTargets(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $staff = DB::table('payroll_staff')->where('deleted', false)->orderBy('project_name')->orderBy('name')->get();
        $accMap = DB::table('payroll_accounts')->whereNotNull('staff_id')->where('enabled', true)->get()
            ->keyBy('staff_id');
        $items = $staff->map(fn ($s) => [
            'id' => $s->legacy_id, 'name' => $s->name, 'project' => $s->project_name,
            'position' => $s->position ?: '', 'has_account' => isset($accMap[$s->legacy_id]),
            'account' => isset($accMap[$s->legacy_id]) ? $accMap[$s->legacy_id]->username : '',
        ])->filter(fn ($s) => $s['has_account']);
        return response()->json(['ok' => true, 'items' => array_values($items->all())]);
    }

    // ---------------- 查询流程 ----------------

    /** 查询流程：流程号精确 / 关键字 / 类型 / 状态 / 日期范围（管理员全部，其余仅本人发起） */
    public function search(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $flowNo = trim((string) $request->input('flow_no'));
        $keyword = trim((string) $request->input('keyword'));
        $flowKey = trim((string) $request->input('flow_key'));
        $status = trim((string) $request->input('status'));
        $dateFrom = trim((string) $request->input('date_from'));
        $dateTo = trim((string) $request->input('date_to'));

        $q = DB::table('approval_instances');
        if ($flowNo !== '') {
            $q->where('flow_no', 'like', '%' . $flowNo . '%');
        }
        if ($keyword !== '') {
            $q->where(function ($qq) use ($keyword) {
                $qq->where('title', 'like', '%' . $keyword . '%')
                   ->orWhere('applicant_name', 'like', '%' . $keyword . '%')
                   ->orWhere('flow_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($flowKey !== '') $q->where('flow_key', $flowKey);
        if ($status !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status)), fn ($s) => $s !== ''));
            if (count($statuses) === 1) $q->where('status', reset($statuses));
            elseif (count($statuses) > 1) $q->whereIn('status', $statuses);
        }
        if ($dateFrom !== '') $q->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo !== '') $q->whereDate('created_at', '<=', $dateTo);
        if ((string) $account->role !== 'admin') {
            $q->where('applicant_id', $account->id);
        }

        $items = $q->orderByDesc('id')->limit(300)->get()->map(function ($inst) {
            $chain = $this->jsonValue($inst->node_chain);
            return [
                'id' => $inst->id, 'flow_no' => $inst->flow_no, 'flow_key' => $inst->flow_key,
                'flow_name' => $inst->flow_name, 'title' => $inst->title, 'status' => $inst->status,
                'project' => $inst->project_name, 'applicant' => $inst->applicant_name,
                'current' => ($chain[$inst->current_node] ?? null)['name'] ?? '',
                'current_index' => (int) $inst->current_node, 'total_nodes' => count($chain),
                'time' => $inst->created_at,
            ];
        });
        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** 附件上传 */
    public function upload(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $file = $request->file('file');
        if (!$file || !$file->isValid()) return response()->json(['ok' => false, 'error' => '未收到文件'], 400);
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        if (!in_array($ext, ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx','txt','zip','rar','7z'], true)) {
            return response()->json(['ok' => false, 'error' => '不支持的文件类型：' . $ext], 400);
        }
        $dir = public_path('uploads');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $orig = $file->getClientOriginalName();
        $size = $file->getSize();
        $file->move($dir, $name);
        return response()->json(['ok' => true, 'name' => $orig, 'url' => '/uploads/' . $name, 'size' => $size]);
    }

    // ---------------- 入职办理清单 ----------------

    public function onboardList(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅人力可办理入职'], 403);
        }
        $status = trim((string) $request->input('status', 'pending'));
        $items = DB::table('onboard_checklists')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(200)->get()->map(fn ($o) => [
                'id' => $o->id, 'instance_id' => $o->instance_id, 'staff_name' => $o->staff_name,
                'project' => $o->project_name, 'status' => $o->status,
                'done_count' => count(array_filter($this->jsonValue($o->items), fn ($i) => !empty($i['done']))),
                'total_count' => count($this->jsonValue($o->items)),
                'time' => $o->created_at,
            ]);
        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function onboardDetail(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $o = DB::table('onboard_checklists')->where('id', $id)->first();
        if (!$o) return response()->json(['ok' => false, 'error' => '清单不存在'], 404);
        $inst = DB::table('approval_instances')->where('id', $o->instance_id)->first();
        $formData = $inst ? $this->jsonValue($inst->form_data) : [];
        $schema = [];
        if ($inst) {
            $flow = DB::table('approval_flows')->where('flow_key', $inst->flow_key)->first();
            $schema = $flow ? $this->jsonValue($flow->form_schema) : [];
        }
        return response()->json(['ok' => true, 'item' => [
            'id' => $o->id, 'staff_name' => $o->staff_name, 'project' => $o->project_name,
            'status' => $o->status, 'items' => $this->jsonValue($o->items),
            'extra' => $this->jsonValue($o->extra), 'form_data' => $formData, 'form_schema' => $schema, 'time' => $o->created_at,
        ]]);
    }

    public function onboardSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅人力可办理入职'], 403);
        }
        $id = (int) $request->input('id');
        $o = DB::table('onboard_checklists')->where('id', $id)->first();
        if (!$o) return response()->json(['ok' => false, 'error' => '清单不存在'], 404);
        $items = $request->input('items');
        $extra = $request->input('extra', []);
        if (!is_array($items) || !is_array($extra)) return response()->json(['ok' => false, 'error' => '参数错误'], 400);
        // 规范化办理项：兼容 [{key,label,done,by}] 与 ['key',...] 两种传法，防止误用导致办理项永远无法完成
        $items = array_values(array_map(function ($it) {
            if (is_string($it)) return ['key' => $it, 'label' => $it, 'done' => true, 'by' => ''];
            return [
                'key' => (string) ($it['key'] ?? 'item'),
                'label' => (string) ($it['label'] ?? $it['key'] ?? ''),
                'done' => !empty($it['done']),
                'by' => (string) ($it['by'] ?? ''),
            ];
        }, $items));
        DB::table('onboard_checklists')->where('id', $id)->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    /** 清单完成：正式新增人员 + 可选开通账号 */
    public function onboardComplete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ((string) $account->role !== 'admin') {
            return response()->json(['ok' => false, 'error' => '仅人力可办理入职'], 403);
        }
        $id = (int) $request->input('id');
        $o = DB::table('onboard_checklists')->where('id', $id)->first();
        if (!$o) return response()->json(['ok' => false, 'error' => '清单不存在'], 404);
        if ($o->status === 'done') return response()->json(['ok' => false, 'error' => '该清单已办理完成'], 409);
        $inst = DB::table('approval_instances')->where('id', $o->instance_id)->first();
        if (!$inst) return response()->json(['ok' => false, 'error' => '关联审批单不存在'], 404);
        $formData = $this->jsonValue($inst->form_data);
        $items = $this->jsonValue($o->items);
        $extra = $this->jsonValue($o->extra);

        // 未完成的办理项不允许完成
        foreach ($items as $it) {
            if (empty($it['done'])) {
                return response()->json(['ok' => false, 'error' => '还有办理项未完成：' . ($it['label'] ?? '')], 400);
            }
        }

        // 是否开通账号：优先采用录用表单里"是否开通系统账号"的选择，接口参数可显式覆盖
        $openAccount = (bool) $request->input('open_account', false);
        if (!$openAccount && ($formData['open_account'] ?? '') === '是') {
            $openAccount = true;
        }
        $result = ApprovalActions::createStaff($formData, $extra, $inst->project_name, $openAccount);
        if (!empty($result['error'])) {
            // 建档失败（如身份证/手机号不合法、重名等）：不置完成、不发通知，允许用户修正后重试
            return response()->json(['ok' => false, 'error' => $result['error']], 400);
        }
        DB::table('onboard_checklists')->where('id', $id)->update(['status' => 'done', 'updated_at' => now()]);
        MessageService::push($inst->applicant_id, 'notice', '入职办理完成', "「{$o->staff_name}」已完成入职办理并正式建档", 'staff', $inst->project_name);
        return response()->json(['ok' => true, 'result' => $result]);
    }

    // ---------------- 工具方法 ----------------

    private function validateSchema(array $schema, array $formData): ?string
    {
        foreach ($schema as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') continue;
            $required = !empty($f['required']);
            $val = $formData[$key] ?? null;
            $isEmpty = $val === null || $val === '' || (is_array($val) && !count($val));
            if ($required && $isEmpty) return '请填写「' . ($f['label'] ?? $key) . '」';
            if ($isEmpty) continue;
            $type = (string) ($f['type'] ?? 'text');
            if (in_array($type, ['number', 'amount'], true) && !is_numeric($val)) return '「' . ($f['label'] ?? $key) . '」必须为数字';
            if ($type === 'date') {
                $d = (string) $val;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return '「' . ($f['label'] ?? $key) . '」日期格式应为 YYYY-MM-DD';
            }
            if (in_array($type, ['select', 'radio'], true)) {
                $opts = array_map(fn ($o) => is_array($o) ? ($o['value'] ?? $o['label'] ?? '') : (string) $o, $f['options'] ?? []);
                if ($opts && !in_array((string) $val, $opts, true)) return '「' . ($f['label'] ?? $key) . '」取值不合法';
            }
        }
        return null;
    }

    /** 按条件分支解析节点序列（分支顶层 field/op/value，兼容嵌套 condition 形式） */
        /** 递归解析审批流树（新结构 tree），兼容旧结构 default+branches */
    private function resolveTree(array $config, array $formData): array
    {
        if (isset($config['tree']) && is_array($config['tree'])) {
            $out = [];
            $this->walkTree($config['tree'], $config, $formData, $out);
            return array_values(array_filter(array_unique($out)));
        }
        // 旧结构兼容
        $branches = $config['branches'] ?? [];
        foreach ($branches as $b) {
            if ($this->matchCondition($b, $formData)) {
                $nodes = array_values(array_filter($b['nodes'] ?? []));
                if ($nodes) return $nodes;
            }
        }
        return array_values(array_filter($config['default'] ?? []));
    }

    /** 递归遍历树：chain 按顺序收集 approve 节点；branch 按条件选第一条命中 path（无命中走默认 path） */
    private function walkTree(array $tree, array $config, array $formData, array &$out): void
    {
        $type = $tree['type'] ?? 'chain';
        if ($type === 'branch') {
            $paths = $tree['paths'] ?? [];
            $hit = null;
            foreach ($paths as $p) {
                if (!empty($p['default'])) { if ($hit === null) $hit = $p; continue; }
                if (isset($p['cond']) && is_array($p['cond']) && $this->matchCondition($p['cond'], $formData)) { $hit = $p; break; }
            }
            if ($hit === null && count($paths)) $hit = $paths[count($paths) - 1];
            if ($hit) $this->walkTree($hit, $config, $formData, $out);
            return;
        }
        // chain
        foreach (($tree['items'] ?? []) as $item) {
            $itype = $item['type'] ?? 'approve';
            if ($itype === 'branch' || isset($item['paths'])) { $this->walkTree($item, $config, $formData, $out); }
            else {
                $nid = (string) ($item['nodeId'] ?? $item['node'] ?? '');
                if ($nid === '' && isset($item['items'])) { $this->walkTree($item, $config, $formData, $out); }
                else { $out[] = $nid; }
            }
        }
    }

private function matchCondition($b, array $formData): bool
    {
        if (!is_array($b)) return false;
        $field = (string) ($b['field'] ?? ($b['condition']['field'] ?? ''));
        if ($field === '') return false;
        $op = (string) ($b['op'] ?? ($b['condition']['op'] ?? 'eq'));
        $expect = (string) ($b['value'] ?? ($b['condition']['value'] ?? ''));
        $actual = $formData[$field] ?? '';
        if (is_array($actual)) $actual = implode(',', $actual);
        $actual = (string) $actual;
        switch ($op) {
            case 'eq': return $actual === $expect;
            case 'neq': return $actual !== $expect;
            case 'contains': return $expect === '' || str_contains($actual, $expect);
            case 'gt': return is_numeric($actual) && is_numeric($expect) && (float) $actual > (float) $expect;
            case 'lt': return is_numeric($actual) && is_numeric($expect) && (float) $actual < (float) $expect;
            case 'gte': return is_numeric($actual) && is_numeric($expect) && (float) $actual >= (float) $expect;
            case 'lte': return is_numeric($actual) && is_numeric($expect) && (float) $actual <= (float) $expect;
            case 'earlier': return $actual !== '' && $expect !== '' && $actual < $expect;
            case 'later': return $actual !== '' && $expect !== '' && $actual > $expect;
            case 'empty': return $actual === '';
            case 'notempty': return $actual !== '';
        }
        return false;
    }

    /** 解析节点审批人（leader=发起人上级 / role=角色 / accounts=指定账号） */
        private function resolveApprovers(array $node, object $account, ?object $applicantStaff, string $project = ''): array
    {
        $approvers = [];
        foreach (($node['approvers'] ?? []) as $rule) {
            $type = (string) ($rule['type'] ?? 'accounts');
            if ($type === 'leader') {
                $staff = $applicantStaff;
                if ($staff && $staff->leader_id) {
                    $leader = DB::table('payroll_staff')->where('legacy_id', $staff->leader_id)->first();
                    $acc = $leader ? DB::table('payroll_accounts')->where('staff_id', $leader->legacy_id)->where('enabled', true)->first() : null;
                    if ($acc) $approvers[] = ['accountId' => (int) $acc->id, 'name' => $acc->name ?: $acc->username, 'state' => 'pending'];
                }
                foreach (($rule['fallback_accounts'] ?? []) as $fid) {
                    $acc = DB::table('payroll_accounts')->where('id', (int) $fid)->where('enabled', true)->first();
                    if ($acc) $approvers[] = ['accountId' => (int) $acc->id, 'name' => $acc->name ?: $acc->username, 'state' => 'pending'];
                }
            } elseif ($type === 'role') {
                $roleKey = (string) ($rule['role'] ?? '');
                $accounts = DB::table('payroll_accounts')->where('role', $roleKey)->where('enabled', true)->get();
                foreach ($accounts as $acc) {
                    $approvers[] = ['accountId' => (int) $acc->id, 'name' => $acc->name ?: $acc->username, 'state' => 'pending'];
                }
            } elseif ($type === 'position') {
                // 指定岗位：按花名册岗位匹配，scope=project 取发起人所在项目，scope=global 取物业总部（无则全公司）
                $position = (string) ($rule['position'] ?? '');
                $scope = (string) ($rule['scope'] ?? 'project');
                if ($position !== '') {
                    $q = DB::table('payroll_staff')->where('position', $position)->where('deleted', 0);
                    if ($scope === 'global') {
                        $rows = (clone $q)->where('project_name', '物业总部')->get();
                        if (!$rows->count()) $rows = $q->get();
                    } else {
                        $rows = $project !== '' ? (clone $q)->where('project_name', $project)->get() : collect();
                        if (!isset($rows) || !$rows->count()) $rows = $q->get();
                    }
                    foreach ($rows as $st) {
                        $acc = DB::table('payroll_accounts')->where('staff_id', $st->legacy_id)->where('enabled', true)->first();
                        if ($acc) $approvers[] = ['accountId' => (int) $acc->id, 'name' => $acc->name ?: $acc->username, 'state' => 'pending'];
                    }
                }
            } else {
                foreach (($rule['accountIds'] ?? []) as $aid) {
                    $acc = DB::table('payroll_accounts')->where('id', (int) $aid)->where('enabled', true)->first();
                    if ($acc) $approvers[] = ['accountId' => (int) $acc->id, 'name' => $acc->name ?: $acc->username, 'state' => 'pending'];
                }
            }
        }
        // 去重（按账号）
        $seen = [];
        $out = [];
        foreach ($approvers as $a) {
            if (!isset($seen[$a['accountId']])) { $seen[$a['accountId']] = true; $out[] = $a; }
        }
        return $out;
    }

private function staffByAccount(object $account): ?object
    {
        if (!$account->staff_id) return null;
        return DB::table('payroll_staff')->where('legacy_id', $account->staff_id)->first();
    }

    private function chainHasAccount(array $node, object $account): bool
    {
        foreach (($node['approvers'] ?? []) as $a) {
            if ((int) $a['accountId'] === (int) $account->id && ($a['state'] ?? '') !== 'approved') return true;
        }
        return false;
    }

    private function nodeHasAnyApproved(array $node): bool
    {
        foreach (($node['approvers'] ?? []) as $a) {
            if (($a['state'] ?? '') === 'approved') return true;
        }
        return false;
    }

    private function instanceTouchedBy(object $inst, object $account): bool
    {
        $opinions = $this->jsonValue($inst->opinions);
        foreach ($opinions as $nodeOpinions) {
            foreach ($nodeOpinions as $o) {
                if ((int) ($o['accountId'] ?? 0) === (int) $account->id) return true;
            }
        }
        return false;
    }

    private function buildTitle(string $flowName, array $schema, array $formData): string
    {
        $name = '';
        foreach ($schema as $f) {
            if (($f['type'] ?? '') === 'person') { $name = (string) ($formData[$f['key']] ?? ''); break; }
        }
        if ($name === '') {
            foreach (['name', 'staff_name', 'employee_name'] as $k) {
                if (!empty($formData[$k])) { $name = (string) $formData[$k]; break; }
            }
        }
        return ($name !== '' ? $name . '的' : '') . $flowName;
    }

    private function notifyNode(array $node, string $project, string $title, string $content): void
    {
        $ids = array_column($node['approvers'] ?? [], 'accountId');
        MessageService::push($ids, 'approval', $title, $content, 'approvalCenter', $project);
    }
}
