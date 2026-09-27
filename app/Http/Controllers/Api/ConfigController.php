<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfigController extends ApiController
{
    public function saveRules(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'calc_rules.json', ['rules' => $request->input('rules', [])]);
    }

    public function saveSymbols(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if (!is_array($items)) return response()->json(['ok' => false, 'error' => '符号数据格式错误'], 400);
        return $this->saveSnapshot($request, 'symbols.json', ['items' => $items]);
    }

    public function resetSymbols(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'symbols.json', ['items' => self::defaultSymbols()]);
    }

    public function importSymbols(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到配置文件'], 400);
        try {
            $data = json_decode($request->file('file')->get(), true, 512, JSON_THROW_ON_ERROR);
            $items = is_array($data) && isset($data['items']) ? $data['items'] : $data;
            if (!is_array($items)) throw new \RuntimeException('invalid symbols');
            return $this->saveSnapshot($request, 'symbols.json', ['items' => $items]);
        } catch (\Throwable $error) {
            return response()->json(['ok' => false, 'error' => '配置文件解析失败'], 400);
        }
    }

    public function savePayslip(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'payslip_config.json', ['config' => $request->input('config', [])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'settings.json', $request->input('settings', []));
    }

    private function saveSnapshot(Request $request, string $file, array $payload): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可修改配置'], 403);
        $old = DB::table('legacy_json_snapshots')->where('file_name', $file)->first();
        $data = $old ? ($this->jsonValue($old->payload) ?: []) : [];
        $data = array_replace_recursive($data, $payload);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => $file], [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE), 'imported_at' => now(),
        ]);
        return response()->json(['ok' => true] + $data);
    }

    public static function defaultSymbols(): array
    {
        return [
            ['symbol' => '√', 'name' => '正常出勤', 'in_required' => true, 'in_actual' => true, 'value' => 1, 'category' => '正常'],
            ['symbol' => '休', 'name' => '公休/休息日', 'in_required' => false, 'in_actual' => false, 'value' => 0, 'category' => '公休'],
            ['symbol' => '事', 'name' => '事假', 'in_required' => true, 'in_actual' => false, 'value' => 0, 'category' => '事假'],
            ['symbol' => '病', 'name' => '病假', 'in_required' => true, 'in_actual' => false, 'value' => 0, 'category' => '病假'],
            ['symbol' => '旷', 'name' => '旷工', 'in_required' => true, 'in_actual' => false, 'value' => 0, 'category' => '旷工'],
        ];
    }
}
