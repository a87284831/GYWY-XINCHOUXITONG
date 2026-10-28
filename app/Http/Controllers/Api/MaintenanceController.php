<?php

namespace App\Http\Controllers\Api;

use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\{Color, Alignment, Border, Fill};

class MaintenanceController extends ApiController
{
    /** 合同字段中文名（变更留痕用） */
    private const FIELD_LABELS = [
        'project_name' => '项目名称', 'party' => '签约方', 'contract_no' => '合同编号',
        'party_a' => '签约主体(甲方)', 'party_contact' => '乙方负责人', 'party_phone' => '乙方联系电话',
        'amount' => '签约金额', 'sign_date' => '签订日期', 'start_date' => '生效开始', 'end_date' => '到期日期',
        'elevator_count' => '电梯台数', 'building_area_sqm' => '建筑面积(㎡)',
        'price_per_sqm' => '每㎡单价', 'price_per_unit' => '每台单价',
        'service_scope' => '维保服务内容', 'includes_supplies' => '含配件耗材', 'remark' => '备注',
    ];
    private const NUMERIC_FIELDS = ['amount', 'building_area_sqm', 'price_per_sqm', 'price_per_unit', 'elevator_count', 'includes_supplies'];

    /** 附件分类 */
    public const ATT_KINDS = [
        'contract' => '合同正文', 'supplement' => '补充协议', 'inspection' => '年检报告',
        'qualification' => '资质证书', 'record' => '履约附件', 'other' => '其他',
    ];

    /** 履约记录类型 */
    public const RECORD_KINDS = ['maintain' => '维保', 'rectify' => '整改', 'inspection' => '年检'];

    /* ================= 合同 ================= */

    public function contracts(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('maintenance_contracts');
        if ($request->filled('type')) $query->where('type', $request->string('type'));
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $today = now()->startOfDay();
        return response()->json(['ok' => true, 'contracts' => $query->orderByDesc('end_date')->get()
            ->map(fn ($row) => $this->contractArray($row, $today))]);
    }

    public function saveContract(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $project = trim((string) $request->input('project_name'));
        if ($project === '') return response()->json(['ok' => false, 'error' => '项目不能为空'], 400);
        if ($this->isProjectScope($account) && $project !== (string) $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        $type = $request->input('type') === 'elevator' ? 'elevator' : 'fire';
        $start = $request->input('start_date') ?: null;
        $end = $request->input('end_date') ?: null;
        if ($start && $end && $end < $start) return response()->json(['ok' => false, 'error' => '合同到期日期不能早于生效开始日期'], 400);
        $amount = (float) $request->input('amount', 0);
        if ($amount <= 0) return response()->json(['ok' => false, 'error' => '合同签约金额必须大于 0'], 400);
        $elevCount = (int) $request->input('elevator_count', 0);
        $area = (float) $request->input('building_area_sqm', 0);
        if ($type === 'elevator' && $elevCount <= 0) return response()->json(['ok' => false, 'error' => '电梯维保总台数必须大于 0'], 400);
        if ($type === 'fire' && $area <= 0) return response()->json(['ok' => false, 'error' => '项目建筑面积必须大于 0'], 400);

        $data = ['type' => $type, 'project_name' => $project, 'party' => trim((string) $request->input('party')),
            'contract_no' => trim((string) $request->input('contract_no', '')),
            'party_a' => trim((string) $request->input('party_a', '')),
            'party_contact' => trim((string) $request->input('party_contact', '')),
            'party_phone' => trim((string) $request->input('party_phone', '')),
            'service_scope' => trim((string) $request->input('service_scope', '')),
            'includes_supplies' => $request->boolean('includes_supplies') ? 1 : 0,
            'amount' => $amount, 'sign_date' => $request->input('sign_date') ?: null,
            'start_date' => $start, 'end_date' => $end,
            'elevator_count' => $elevCount, 'building_area_sqm' => $area,
            'price_per_sqm' => (float) $request->input('price_per_sqm', 0), 'price_per_unit' => (float) $request->input('price_per_unit', 0),
            'remark' => trim((string) $request->input('remark', '')), 'updated_at' => now()];
        if ($id) {
            $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
            if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
            if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
            $changes = $this->diffContract($row, $data);
            DB::table('maintenance_contracts')->where('legacy_id', $id)->update($data);
            if ($changes) $this->logContract($id, $account, 'update', $changes);
        } else {
            $id = (int) (DB::table('maintenance_contracts')->max('legacy_id') ?? 0) + 1;
            $data['legacy_id'] = $id; $data['created_at'] = now();
            DB::table('maintenance_contracts')->insert($data);
            $this->logContract($id, $account, 'create', []);
        }
        $saved = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        return response()->json(['ok' => true, 'contract' => $this->contractArray($saved)]);
    }

    public function deleteContract(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权删除其他项目合同'], 403);
        if ($row->pdf_name) Storage::disk('local')->delete('maintenance/' . $row->pdf_name);
        // 级联清理：附件文件、履约记录、变更日志、预警记录
        foreach (DB::table('maintenance_attachments')->where('contract_id', $id)->get() as $att) {
            Storage::disk('local')->delete('maintenance/atts/' . $att->file_name);
        }
        DB::table('maintenance_attachments')->where('contract_id', $id)->delete();
        DB::table('maintenance_records')->where('contract_id', $id)->delete();
        DB::table('maintenance_contract_logs')->where('contract_id', $id)->delete();
        DB::table('maintenance_alert_logs')->where('obj_type', 'contract')->where('obj_id', $id)->delete();
        DB::table('maintenance_contracts')->where('legacy_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** 合同变更留痕 */
    public function contractLogs(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权查看其他项目合同'], 403);
        $logs = DB::table('maintenance_contract_logs')->where('contract_id', $id)->orderByDesc('id')->limit(100)->get()
            ->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'account' => $l->account_name,
                'changes' => $this->jsonValue($l->changes, []), 'time' => $l->created_at]);
        return response()->json(['ok' => true, 'logs' => $logs]);
    }

    /* ================= 合同附件（多附件） ================= */

    public function attachments(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$contract) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权查看其他项目合同'], 403);
        $rows = DB::table('maintenance_attachments')->where('contract_id', $id)->whereNull('record_id')->orderByDesc('id')->get();
        return response()->json(['ok' => true, 'attachments' => $rows->map(fn ($a) => $this->attachmentArray($a))]);
    }

