<?php

namespace App\Http\Controllers\Api;

use App\Invoice\InvoiceAttachments;
use App\Invoice\InvoiceNumbers;
use App\Invoice\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * 业主端公开接口：项目入口 token 进入，无需登录。
 */
class InvoicePublicController extends ApiController
{
    public function __construct(
        private InvoiceService $service,
        private InvoiceAttachments $attachments,
        private InvoiceNumbers $numbers,
    ) {
    }

    public function entry(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('p', ''));
        if ($token === '') {
            return response()->json(['ok' => false, 'error' => '缺少项目入口参数', 'code' => 'ENTRY_REQUIRED'], 400);
        }

        $binding = DB::table('invoice_project_bindings')->where('entry_token', $token)->first();
        if (!$binding || !$binding->enabled) {
            return response()->json(['ok' => false, 'error' => '该项目暂未开通开票', 'code' => 'PROJECT_DISABLED'], 404);
        }
        $accountSet = DB::table('invoice_account_sets')->where('id', $binding->account_set_id)->first();
        if (!$accountSet || $accountSet->status !== 'enabled') {
            return response()->json(['ok' => false, 'error' => '该项目暂未开通开票', 'code' => 'ACCOUNT_SET_DISABLED'], 404);
        }

        $defaults = config('invoice.defaults');
        return response()->json([
            'ok' => true,
            'project' => ['id' => (int) $binding->project_id, 'name' => $binding->project_name],
            'seller' => ['name' => $accountSet->seller_name, 'tax_no' => $this->maskTaxNo($accountSet->seller_tax_no)],
            'available' => true,
            'rules' => [
                'amount_limit' => (float) $accountSet->amount_limit,
                'house_no_regex' => $defaults['house_no_regex'],
                'attachments_min' => (int) $defaults['attachments_min'],
                'attachments_max' => (int) $defaults['attachments_max'],
                'attachment_max_kb' => (int) $defaults['attachment_max_kb'],
                'attachments_total_kb' => (int) $defaults['attachments_total_kb'],
                'accepted_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],
            ],
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('p', $request->query('p', '')));
        $binding = DB::table('invoice_project_bindings')->where('entry_token', $token)->first();
        if (!$binding || !$binding->enabled) {
            return response()->json(['ok' => false, 'error' => '该项目暂未开通开票', 'code' => 'PROJECT_DISABLED'], 404);
        }
        $project = DB::table('payroll_projects')->where('id', $binding->project_id)->first();
        $accountSet = DB::table('invoice_account_sets')->where('id', $binding->account_set_id)->first();
        if (!$project || !$accountSet || $accountSet->status !== 'enabled') {
            return response()->json(['ok' => false, 'error' => '该项目暂未开通开票', 'code' => 'ACCOUNT_SET_DISABLED'], 404);
        }

        $defaults = config('invoice.defaults');
        $validator = Validator::make($request->all(), [
            'house_no' => ['required', 'string', 'max:64'],
            'payer_name' => ['required', 'string', 'max:120'],
            'declared_amount' => ['required', 'numeric', 'min:0.01', 'max:' . (float) $accountSet->amount_limit],
            'title_type' => ['required', 'in:personal,company'],
            'title' => ['required', 'string', 'max:200'],
            'buyer_tax_no' => ['nullable', 'string', 'max:32'],
            'buyer_email' => ['required', 'email', 'max:120'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
            'fee_period' => ['nullable', 'string', 'max:64'],
            'remark' => ['nullable', 'string', 'max:500'],
            'client_request_no' => ['nullable', 'string', 'max:32'],
            'resubmit_no' => ['nullable', 'string', 'max:32'],
            'query_key' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => '参数校验失败', 'code' => 'VALIDATION_FAILED', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        $regex = (string) ($defaults['house_no_regex'] ?? '');
        if ($regex !== '' && !preg_match($regex, (string) $data['house_no'])) {
            return response()->json(['ok' => false, 'error' => '房号格式不正确，请按「楼栋-单元-房号」填写', 'code' => 'HOUSE_NO_INVALID'], 422);
        }
        if ($data['title_type'] === 'company') {
            $taxNo = strtoupper(trim((string) ($data['buyer_tax_no'] ?? '')));
            if (!$this->validTaxNo($taxNo)) {
                return response()->json(['ok' => false, 'error' => '企业抬头需填写有效的统一社会信用代码', 'code' => 'TAX_NO_INVALID'], 422);
            }
            $data['buyer_tax_no'] = $taxNo;
        } else {
            $data['buyer_tax_no'] = null;
        }

        $files = $request->file('attachments', []);
        if ($files instanceof \Illuminate\Http\UploadedFile) {
            $files = [$files];
        }
        $files = array_values(array_filter((array) $files));

        if (!empty($data['resubmit_no'])) {
            if (empty($data['query_key'])) {
                return response()->json(['ok' => false, 'error' => '重提需要查询密钥', 'code' => 'QUERY_KEY_REQUIRED'], 422);
            }
            if (count($files) < (int) $defaults['attachments_min']) {
                return response()->json(['ok' => false, 'error' => '请至少上传 1 张缴费凭证', 'code' => 'ATTACHMENT_REQUIRED'], 422);
            }
        }

        if (count($files) < (int) $defaults['attachments_min'] || count($files) > (int) $defaults['attachments_max']) {
            return response()->json([
                'ok' => false,
                'error' => '缴费凭证需上传 ' . $defaults['attachments_min'] . '–' . $defaults['attachments_max'] . ' 张',
                'code' => 'ATTACHMENT_COUNT',
            ], 422);
        }

        $totalKb = 0;
        foreach ($files as $file) {
            if (!$file->isValid()) {
                return response()->json(['ok' => false, 'error' => '附件上传失败，请重试', 'code' => 'ATTACHMENT_INVALID'], 422);
            }
            if (!in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'pdf'], true)) {
                return response()->json(['ok' => false, 'error' => '凭证仅支持 jpg/png/pdf', 'code' => 'ATTACHMENT_TYPE'], 422);
            }
            $sizeKb = (int) ceil($file->getSize() / 1024);
            if ($sizeKb > (int) $defaults['attachment_max_kb']) {
                return response()->json(['ok' => false, 'error' => '单张凭证不得超过 5MB', 'code' => 'ATTACHMENT_TOO_LARGE'], 422);
            }
            $totalKb += $sizeKb;
        }
        if ($totalKb > (int) $defaults['attachments_total_kb']) {
            return response()->json(['ok' => false, 'error' => '凭证合计不得超过 12MB', 'code' => 'ATTACHMENT_TOTAL_TOO_LARGE'], 422);
        }

        try {
            [$row, $queryKey, $warnings, $reused] = $this->service->create($data, $files, $project, $accountSet, $request);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'code' => 'APPLY_REJECTED'], 422);
        }

