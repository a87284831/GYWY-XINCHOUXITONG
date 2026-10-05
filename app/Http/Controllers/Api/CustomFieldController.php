<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomFieldController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $payload = $snap ? (json_decode((string)$snap->payload, true) ?: []) : [];
        return response()->json(['ok' => true, 'fields' => $payload['rules']['custom_fields'] ?? []]);
    }

    public function save(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可修改配置'], 403);
        $fields = $request->input('fields', []);
        if (!is_array($fields)) return response()->json(['ok' => false, 'error' => '字段数据格式错误'], 400);
        $clean = [];
        foreach ($fields as $f) {
            if (!is_array($f) || empty($f['name'])) continue;
            $type = ($f['type'] ?? '') === 'deduction' ? 'deduction' : 'subsidy';
            $clean[] = [
                'name' => trim((string)$f['name']),
                'type' => $type,
                'enabled' => !empty($f['enabled']),
                'default' => round((float)($f['default'] ?? 0), 2),
            ];
        }
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $data = $snap ? (json_decode((string)$snap->payload, true) ?: []) : [];
        $data['rules']['custom_fields'] = $clean;
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'calc_rules.json'], [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'imported_at' => now(),
        ]);
        return response()->json(['ok' => true, 'fields' => $clean]);
    }
}