    public function uploadAttachment(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$contract) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到文件'], 400);
        $kind = (string) $request->input('kind', 'other');
        if (!isset(self::ATT_KINDS[$kind]) || $kind === 'record') $kind = 'other';
        $request->validate(['file' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,zip', 'max:51200']]);
        return response()->json(['ok' => true, 'attachment' => $this->storeAttachment($request, $contract, $kind, null, $account)]);
    }

    public function downloadAttachment(Request $request, int $id)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $att = DB::table('maintenance_attachments')->where('legacy_id', $id)->first();
        if (!$att) return response('file not found', 404);
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $att->contract_id)->first();
        if (!$contract) return response('file not found', 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权访问其他项目合同'], 403);
        $path = 'maintenance/atts/' . $att->file_name;
        if (!Storage::disk('local')->exists($path)) return response('file not found', 404);
        return Storage::disk('local')->download($path, $att->name !== '' ? $att->name : $att->file_name);
    }

    public function deleteAttachment(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $att = DB::table('maintenance_attachments')->where('legacy_id', $id)->first();
        if (!$att) return response()->json(['ok' => false, 'error' => '附件不存在'], 404);
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $att->contract_id)->first();
        if ($contract && $this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        Storage::disk('local')->delete('maintenance/atts/' . $att->file_name);
        DB::table('maintenance_attachments')->where('legacy_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /* ================= 履约记录 ================= */

    public function records(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$contract) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权查看其他项目合同'], 403);
        $rows = DB::table('maintenance_records')->where('contract_id', $id)->orderByDesc('record_date')->orderByDesc('id')->get();
        $atts = DB::table('maintenance_attachments')->where('contract_id', $id)->whereNotNull('record_id')->orderByDesc('id')->get()
            ->groupBy('record_id');
        return response()->json(['ok' => true, 'records' => $rows->map(function ($r) use ($atts) {
            return ['id' => (int) $r->legacy_id, 'record_date' => $r->record_date, 'kind' => $r->kind,
                'kind_label' => self::RECORD_KINDS[$r->kind] ?? $r->kind,
                'content' => $r->content, 'result' => $r->result, 'created_at' => $r->created_at,
                'attachments' => ($atts[(int) $r->legacy_id] ?? collect())->map(fn ($a) => $this->attachmentArray($a))->values()];
        })]);
    }

    public function saveRecord(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$contract) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        $content = trim((string) $request->input('content', ''));
        if ($content === '') return response()->json(['ok' => false, 'error' => '记录内容不能为空'], 400);
        $kind = (string) $request->input('kind', 'maintain');
        if (!isset(self::RECORD_KINDS[$kind])) $kind = 'maintain';
        $rid = (int) (DB::table('maintenance_records')->max('legacy_id') ?? 0) + 1;
        DB::table('maintenance_records')->insert([
            'legacy_id' => $rid, 'contract_id' => $id,
            'record_date' => $request->input('record_date') ?: now()->toDateString(),
            'kind' => $kind, 'content' => $content,
            'result' => trim((string) $request->input('result', '')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $rid]);
    }

    public function updateRecord(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $record = DB::table('maintenance_records')->where('legacy_id', $id)->first();
        if (!$record) return response()->json(['ok' => false, 'error' => '记录不存在'], 404);
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $record->contract_id)->first();
        if ($contract && $this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        $content = trim((string) $request->input('content', $record->content));
        if ($content === '') return response()->json(['ok' => false, 'error' => '记录内容不能为空'], 400);
        $kind = (string) $request->input('kind', $record->kind);
        if (!isset(self::RECORD_KINDS[$kind])) $kind = $record->kind;
        DB::table('maintenance_records')->where('legacy_id', $id)->update([
            'record_date' => $request->input('record_date') ?: $record->record_date,
            'kind' => $kind, 'content' => $content,
            'result' => trim((string) $request->input('result', $record->result)),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function deleteRecord(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $record = DB::table('maintenance_records')->where('legacy_id', $id)->first();
        if (!$record) return response()->json(['ok' => false, 'error' => '记录不存在'], 404);
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $record->contract_id)->first();
        if ($contract && $this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        foreach (DB::table('maintenance_attachments')->where('record_id', $id)->get() as $att) {
            Storage::disk('local')->delete('maintenance/atts/' . $att->file_name);
        }
        DB::table('maintenance_attachments')->where('record_id', $id)->delete();
        DB::table('maintenance_records')->where('legacy_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function uploadRecordAttachment(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $record = DB::table('maintenance_records')->where('legacy_id', $id)->first();
        if (!$record) return response()->json(['ok' => false, 'error' => '记录不存在'], 404);
        $contract = DB::table('maintenance_contracts')->where('legacy_id', $record->contract_id)->first();
        if (!$contract) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $contract->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到文件'], 400);
        $request->validate(['file' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,zip', 'max:51200']]);
        return response()->json(['ok' => true, 'attachment' => $this->storeAttachment($request, $contract, 'record', $id, $account)]);
    }

    /* ================= 设备台账 ================= */

    public function devices(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('maintenance_devices');
        if ($request->filled('type')) $query->where('type', $request->string('type'));
        if ($request->filled('project')) $query->where('project_name', trim((string) $request->input('project')));
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $today = now()->startOfDay();
        return response()->json(['ok' => true, 'devices' => $query->orderBy('project_name')->orderBy('device_no')->get()
            ->map(function ($d) use ($today) {
                $row = (array) $d; $row['id'] = (int) $d->legacy_id;
                // 年检状态：expired 已过期 / soon 30天内 / normal / missing 未登记
                if (!$d->inspect_date) $row['inspect_status'] = 'missing';
                else {
                    $dt = now()->parse($d->inspect_date)->startOfDay();
                    $row['inspect_status'] = $dt->lt($today) ? 'expired' : ($dt->lte($today->copy()->addDays(30)) ? 'soon' : 'normal');
                }
                return $row;
            })]);
    }

    public function saveDevice(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $type = $request->input('type') === 'elevator' ? 'elevator' : 'fire';
        $project = trim((string) $request->input('project_name'));
        if ($project === '') return response()->json(['ok' => false, 'error' => '项目不能为空'], 400);
        if ($this->isProjectScope($account) && $project !== (string) $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目设备'], 403);
        $deviceNo = trim((string) $request->input('device_no', ''));
        if ($deviceNo === '') return response()->json(['ok' => false, 'error' => '设备编号不能为空'], 400);
        $data = ['type' => $type, 'project_name' => $project, 'device_no' => $deviceNo,
            'load_kg' => $type === 'elevator' ? max(0, (int) $request->input('load_kg', 0)) : null,
            'floors' => $type === 'elevator' ? trim((string) $request->input('floors', '')) : null,
            'system_type' => $type === 'fire' ? trim((string) $request->input('system_type', '')) : null,
            'cover_area_sqm' => $type === 'fire' ? max(0, (float) $request->input('cover_area_sqm', 0)) : null,
            'inspect_date' => $request->input('inspect_date') ?: null,
            'remark' => trim((string) $request->input('remark', '')), 'updated_at' => now()];
        if ($id) {
            $row = DB::table('maintenance_devices')->where('legacy_id', $id)->first();
            if (!$row) return response()->json(['ok' => false, 'error' => '设备不存在'], 404);
            if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目设备'], 403);
            DB::table('maintenance_devices')->where('legacy_id', $id)->update($data);
        } else {
            $id = (int) (DB::table('maintenance_devices')->max('legacy_id') ?? 0) + 1;
            $data['legacy_id'] = $id; $data['created_at'] = now();
            DB::table('maintenance_devices')->insert($data);
        }
        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function deleteDevice(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_devices')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '设备不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权删除其他项目设备'], 403);
        DB::table('maintenance_devices')->where('legacy_id', $id)->delete();
        DB::table('maintenance_alert_logs')->where('obj_type', 'device')->where('obj_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /* ================= 签约方 ================= */

    public function partners(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        return response()->json(['ok' => true, 'partners' => DB::table('maintenance_partners')->orderBy('name')->get()]);
    }

    public function savePartner(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => '签约方名称不能为空'], 400);
        $data = ['name' => $name, 'type' => $request->input('type', 'both'), 'contact' => trim((string) $request->input('contact', '')),
            'phone' => trim((string) $request->input('phone', '')),
            'qualification_expire' => $request->input('qualification_expire') ?: null,
            'remark' => trim((string) $request->input('remark', '')), 'updated_at' => now()];
        if ($id) DB::table('maintenance_partners')->where('legacy_id', $id)->update($data);
        else { $id = (int) (DB::table('maintenance_partners')->max('legacy_id') ?? 0) + 1; $data['legacy_id'] = $id; $data['created_at'] = now(); DB::table('maintenance_partners')->insert($data); }
        return response()->json(['ok' => true, 'partner' => DB::table('maintenance_partners')->where('legacy_id', $id)->first()]);
    }

    public function deletePartner(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        DB::table('maintenance_partners')->where('legacy_id', $id)->delete();
        DB::table('maintenance_alert_logs')->where('obj_type', 'partner')->where('obj_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /* ================= 旧版单 PDF（兼容保留） ================= */

    public function uploadPdf(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到 PDF 文件'], 400);
        $request->validate(['file' => ['file', 'mimes:pdf', 'max:51200']]);
        $name = preg_replace('/[^A-Za-z0-9_.-]+/u', '_', $row->project_name . '_' . $row->type . '_' . $id . '.pdf');
        $path = $request->file('file')->storeAs('maintenance', $name, 'local');
        DB::table('maintenance_contracts')->where('legacy_id', $id)->update(['pdf_name' => basename($path), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'pdf_name' => basename($path)]);
    }

    public function pdf(Request $request, int $id)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row || !$row->pdf_name) return response('file not found', 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权访问其他项目合同'], 403);
        $path = 'maintenance/' . $row->pdf_name;
        if (!Storage::disk('local')->exists($path)) return response('file not found', 404);
        return Storage::disk('local')->download($path, $row->pdf_name, ['Content-Type' => 'application/pdf']);
    }

    /* ================= 台账 xlsx 导出 ================= */

    public function exportContracts(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $type = $request->input('type') === 'elevator' ? 'elevator' : 'fire';
        $query = DB::table('maintenance_contracts')->where('type', $type);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->orderBy('project_name')->orderBy('end_date')->get();
        $today = now()->startOfDay();
        $isFire = $type === 'fire';
        $typeLabel = $isFire ? '消防' : '电梯';

        $headers = ['序号', '项目名称', '合同编号', '签约方', '签约主体(甲方)', '乙方负责人', '乙方电话',
            '签约金额(元)', '签订日期', '生效开始', '到期日期', '状态'];
        if ($isFire) { $headers[] = '建筑面积(㎡)'; $headers[] = '每㎡单价(元)'; }
        else { $headers[] = '电梯台数'; $headers[] = '每台单价(元)'; }
        $headers = array_merge($headers, ['含配件耗材', '维保服务内容', '备注']);
        $colCount = count($headers);
        $lastLetter = Coordinate::stringFromColumnIndex($colCount);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($typeLabel . '维保台账');
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', $typeLabel . '维保合同台账（导出时间 ' . now()->format('Y-m-d H:i') . '）');
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->fromArray($headers, null, 'A2');
        for ($c = 1; $c <= $colCount; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $style = $sheet->getStyle("{$letter}2");
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            $style->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
            $style->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BFBFBF'));
        }
        $sheet->getRowDimension(2)->setRowHeight(30);
        $sheet->freezePane('A3');

        $rn = 3;
        foreach ($rows as $i => $row) {
            $status = MaintenanceService::contractStatus($row->end_date, $today);
            $values = [$i + 1, $row->project_name, $row->contract_no ?: '', $row->party, $row->party_a ?: '',
                $row->party_contact ?: '', $row->party_phone ?: '', (float) $row->amount,
                $row->sign_date ?: '', $row->start_date ?: '', $row->end_date ?: '', MaintenanceService::statusLabel($status)];
            if ($isFire) { $values[] = (float) $row->building_area_sqm; $values[] = (float) $row->price_per_sqm; }
            else { $values[] = (int) $row->elevator_count; $values[] = (float) $row->price_per_unit; }
            $values[] = $row->includes_supplies ? '是' : '否';
            $values[] = $row->service_scope ?: '';
            $values[] = $row->remark ?: '';
            $sheet->fromArray($values, null, 'A' . $rn);
            foreach ($values as $ci => $v) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . $rn, $v);
            }
            for ($c = 1; $c <= $colCount; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $cellStyle = $sheet->getStyle("{$letter}{$rn}");
                $cellStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('D9D9D9'));
                $cellStyle->getFont()->setName('微软雅黑')->setSize(10);
                if ($c === 8 || (!$isFire && $c === 15) || ($isFire && ($c === 13 || $c === 14))) {
                    $cellStyle->getNumberFormat()->setFormatCode('#,##0.00');
                    $cellStyle->getAlignment()->setHorizontal('right');
                } else {
                    $cellStyle->getAlignment()->setHorizontal('center')->setVertical('center');
                }
            }
            $rn++;
        }
        // 列宽
        for ($c = 1; $c <= $colCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(in_array($c, [$colCount - 1, $colCount - 2], true) ? 30 : 14);
        }
        $filename = $typeLabel . '维保台账_' . now()->format('Ymd') . '.xlsx';
        return $this->xlsx($book, $filename);
    }

    /* ================= 驾驶舱 / 报表 ================= */

    public function dashboard(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        // 年度覆盖：合同周期与该年度有交集即计入（无到期日合同恒计入，列为异常）
        $query = DB::table('maintenance_contracts')->whereYear('start_date', '<=', $year)
            ->where(function ($q) use ($year) { $q->whereNull('end_date')->orWhereYear('end_date', '>=', $year); });
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        $today = now()->startOfDay();

        $blank = ['normal' => 0, 'd90' => 0, 'd60' => 0, 'd30' => 0, 'expired' => 0, 'missing' => 0];
        $fire = $blank; $elev = $blank;
        $risk = [];
        // 合作方聚合：金额（分类型）、项目数、电梯台数
        $partnerAgg = [];   // party => ['name','fireAmount','elevAmount','projects'=>set,'projectCount','elevatorCount']
        // 项目聚合
        $projAgg = [];      // project => ['name','fireCount','fireAmount','fireTotalArea','elevCount','elevAmount','elevTotalUnits']

        foreach ($rows as $row) {
            $state = MaintenanceService::contractStatus($row->end_date, $today);
            $isFire = $row->type === 'fire';
            if ($isFire) $fire[$state]++; else $elev[$state]++;
            if ($state !== 'normal') {
                $risk[] = $this->contractArray($row, $today) + ['status' => $state, 'status_label' => MaintenanceService::statusLabel($state)];
            }

            $party = trim((string) $row->party) !== '' ? trim((string) $row->party) : '未填签约方';
            $project = (string) $row->project_name;
            if (!isset($partnerAgg[$party])) $partnerAgg[$party] = ['name' => $party, 'fireAmount' => 0.0, 'elevAmount' => 0.0, 'projects' => [], 'elevatorCount' => 0];
            if ($isFire) $partnerAgg[$party]['fireAmount'] += (float) $row->amount;
            else { $partnerAgg[$party]['elevAmount'] += (float) $row->amount; $partnerAgg[$party]['elevatorCount'] += (int) $row->elevator_count; }
            $partnerAgg[$party]['projects'][$project] = true;

            if (!isset($projAgg[$project])) $projAgg[$project] = ['name' => $project, 'fireCount' => 0, 'fireAmount' => 0.0, 'fireTotalArea' => 0.0,
                'elevCount' => 0, 'elevAmount' => 0.0, 'elevTotalUnits' => 0];
            if ($isFire) { $projAgg[$project]['fireCount']++; $projAgg[$project]['fireAmount'] += (float) $row->amount; $projAgg[$project]['fireTotalArea'] += (float) $row->building_area_sqm; }
            else { $projAgg[$project]['elevCount']++; $projAgg[$project]['elevAmount'] += (float) $row->amount; $projAgg[$project]['elevTotalUnits'] += (int) $row->elevator_count; }
        }

        $partners = array_map(function ($a) {
            return ['name' => $a['name'], 'projectCount' => count($a['projects']),
                'elevatorCount' => $a['elevatorCount'], 'totalAmount' => round($a['fireAmount'] + $a['elevAmount'], 2)];
        }, array_values($partnerAgg));
        $firePartners = array_values(array_map(fn ($a) => ['name' => $a['name'], 'totalAmount' => round($a['fireAmount'], 2)],
            array_filter($partnerAgg, fn ($a) => $a['fireAmount'] > 0)));
        $elevPartners = array_values(array_map(fn ($a) => ['name' => $a['name'], 'totalAmount' => round($a['elevAmount'], 2)],
            array_filter($partnerAgg, fn ($a) => $a['elevAmount'] > 0)));
        $projectAmounts = array_map(function ($p) {
            $p['fireAmount'] = round($p['fireAmount'], 2); $p['elevAmount'] = round($p['elevAmount'], 2); return $p;
        }, array_values($projAgg));
        usort($projectAmounts, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $fireRows = $rows->where('type', 'fire'); $elevRows = $rows->where('type', 'elevator');
        $overview = [
            'projectCount' => $rows->pluck('project_name')->unique()->count(),
            'fireCount' => $fireRows->count(), 'elevatorCount' => $elevRows->count(),
            'totalAmount' => round((float) $rows->sum('amount'), 2),
            'fireAmount' => round((float) $fireRows->sum('amount'), 2),
            'elevAmount' => round((float) $elevRows->sum('amount'), 2),
            'fireProjectCount' => $fireRows->pluck('project_name')->unique()->count(),
            'elevProjectCount' => $elevRows->pluck('project_name')->unique()->count(),
        ];
        // 风险按到期紧迫度排序（已过期优先，其次到期日近的优先，无到期日垫底）
        usort($risk, fn ($a, $b) => strcmp((string) ($a['end_date'] ?? '9999'), (string) ($b['end_date'] ?? '9999')));

        return response()->json(['ok' => true, 'year' => $year, 'riskTotal' => count($risk),
            'fire' => $fire, 'elev' => $elev, 'riskList' => $risk,
            'partners' => $partners, 'firePartners' => $firePartners, 'elevPartners' => $elevPartners,
            'projectAmounts' => $projectAmounts, 'overview' => $overview]);
    }

    /** 消防维保报表 */
    public function fireReport(Request $request): JsonResponse
    {
        return $this->maintReport($request, 'fire');
    }

    /** 电梯维保报表 */
    public function elevReport(Request $request): JsonResponse
    {
        return $this->maintReport($request, 'elevator');
    }

    /**
     * 维保报表统一聚合（按类型拆分）。
     * 含：分级状态分布 / 合作方金额 / 项目金额 / 面积或台数分布 / 风险与异常清单 /
     * 单位成本对标 / 同比 / 消防+电梯合并总览 / 项目覆盖率。
     */
    private function maintReport(Request $request, string $type): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        $isProject = $this->isProjectScope($account);
        $query = DB::table('maintenance_contracts')->where('type', $type)
            ->whereYear('start_date', '<=', $year)
            ->where(function ($q) use ($year) { $q->whereNull('end_date')->orWhereYear('end_date', '>=', $year); });
        if ($isProject) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        $today = now()->startOfDay();

        $stateCount = ['normal' => 0, 'd90' => 0, 'd60' => 0, 'd30' => 0, 'expired' => 0, 'missing' => 0];
        $risk = [];
        $abnormal = [];
        $partnerAmount = [];   // party => 金额
        $projectAmount = [];   // project => ['name','amount','count','scale']
        $partnerArea = [];     // party => 面积合计（消防）
        $partnerUnits = [];    // party => 台数合计（电梯）
        foreach ($rows as $row) {
            $state = MaintenanceService::contractStatus($row->end_date, $today);
            $stateCount[$state]++;
            if ($state !== 'normal') {
                $item = $this->contractArray($row, $today) + ['status' => $state,
                    'status_label' => MaintenanceService::statusLabel($state), 'project' => (string) $row->project_name];
                $risk[] = $item;
                if ($state === 'expired' || $state === 'missing') $abnormal[] = $item;
            }
            $party = trim((string) $row->party) !== '' ? trim((string) $row->party) : '未填签约方';
            $project = (string) $row->project_name;
            $partnerAmount[$party] = round(($partnerAmount[$party] ?? 0) + (float) $row->amount, 2);
            if (!isset($projectAmount[$project])) $projectAmount[$project] = ['name' => $project, 'amount' => 0.0, 'count' => 0, 'scale' => 0.0];
            $projectAmount[$project]['amount'] = round($projectAmount[$project]['amount'] + (float) $row->amount, 2);
            $projectAmount[$project]['count']++;
            $projectAmount[$project]['scale'] += $type === 'fire' ? (float) $row->building_area_sqm : (float) $row->elevator_count;
            if ($type === 'fire') $partnerArea[$party] = round(($partnerArea[$party] ?? 0) + (float) $row->building_area_sqm, 2);
            else $partnerUnits[$party] = ($partnerUnits[$party] ?? 0) + (int) $row->elevator_count;
        }
        arsort($partnerAmount); arsort($partnerArea); arsort($partnerUnits);
        usort($risk, fn ($a, $b) => strcmp((string) ($a['end_date'] ?? '9999'), (string) ($b['end_date'] ?? '9999')));
        usort($abnormal, fn ($a, $b) => strcmp((string) ($a['end_date'] ?? '9999'), (string) ($b['end_date'] ?? '9999')));
        $toChart = fn (array $m) => array_map(fn ($v, $k) => ['label' => MaintenanceService::statusLabel((string) $k) !== (string) $k ? MaintenanceService::statusLabel((string) $k) : (string) $k, 'value' => $v], array_values($m), array_keys($m));

        // 单位成本对标（元/㎡·年 或 元/台·年）
        $totalScale = array_sum(array_column($projectAmount, 'scale'));
        $totalAmount = (float) $rows->sum('amount');
        $avgCost = $totalScale > 0 ? round($totalAmount / $totalScale, 2) : null;
        $unitCosts = array_map(function ($p) use ($avgCost) {
            $unit = $p['scale'] > 0 ? round($p['amount'] / $p['scale'], 2) : null;
            return ['name' => $p['name'], 'amount' => round($p['amount'], 2), 'scale' => round($p['scale'], 2),
                'unit_cost' => $unit, 'diff_pct' => ($unit !== null && $avgCost) ? round(($unit - $avgCost) / $avgCost * 100, 1) : null];
        }, array_values($projectAmount));
        usort($unitCosts, fn ($a, $b) => ($b['unit_cost'] ?? -1) <=> ($a['unit_cost'] ?? -1));
        $projectList = array_values($projectAmount);
        usort($projectList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        // 同比：上一年同口径（同类型、同权限范围、年度交集）
        $prevQ = DB::table('maintenance_contracts')->where('type', $type)
            ->whereYear('start_date', '<=', $year - 1)->whereYear('end_date', '>=', $year - 1);
        if ($isProject) $prevQ->where('project_name', $account->project_name);
        $prevRows = $prevQ->get();
        $prevAmount = (float) $prevRows->sum('amount');
        $yoy = ['amount' => round($totalAmount, 2), 'prev_amount' => round($prevAmount, 2),
            'amount_pct' => $prevAmount > 0 ? round(($totalAmount - $prevAmount) / $prevAmount * 100, 1) : null,
            'count' => $rows->count(), 'prev_count' => $prevRows->count()];

        // 合并总览（消防+电梯，本年度）
        $combQ = DB::table('maintenance_contracts')->whereYear('start_date', '<=', $year)
            ->where(function ($q) use ($year) { $q->whereNull('end_date')->orWhereYear('end_date', '>=', $year); });
        if ($isProject) $combQ->where('project_name', $account->project_name);
        $comb = $combQ->get();
        $combFire = $comb->where('type', 'fire'); $combElev = $comb->where('type', 'elevator');
        $combined = ['fire_count' => $combFire->count(), 'fire_amount' => round((float) $combFire->sum('amount'), 2),
            'elev_count' => $combElev->count(), 'elev_amount' => round((float) $combElev->sum('amount'), 2),
            'total_count' => $comb->count(), 'total_amount' => round((float) $comb->sum('amount'), 2)];

        // 覆盖率：有设备但当前无有效维保合同的项目
        $devQ = DB::table('maintenance_devices')->where('type', $type);
        if ($isProject) $devQ->where('project_name', $account->project_name);
        $devRows = $devQ->get();
        $devByProject = $devRows->groupBy('project_name')->map->count();
        $validQ = DB::table('maintenance_contracts')->where('type', $type)
            ->whereNotNull('end_date')->whereDate('end_date', '>=', $today->toDateString())
            ->where(function ($q) use ($today) { $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today->toDateString()); });
        if ($isProject) $validQ->where('project_name', $account->project_name);
        $validProjects = $validQ->pluck('project_name')->flip();
        $uncovered = [];
        foreach ($devByProject as $proj => $cnt) {
            if (!isset($validProjects[$proj])) $uncovered[] = ['project' => $proj, 'device_count' => $cnt];
        }
        usort($uncovered, fn ($a, $b) => strcmp($a['project'], $b['project']));
        $coverage = ['device_total' => $devRows->count(), 'project_with_devices' => count($devByProject),
            'uncovered' => $uncovered, 'uncovered_count' => count($uncovered)];

        // 状态分布图保持 label/value 结构，但 label 用中文档位
        $stateChart = [];
        foreach ($stateCount as $k => $v) $stateChart[] = ['label' => MaintenanceService::statusLabel($k), 'value' => $v];

        return response()->json(['ok' => true, 'year' => $year, 'type' => $type,
            'state_dist' => $stateChart, 'partner_amount' => $toChart($partnerAmount),
            'project_amount' => $projectList, 'partner_scale' => $toChart($type === 'fire' ? $partnerArea : $partnerUnits),
            'risk_list' => $risk, 'risk_total' => count($risk), 'abnormal_list' => $abnormal,
            'unit_costs' => $unitCosts, 'avg_unit_cost' => $avgCost, 'yoy' => $yoy,
            'combined' => $combined, 'coverage' => $coverage,
            'total_amount' => round($totalAmount, 2), 'contract_count' => $rows->count(),
            'project_count' => $rows->pluck('project_name')->unique()->count()]);
    }

    /* ================= 内部工具 ================= */

    private function contractArray(object $row, $today = null): array
    {
        $result = (array) $row;
        $result['id'] = (int) $row->legacy_id;
        $result['status'] = MaintenanceService::contractStatus($row->end_date, $today);
        $result['status_label'] = MaintenanceService::statusLabel($result['status']);
        $result['includes_supplies'] = (int) ($row->includes_supplies ?? 0);
        return $result;
    }

    private function diffContract(object $old, array $new): array
    {
        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $new)) continue;
            $ov = $old->$field ?? null; $nv = $new[$field] ?? null;
            if (in_array($field, self::NUMERIC_FIELDS, true)) {
                if ((float) $ov === (float) $nv) continue;
            } elseif ((string) ($ov ?? '') === (string) ($nv ?? '')) continue;
            if ($field === 'includes_supplies') {
                $changes[$label] = ['old' => ((int) $ov) ? '是' : '否', 'new' => ((int) $nv) ? '是' : '否'];
            } else {
                $fmt = fn ($v) => ($v === null || $v === '') ? '空' : (string) $v;
                $changes[$label] = ['old' => $fmt($ov), 'new' => $fmt($nv)];
            }
        }
        return $changes;
    }

    private function logContract(int $contractId, object $account, string $action, array $changes): void
    {
        DB::table('maintenance_contract_logs')->insert([
            'contract_id' => $contractId, 'account_id' => $account->id ?? null,
            'account_name' => (string) ($account->name ?? ''), 'action' => $action,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function storeAttachment(Request $request, object $contract, string $kind, ?int $recordId, object $account): array
    {
        $file = $request->file('file');
        $attId = (int) (DB::table('maintenance_attachments')->max('legacy_id') ?? 0) + 1;
        $orig = $file->getClientOriginalName() ?: ('附件_' . $attId);
        $stored = $contract->legacy_id . '_' . $attId . '_' . preg_replace('/[^A-Za-z0-9_.-]+/u', '_', $orig);
        $path = $file->storeAs('maintenance/atts', $stored, 'local');
        DB::table('maintenance_attachments')->insert([
            'legacy_id' => $attId, 'contract_id' => (int) $contract->legacy_id, 'record_id' => $recordId,
            'kind' => $kind, 'name' => mb_substr($orig, 0, 200), 'file_name' => basename($path),
            'size' => (int) $file->getSize(), 'uploaded_by' => (string) ($account->name ?? ''),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->attachmentArray(DB::table('maintenance_attachments')->where('legacy_id', $attId)->first());
    }

    private function attachmentArray(object $a): array
    {
        return ['id' => (int) $a->legacy_id, 'contract_id' => (int) $a->contract_id,
            'record_id' => $a->record_id !== null ? (int) $a->record_id : null,
            'kind' => $a->kind, 'kind_label' => self::ATT_KINDS[$a->kind] ?? $a->kind,
            'name' => $a->name, 'size' => (int) $a->size, 'uploaded_by' => $a->uploaded_by, 'created_at' => $a->created_at];
    }

    private function xlsx(Spreadsheet $book, string $filename)
    {
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
