<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 停车场登记模块：司机端公开接口（凭门 entry token）+ 管理端（X-Token）。
 * 设计：docs/superpowers/specs/2026-11-24-parking-register-design.md
 * 公开接口照业主开票模式（无中间件，token + 启用开关；GATE_DISABLED / ENTRY_REQUIRED）。
 */
class ParkingController extends ApiController
{
    /** 车牌号（普通 7 位 / 新能源 8 位） */
    private const PLATE_RE = '/^[京津沪渝冀晋蒙辽吉黑苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云藏陕甘青宁新使领][A-HJ-NP-Z][A-HJ-NP-Z0-9]{4,5}[A-HJ-NP-Z0-9挂学警港澳]$/u';

    private const DEFAULT_REASONS = ['拜访', '送货', '施工', '快递', '其他'];

    private const RATE_LIMIT_PER_MIN = 10;

    // ================== 司机端（公开） ==================

    public function entry(Request $request): JsonResponse
    {
        $gate = $this->gateByToken((string) $request->query('p'), $code);
        if ($gate === null) return $this->pubFail($code);
        $project = DB::table('payroll_projects')->where('id', $gate->project_id)->first();
        return response()->json([
            'ok' => true,
            'project_name' => $project->name ?? '',
            'gate_name' => $gate->name,
            'config' => $this->publicConfig(),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $gate = $this->gateByToken((string) ($request->input('p') ?: $request->query('p')), $code);
        if ($gate === null) return $this->pubFail($code);
        $project = DB::table('payroll_projects')->where('id', $gate->project_id)->first();

        $ip = (string) $request->ip();
        $rlKey = 'parking_rl_' . md5($ip);
        $count = (int) Cache::get($rlKey, 0);
        if ($count >= self::RATE_LIMIT_PER_MIN) {
            return $this->pubFail('TOO_MANY_REQUESTS', 429, 'TOO_MANY_REQUESTS');
        }
        Cache::put($rlKey, $count + 1, 60);

        $in = $request->json()->all() ?: $request->all();
        $plate = strtoupper(str_replace([' ', '·', '。', '.'], '', trim((string) ($in['plate'] ?? ''))));
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $visit = trim((string) ($in['visit_target'] ?? ''));
        $reason = trim((string) ($in['reason'] ?? ''));

        if (!preg_match(self::PLATE_RE, $plate)) return $this->fail422('请填写正确的车牌号');
        if ($name === '' || mb_strlen($name) > 60) return $this->fail422('请填写司机姓名');
        if (!preg_match('/^1\d{10}$/', $phone)) return $this->fail422('请填写正确的手机号');
        if ($visit === '' || mb_strlen($visit) > 120) return $this->fail422('请填写访问户号/受访人');

        $cfg = $this->configData();
        $reasons = is_array($cfg['reasons'] ?? null) && $cfg['reasons'] !== []
            ? array_values(array_map('strval', $cfg['reasons']))
            : self::DEFAULT_REASONS;
        if (!in_array($reason, $reasons, true)) return $this->fail422('请选择事由');

        $extra = [];
        foreach (is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [] as $f) {
            if (($f['enabled'] ?? true) !== true) continue;
            $key = (string) ($f['key'] ?? '');
            $label = trim((string) ($f['label'] ?? ''));
            if ($key === '' || $label === '') continue;
            $val = $in['extra'][$key] ?? '';
            if (is_array($val)) return $this->fail422("「{$label}」格式不正确");
            $val = trim((string) $val);
            if ($val === '') {
                if (!empty($f['required'])) return $this->fail422("请填写「{$label}」");
                continue;
            }
            if (mb_strlen($val) > 100) return $this->fail422("「{$label}」内容过长");
            $type = (string) ($f['type'] ?? 'text');
            if ($type === 'number' && !is_numeric($val)) return $this->fail422("「{$label}」必须为数字");
            if ($type === 'radio') {
                $options = array_map('strval', array_filter(array_map('trim', (array) ($f['options'] ?? [])), fn($o) => $o !== ''));
                if ($options !== [] && !in_array($val, $options, true)) return $this->fail422("「{$label}」选项不正确");
            }
            $extra[$key] = $val;
        }

        DB::table('parking_registrations')->insert([
            'gate_id' => $gate->id,
            'project_id' => $gate->project_id,
            'gate_name' => $gate->name,
            'project_name' => $project->name ?? '',
            'plate' => $plate,
            'name' => $name,
            'phone' => $phone,
            'visit_target' => $visit,
            'reason' => $reason,
            'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'ip' => substr($ip, 0, 45),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json([
            'ok' => true,
            'project_name' => $project->name ?? '',
            'gate_name' => $gate->name,
            'plate' => $plate,
            'time' => now()->format('Y-m-d H:i'),
        ]);
    }

    // ================== 管理端（X-Token） ==================

    public function gatesList(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($e = $this->adminOrFail($account)) return $e;
        $rows = DB::table('parking_gates as g')
            ->leftJoin('payroll_projects as p', 'p.id', '=', 'g.project_id')
            ->orderBy('p.id')->orderBy('g.id')
            ->get(['g.*', 'p.name as project_name']);
        return response()->json(['ok' => true, 'gates' => $rows->map(fn($r) => $this->gateOut($r))->all()]);
    }

    public function gatesSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($e = $this->adminOrFail($account)) return $e;
        $in = $request->json()->all() ?: $request->all();
        $id = (int) ($in['id'] ?? 0);
        $projectId = (int) ($in['project_id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($projectId <= 0 || !DB::table('payroll_projects')->where('id', $projectId)->exists()) {
            return response()->json(['ok' => false, 'error' => '小区不存在'], 422);
        }
        if ($name === '' || mb_strlen($name) > 60) {
            return response()->json(['ok' => false, 'error' => '门名称必填且不超过 60 字'], 422);
        }
        $dup = DB::table('parking_gates')->where('project_id', $projectId)->where('name', $name);
        if ($id > 0) $dup->where('id', '<>', $id);
        if ($dup->exists()) return response()->json(['ok' => false, 'error' => '该小区已有同名门'], 422);

        if ($id > 0) {
            $gate = DB::table('parking_gates')->where('id', $id)->first();
            if (!$gate) return response()->json(['ok' => false, 'error' => '门不存在'], 404);
            $upd = ['name' => $name, 'enabled' => !empty($in['enabled']) ? 1 : 0, 'updated_at' => now()];
            if (!empty($in['reset_token'])) $upd['entry_token'] = Str::random(48);
            DB::table('parking_gates')->where('id', $id)->update($upd);
        } else {
            $id = DB::table('parking_gates')->insertGetId([
                'project_id' => $projectId,
                'name' => $name,
                'entry_token' => Str::random(48),
                'enabled' => !empty($in['enabled']) ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $gate = DB::table('parking_gates as g')
            ->leftJoin('payroll_projects as p', 'p.id', '=', 'g.project_id')
            ->where('g.id', $id)
            ->first(['g.*', 'p.name as project_name']);
        return response()->json(['ok' => true, 'gate' => $this->gateOut($gate)]);
    }

    public function gatesDelete(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($e = $this->adminOrFail($account)) return $e;
        $id = (int) ($request->json('id') ?? $request->input('id') ?? 0);
        if ($id <= 0 || !DB::table('parking_gates')->where('id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => '门不存在'], 404);
        }
        // 登记记录保留（快照已冗余小区/门名），仅删门；旧二维码随之失效
        DB::table('parking_gates')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function configGet(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($e = $this->adminOrFail($account)) return $e;
        return response()->json(['ok' => true, 'config' => $this->configData()]);
    }

    public function configSave(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($e = $this->adminOrFail($account)) return $e;
        $in = $request->json()->all() ?: $request->all();
        $reasons = array_values(array_filter(array_map(
            fn($r) => mb_substr(trim((string) $r), 0, 20),
            (array) ($in['reasons'] ?? [])
        ), fn($r) => $r !== ''));
        $fields = [];
        $seen = [];
        foreach ((array) ($in['fields'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 30);
            if ($label === '') continue;
            $type = in_array($f['type'] ?? 'text', ['text', 'number', 'radio'], true) ? $f['type'] : 'text';
            $key = (string) ($f['key'] ?? '');
            if ($key === '' || !preg_match('/^[a-f0-9]{8}$/', $key) || isset($seen[$key])) {
                $key = substr(md5($label), 0, 8);
                $n = 0;
                while (isset($seen[$key])) $key = substr(md5($label . (++$n)), 0, 8);
            }
            $seen[$key] = true;
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($f['required']),
                'enabled' => !empty($f['enabled']),
                'options' => $type === 'radio'
                    ? array_values(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 30), (array) ($f['options'] ?? [])), fn($o) => $o !== ''))
                    : [],
            ];
        }
        $payload = json_encode(['reasons' => $reasons, 'fields' => $fields], JSON_UNESCAPED_UNICODE);
        $exists = DB::table('legacy_json_snapshots')->where('file_name', 'parking_config.json')->exists();
        if ($exists) {
            DB::table('legacy_json_snapshots')->where('file_name', 'parking_config.json')->update([
                'payload' => $payload, 'updated_at' => now(),
            ]);
        } else {
            DB::table('legacy_json_snapshots')->insert([
                'file_name' => 'parking_config.json', 'payload' => $payload,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return response()->json(['ok' => true, 'config' => $this->configData()]);
    }

    public function stats(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $projectId = $this->scopeProjectId($account);
        $base = DB::table('parking_registrations');
        if ($projectId !== null) $base->where('project_id', $projectId);
        $now = now();
        $today = (clone $base)->whereBetween('created_at', [$now->copy()->startOfDay(), $now])->count();
        $week = (clone $base)->whereBetween('created_at', [$now->copy()->subDays(6)->startOfDay(), $now])->count();
        $month = (clone $base)->whereBetween('created_at', [$now->copy()->startOfMonth(), $now])->count();
        $total = (clone $base)->count();

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i);
            $c = (clone $base)->whereBetween('created_at', [$d->copy()->startOfDay(), $d->copy()->endOfDay()])->count();
            $series[] = ['date' => $d->format('m-d'), 'count' => $c];
        }
        $byProject = (clone $base)
            ->whereBetween('created_at', [$now->copy()->startOfDay(), $now])
            ->groupBy('project_name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(10)
            ->get([DB::raw('COALESCE(project_name,\'\') as project_name'), DB::raw('COUNT(*) as c')])
            ->all();
        return response()->json(['ok' => true, 'kpi' => [
            'today' => $today, 'week' => $week, 'month' => $month, 'total' => $total,
        ], 'series' => $series, 'by_project' => array_map(fn($r) => [
            'project_name' => $r->project_name, 'count' => (int) $r->c,
        ], $byProject)]);
    }

    public function list(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        [$from, $to, $projectId, $gateId] = $this->listFilters($request, $account);
        $q = DB::table('parking_registrations');
        $q = $this->applyFilters($q, $from, $to, $projectId, $gateId);
        $total = (clone $q)->count();
        $page = max(1, (int) ($request->query('page', 1)));
        $rows = $q->orderByDesc('id')->forPage($page, 50)
            ->get()->map(fn($r) => $this->rowOut($r))->all();
        return response()->json(['ok' => true, 'total' => $total, 'page' => $page, 'rows' => $rows]);
    }

    public function export(Request $request): mixed
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        [$from, $to, $projectId, $gateId] = $this->listFilters($request, $account);
        $q = DB::table('parking_registrations');
        $q = $this->applyFilters($q, $from, $to, $projectId, $gateId);
        $rows = $q->orderByDesc('id')->limit(50000)->get();

        $cfg = $this->configData();
        $customFields = array_values(array_filter(
            is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [],
            fn($f) => ($f['enabled'] ?? true) === true && (string) ($f['label'] ?? '') !== ''
        ));
        $headers = ['登记时间', '小区', '门', '车牌号', '司机姓名', '手机号', '访问户号/受访人', '事由'];
        foreach ($customFields as $f) $headers[] = (string) $f['label'];

        $data = [$headers];
        foreach ($rows as $r) {
            $extra = $this->jsonValue($r->extra, []) ?: [];
            $line = [
                (string) $r->created_at, (string) $r->project_name, (string) $r->gate_name,
                (string) $r->plate, (string) $r->name, (string) $r->phone,
                (string) $r->visit_target, (string) $r->reason,
            ];
            foreach ($customFields as $f) {
                $line[] = (string) ($extra[$f['key']] ?? '');
            }
            $data[] = $line;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data, null, 'A1');
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')
            ->getFont()->setBold(true);
        foreach (range(1, count($headers)) as $ci) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }
        $fileName = '停车登记_' . ($from ?: '全部') . '_' . ($to ?: '') . '.xlsx';
        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ================== 私有 ==================

    private function gateByToken(string $token, ?string &$errCode = null): ?object
    {
        $token = trim($token);
        if ($token === '') {
            $errCode = 'ENTRY_REQUIRED';
            return null;
        }
        $gate = DB::table('parking_gates')->where('entry_token', $token)->first();
        if (!$gate || !$gate->enabled) {
            $errCode = 'GATE_DISABLED';
            return null;
        }
        return $gate;
    }

    private function pubFail(string $code): JsonResponse
    {
        return match ($code) {
            'ENTRY_REQUIRED' => response()->json(['ok' => false, 'error' => '缺少入口参数', 'code' => $code], 400),
            'GATE_DISABLED' => response()->json(['ok' => false, 'error' => '入口不存在或已停用', 'code' => $code], 404),
            default => response()->json(['ok' => false, 'error' => '提交过于频繁，请稍后再试', 'code' => $code], 429),
        };
    }

    private function fail422(string $error): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error, 'code' => 'VALIDATION_FAILED'], 422);
    }

    private function adminOrFail(object $account): ?JsonResponse
    {
        if (($account->role ?? '') !== 'admin') {
            return response()->json(['ok' => false, 'error' => '无权限'], 403);
        }
        return null;
    }

    /** 数据范围：admin = null（全部）；项目账号 = 自己小区 id（未绑定项目 → -1 看不到任何数据） */
    private function scopeProjectId(object $account): ?int
    {
        if (($account->role ?? '') === 'admin') return null;
        $name = (string) ($account->project_name ?? '');
        if ($name === '') return -1;
        $p = DB::table('payroll_projects')->where('name', $name)->first();
        return $p?->id ?? -1;
    }

    /** @return array{0:string,1:string,2:?int,3:int} from/to/projectId/gateId */
    private function listFilters(Request $request, object $account): array
    {
        $from = $this->dateOrNull((string) $request->query('from', ''));
        $to = $this->dateOrNull((string) $request->query('to', ''));
        $forced = $this->scopeProjectId($account);
        $projectId = $forced !== null ? $forced : ((int) $request->query('project_id', 0) ?: null);
        $gateId = (int) $request->query('gate_id', 0) ?: null;
        return [$from, $to, $projectId, $gateId];
    }

    private function applyFilters($q, ?string $from, ?string $to, ?int $projectId, ?int $gateId)
    {
        if ($from !== null) $q->where('created_at', '>=', $from . ' 00:00:00');
        if ($to !== null) $q->where('created_at', '<=', $to . ' 23:59:59');
        if ($projectId !== null) $q->where('project_id', $projectId);
        if ($gateId !== null) $q->where('gate_id', $gateId);
        return $q;
    }

    private function dateOrNull(string $v): ?string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private function rowOut(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'time' => (string) $r->created_at,
            'project_name' => (string) $r->project_name,
            'gate_name' => (string) $r->gate_name,
            'plate' => (string) $r->plate,
            'name' => (string) $r->name,
            'phone' => (string) $r->phone,
            'visit_target' => (string) $r->visit_target,
            'reason' => (string) $r->reason,
            'extra' => $this->jsonValue($r->extra, []) ?: [],
        ];
    }

    private function gateOut(object $r): array
    {
        $url = rtrim((string) config('app.url'), '/') . '/parking.html?p=' . $r->entry_token;
        return [
            'id' => (int) $r->id,
            'project_id' => (int) $r->project_id,
            'project_name' => (string) ($r->project_name ?? ''),
            'name' => (string) $r->name,
            'enabled' => (bool) $r->enabled,
            'entry_url' => $url,
            'label' => (($r->project_name ?? '') !== '' ? $r->project_name . ' · ' : '') . $r->name,
        ];
    }

    private function publicConfig(): array
    {
        $cfg = $this->configData();
        $fields = array_values(array_filter(
            is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [],
            fn($f) => ($f['enabled'] ?? true) === true && (string) ($f['label'] ?? '') !== ''
        ));
        return [
            'reasons' => is_array($cfg['reasons'] ?? null) && $cfg['reasons'] !== []
                ? array_values(array_map('strval', $cfg['reasons']))
                : self::DEFAULT_REASONS,
            'fields' => array_map(fn($f) => [
                'key' => (string) $f['key'],
                'label' => (string) $f['label'],
                'type' => (string) ($f['type'] ?? 'text'),
                'required' => (bool) ($f['required'] ?? false),
                'options' => array_values((array) ($f['options'] ?? [])),
            ], $fields),
        ];
    }

    private function configData(): array
    {
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'parking_config.json')->first();
        return $snapshot ? ($this->jsonValue($snapshot->payload) ?: []) : [];
    }
}
