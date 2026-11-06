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
        if ($error = $this->validateSymbolItems($items)) return $error;
        // 改 in_actual / in_required / value 会改变未归档月份的核算结果：先把受影响人数与月份报出来，
        // 前端二次确认后带 confirm=1 再保存，避免"改一个勾选项，工资静默变化"。
        $impact = \App\Services\PayrollCalculator::symbolImpact($this->currentSymbolItems(), $items);
        if ($impact['people'] > 0 && !$request->boolean('confirm')) {
            $months = [];
            foreach ($impact['months'] as $ym => $n) $months[] = "{$ym}（{$n}人）";
            return response()->json([
                'ok' => false, 'need_confirm' => true, 'affected' => $impact,
                'error' => '修改符号属性会影响未归档月份的核算：' . implode('、', $months)
                    . "，共 {$impact['people']} 人。确认无误后请再次保存（confirm=1）。",
            ], 409);
        }
        return $this->saveSnapshot($request, 'symbols.json', ['items' => array_values($items)], true);
    }

    public function resetSymbols(Request $request): JsonResponse
    {
        $items = self::defaultSymbols();
        // 恢复默认同样要过校验与历史兼容扫描：默认库没有的自定义符号若仍被历史考勤使用，不能静默丢弃
        if ($error = $this->validateSymbolItems($items)) return $error;
        return $this->saveSnapshot($request, 'symbols.json', ['items' => $items], true);
    }

    /** 当前生效的符号库（用于比较属性变更影响面） */
    private function currentSymbolItems(): array
    {
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'symbols.json')->first();
        $data = $snap ? ($this->jsonValue($snap->payload) ?: []) : [];
        $items = $data['items'] ?? [];
        return is_array($items) ? $items : [];
    }

    public function importSymbols(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到配置文件'], 400);
        try {
            $data = json_decode($request->file('file')->get(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            return response()->json(['ok' => false, 'error' => '配置文件解析失败'], 400);
        }
        $items = is_array($data) && isset($data['items']) ? $data['items'] : $data;
        if (!is_array($items)) return response()->json(['ok' => false, 'error' => '配置文件解析失败'], 400);
        // 导入与正常保存共用同一套校验：非空、0~1、字面查重、历史兼容扫描，避免绕过防线
        if ($error = $this->validateSymbolItems($items)) return $error;
        return $this->saveSnapshot($request, 'symbols.json', ['items' => array_values($items)], true);
    }

    /**
     * 符号库统一校验（保存与导入共用）。返回 null 表示通过，否则返回 400 响应。
     */
    private function validateSymbolItems(array $items): ?JsonResponse
    {
        // 基础校验：必填、计勤值范围、字面查重（重复符号在 map 构建时后者静默覆盖前者 → 核算错乱）
        $seen = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) return response()->json(['ok' => false, 'error' => '符号数据格式错误'], 400);
            $sym = trim((string) ($it['symbol'] ?? ''));
            $name = trim((string) ($it['name'] ?? ''));
            if ($sym === '' || $name === '') {
                return response()->json(['ok' => false, 'error' => '第' . ($i + 1) . '行：符号或名称不能为空'], 400);
            }
            if (isset($seen[$sym])) {
                return response()->json(['ok' => false, 'error' => "符号字面重复：「{$sym}」同时出现在第" . ($seen[$sym] + 1) . '行和第' . ($i + 1) . '行。重复符号会互相覆盖导致核算错乱，请改用不同字面'], 400);
            }
            $seen[$sym] = $i;
            if (!isset($it['value']) || !is_numeric($it['value']) || (float) $it['value'] < 0 || (float) $it['value'] > 1) {
                return response()->json(['ok' => false, 'error' => "「{$sym}」的计勤值必须是 0~1 的数值（1=全勤、0.5=半天、0=不计勤）"], 400);
            }
        }

        // 兼容性检查：历史考勤中出现过的符号字面必须仍能被新符号库识别（含勾号别名容错），
        // 否则已上传考勤在核算时被静默跳过 → 出勤少算 → 工资错误
        $conflicts = \App\Services\PayrollCalculator::symbolConflicts($items);
        if ($conflicts) {
            return response()->json(['ok' => false, 'error' => "保存被阻止：以下符号在历史考勤中实际使用，但新符号库无法识别（会导致相应月份工资核算错误）：\n" . implode("\n", $conflicts) . "\n请先修正符号库，或先在考勤管理中重新上传使用新符号的考勤表"], 400);
        }

        return null;
    }

    public function savePayslip(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'payslip_config.json', ['config' => $request->input('config', [])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return $this->saveSnapshot($request, 'settings.json', $request->input('settings', []));
    }

    /**
     * 写配置快照。
     *
     * $replace=true 时整份覆盖（符号库等"全量提交"配置必须用整份覆盖：
     * array_replace_recursive 对数字索引数组不会截断，删除末项会不生效、删中间项会残留重复）。
     * $replace=false 保留深合并，供 settings 这类"分区局部提交"的配置使用，
     * 避免保存安全设置时把公司信息等其他分区冲掉。
     */
    private function saveSnapshot(Request $request, string $file, array $payload, bool $replace = false): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($account->role !== 'admin') return response()->json(['ok' => false, 'error' => '仅管理员可修改配置'], 403);
        if ($replace) {
            $data = $payload;
        } else {
            $old = DB::table('legacy_json_snapshots')->where('file_name', $file)->first();
            $data = $old ? ($this->jsonValue($old->payload) ?: []) : [];
            $data = array_replace_recursive($data, $payload);
        }
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
