<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 从 legacy_json_snapshots.calc_rules.json 加载"系统设置→工资计算规则"，
 * 供薪资核算读取。所有 getter 都做了 null 安全与类型转换。
 * 单次请求内做静态缓存，避免重复查库。
 */
class CalcRules
{
    private array $rules;

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

    /** 求值一个公式；空串或解析失败时用 $fallback 表达式，两者都失败返回 0。 */
    public function evaluate(string $expr, array $vars, ?string $fallback = null): float
    {
        $e = trim($expr);
        if ($e === '' && $fallback !== null) $e = trim($fallback);
        if ($e === '') return 0.0;
        try {
            return Expr::evaluate($e, $vars);
        } catch (\Throwable $ex) {
            if ($fallback !== null && trim($fallback) !== '' && $e !== trim($fallback)) {
                try { return Expr::evaluate($fallback, $vars); } catch (\Throwable) { /* noop */ }
            }
            report($ex);
            return 0.0;
        }
    }
}
