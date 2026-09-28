<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 从 legacy_json_snapshots.calc_rules.json 加载"系统设置→工资计算规则"，
 * 供薪资核算读取。所有 getter 都做了 null 安全与类型转换。
 * 单次请求内做静态缓存，避免重复查库。
 *
 * evaluate() 求值失败时会记录 lastError 并返回兜底值；调用方（核算/微调）必须在
 * 落库前检查 getLastError()，非空即中止——公式出错绝不允许静默按 0 计薪。
 */
class CalcRules
{
    private array $rules;
    private array $lastError = [];

    public function __construct()
    {
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $payload = $snap ? (json_decode((string)$snap->payload, true) ?: []) : [];
        $this->rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
    }

    public function section(string $name): array
    {
        $v = $this->rules[$name] ?? null;
        return is_array($v) ? $v : [];
    }

    /** 按点路径取任意嵌套值 */
    public function raw(string $path, mixed $default = null): mixed
    {
        $cur = $this->rules;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) $cur = $cur[$seg];
            else return $default;
        }
        return $cur;
    }

    public function num(string $path, float $default = 0.0): float
    {
        $v = $this->raw($path);
        return is_numeric($v) ? (float)$v : $default;
    }

    public function int(string $path, int $default = 0): int
    {
        $v = $this->raw($path);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function str(string $path, string $default = ''): string
    {
        $v = $this->raw($path);
        return $v === null ? $default : (string)$v;
    }

    /**
     * UI 里布尔开关常见存法：true/false, "true"/"false", 1/0, "on"/"off"。
     * 未设置时按 $default 返回。
     */
    public function flag(string $path, bool $default = true): bool
    {
        $v = $this->raw($path);
        if ($v === null) return $default;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return (bool)$v;
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['false', '0', 'no', 'off', ''], true)) return false;
            if (in_array($s, ['true', '1', 'yes', 'on'], true)) return true;
        }
        return (bool)$v;
    }

    /** 求值一个公式；空串或解析失败时用 $fallback 表达式，两者都失败返回 0 并记录错误。 */
    public function evaluate(string $expr, array $vars, ?string $fallback = null): float
    {
        $e = trim($expr);
        if ($e === '' && $fallback !== null) $e = trim($fallback);
        if ($e === '') return 0.0;
        try {
            return Expr::evaluate($e, $vars);
        } catch (\Throwable $ex) {
            if ($fallback !== null && trim($fallback) !== '' && $e !== trim($fallback)) {
                try {
                    $result = Expr::evaluate($fallback, $vars);
                    // 主公式失败但备用公式成功，记录警告
                    $this->lastError = [
                        'expr' => $expr,
                        'fallback' => $fallback,
                        'error' => $ex->getMessage(),
                        'vars' => array_keys($vars),
                    ];
                    Log::warning("工资公式主表达式失败，已使用备用公式", [
                        'expr' => $expr,
                        'fallback' => $fallback,
                        'error' => $ex->getMessage(),
                    ]);
                    return $result;
                } catch (\Throwable $ex2) {
                    // 两个公式都失败
                }
            }
            // 记录详细错误信息
            $this->lastError = [
                'expr' => $expr,
                'fallback' => $fallback,
                'error' => $ex->getMessage(),
                'vars' => array_keys($vars),
            ];
            Log::warning("工资公式计算失败，返回 0", [
                'expr' => $expr,
                'fallback' => $fallback,
                'error' => $ex->getMessage(),
                'vars' => array_keys($vars),
            ]);
            return 0.0;
        }
    }

    /** 获取最后一次公式计算的错误信息（如果有） */
    public function getLastError(): array
    {
        return $this->lastError;
    }

    /** 清除错误记录 */
    public function clearError(): void
    {
        $this->lastError = [];
    }

    /** 薪酬档位（唯一权威枚举）：钉钉花名册「薪酬档位」单选字段的选项，需与钉钉逐字一致 */
    public const PAY_GRADES = ['专员级', '主管级', '经理级'];

    /**
     * 获取绩效发放规则。
     *
     * @param string $personType 人员类型：staff|manager|case|hq
     * @param string $payGrade 薪酬档位（专员级|主管级|经理级，仅 quarterly 使用）
     * @return array{cycle:string, ratio?:float, quarter_ratio?:float, half_year_ratio?:float, configured?:bool}
     */
    public function getPayRule(string $personType, string $payGrade = ''): array
    {
        $rules = $this->section('pay_rules');
        $rule = $rules[$personType] ?? ['cycle' => 'monthly', 'ratio' => 1.0];

        if (($rule['cycle'] ?? 'monthly') === 'monthly') {
            return [
                'cycle' => 'monthly',
                'ratio' => (float)($rule['ratio'] ?? 1.0),
            ];
        }

        // quarterly：必须精确命中已配置档位；无配置时 configured=false（调用方负责显式报错，禁止静默兜底）
        $levels = $rule['levels'] ?? [];
        if (isset($levels[$payGrade]) && is_array($levels[$payGrade])) {
            return [
                'cycle' => 'quarterly',
                'quarter_ratio' => (float)($levels[$payGrade]['quarter_ratio'] ?? 0.0),
                'half_year_ratio' => (float)($levels[$payGrade]['half_year_ratio'] ?? 0.0),
                'configured' => true,
            ];
        }
        return [
            'cycle' => 'quarterly',
            'quarter_ratio' => 0.0,
            'half_year_ratio' => 0.0,
            'configured' => false,
        ];
    }
}