        return response()->json([
            'ok' => true,
            'request_no' => $row->request_no,
            'query_key' => $queryKey,
            'status' => $row->status,
            'declared_amount' => (float) $row->declared_amount,
            'warnings' => $warnings,
            'reused' => $reused,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $row = $this->findByKey($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }

        $logs = DB::table('invoice_request_logs')
            ->where('request_id', $row->id)
            ->whereNotNull('to_status')
            ->orderBy('id')
            ->get(['to_status', 'action', 'created_at'])
            ->map(fn ($l) => ['status' => $l->to_status, 'action' => $l->action, 'at' => $l->created_at])
            ->all();

        return response()->json([
            'ok' => true,
            'request' => [
                'request_no' => $row->request_no,
                'status' => $row->status,
                'project_name' => $row->project_name,
                'seller_name' => $row->seller_name_snapshot,
                'house_no' => $row->house_no,
                'payer_name' => $row->payer_name,
                'declared_amount' => (float) $row->declared_amount,
                'verified_amount' => $row->verified_amount !== null ? (float) $row->verified_amount : null,
                'title' => $row->title,
                'title_type' => $row->title_type,
                'buyer_email' => $this->maskEmail((string) $row->buyer_email),
                'fee_period' => $row->fee_period,
                'reject_reason' => $row->reject_reason,
                'digital_invoice_no' => $row->digital_invoice_no,
                'invoice_date' => $row->invoice_date,
                'simulated' => (bool) $row->simulated,
                'issuer' => $row->issuer,
                'fail_msg' => $row->fail_msg,
                'can_resubmit' => $row->status === 'rejected',
                'can_cancel' => $row->status === 'submitted',
                'can_download' => (bool) $row->pdf_path && in_array($row->status, ['issued', 'delivering', 'delivered', 'delivered_failed'], true),
                'can_download_ofd' => (bool) $row->ofd_path && in_array($row->status, ['issued', 'delivering', 'delivered', 'delivered_failed'], true),
            ],
            'attachments' => $this->attachments->forRequest((int) $row->id),
            'timeline' => $logs,
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $row = $this->findByKey($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }
        if ($row->status !== 'submitted') {
            return response()->json(['ok' => false, 'error' => '当前状态不可撤回', 'code' => 'STATUS_NOT_CANCELABLE'], 422);
        }
        $canceled = $this->service->cancel($row, 'owner', null, $request);
        return response()->json(['ok' => true, 'status' => $canceled->status]);
    }

    public function download(Request $request, string $requestNo): mixed
    {
        $key = (string) $request->query('k', '');
        $row = DB::table('invoice_requests')->where('request_no', $requestNo)->first();
        if (!$row || $key === '' || !hash_equals((string) $row->query_key_hash, $this->numbers->hashKey($key))) {
            return response()->json(['ok' => false, 'error' => '查询密钥不正确', 'code' => 'QUERY_KEY_INVALID'], 403);
        }
        return $this->serveInvoice($row, (string) $request->query('f', 'pdf'));
    }

    public function attachment(Request $request, int $id): mixed
    {
        $exp = (int) $request->query('exp', 0);
        $sig = (string) $request->query('sig', '');
        if (!$this->attachments->verify($id, $exp, $sig)) {
            return response()->json(['ok' => false, 'error' => '预览链接已失效', 'code' => 'SIGNATURE_INVALID'], 403);
        }
        $row = $this->attachments->find($id);
        if (!$row) {
            return response()->json(['ok' => false, 'error' => '附件不存在'], 404);
        }
        $path = $this->attachments->absolutePath($row);
        if (!$path) {
            return response()->json(['ok' => false, 'error' => '附件文件缺失'], 404);
        }
        return response()->file($path, ['Content-Type' => $row->mime]);
    }

    public function serveInvoice(object $row, string $format = 'pdf'): mixed
    {
        $format = strtolower($format) === 'ofd' ? 'ofd' : 'pdf';
        $path = $format === 'ofd' ? $row->ofd_path : $row->pdf_path;
        if (!$path) {
            return response()->json(['ok' => false, 'error' => $format === 'ofd' ? 'OFD 文件尚未生成' : '发票文件尚未生成'], 404);
        }
        $disk = Storage::disk((string) config('invoice.storage.disk', 'local'));
        if (!$disk->exists($path)) {
            return response()->json(['ok' => false, 'error' => '发票文件缺失'], 404);
        }
        $mime = $format === 'ofd' ? 'application/ofd' : 'application/pdf';
        return response()->download($disk->path($path), 'invoice-' . $row->request_no . '.' . $format, ['Content-Type' => $mime]);
    }

    private function findByKey(Request $request): mixed
    {
        $no = trim((string) $request->query('no', $request->input('no', '')));
        $key = trim((string) $request->query('k', $request->input('k', '')));
        if ($no === '' || $key === '') {
            return response()->json(['ok' => false, 'error' => '缺少查询参数', 'code' => 'QUERY_REQUIRED'], 400);
        }
        $row = DB::table('invoice_requests')->where('request_no', $no)->first();
        if (!$row || !hash_equals((string) $row->query_key_hash, $this->numbers->hashKey($key))) {
            return response()->json(['ok' => false, 'error' => '单号或查询密钥不正确', 'code' => 'QUERY_INVALID'], 403);
        }
        return $row;
    }

    private function validTaxNo(string $taxNo): bool
    {
        if (preg_match('/^[0-9A-HJ-NPQRTUWXY]{15}$|^[0-9A-HJ-NPQRTUWXY]{17}$|^[0-9A-HJ-NPQRTUWXY]{20}$/', $taxNo)) {
            return true;
        }
        if (!preg_match('/^[0-9A-HJ-NPQRTUWXY]{18}$/', $taxNo)) {
            return false;
        }
        $chars = '0123456789ABCDEFGHJKLMNPQRTUWXY';
        $weights = [1, 3, 9, 27, 19, 26, 16, 17, 20, 29, 25, 13, 8, 24, 10, 30, 28];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $pos = strpos($chars, $taxNo[$i]);
            if ($pos === false) {
                return false;
            }
            $sum += $pos * $weights[$i];
        }
        $check = 31 - ($sum % 31);
        if ($check === 31) {
            $check = 0;
        }
        return $chars[$check] === $taxNo[17];
    }

    private function maskTaxNo(string $taxNo): string
    {
        return mb_strlen($taxNo) > 8 ? mb_substr($taxNo, 0, 4) . '****' . mb_substr($taxNo, -4) : $taxNo;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$name, $domain] = explode('@', $email, 2);
        return mb_substr($name, 0, 2) . '***@' . $domain;
    }
}
