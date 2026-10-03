<?php

namespace App\Services;

use RuntimeException;

/**
 * 安全算术表达式求值器（不使用 eval），支持：
 *   + - * / % ( ) 数字 变量 白名单函数 min/max/abs/round/floor/ceil/if
 * 未定义变量直接抛异常（不再静默按 0），变量名打错会在核算时报错而不是算出错误工资。
 * if() 支持短路求值：只计算被选中的分支，避免除零等运行时错误。
 * 注意：不支持幂运算 '^'，tokenizer 会明确报错，提示改用乘法或乘方应走白名单函数。
 */
class Expr
{
    private const FUNCTIONS = ['min', 'max', 'abs', 'round', 'floor', 'ceil', 'if'];

    /** @var array<int, array{0:string,1:mixed}> */
    private array $tokens;
    private int $i = 0;
    private array $vars;

    public static function evaluate(string $expr, array $vars = []): float
    {
        $self = new self();
        $self->tokens = self::tokenize($expr);
        $self->vars = $vars;
        if (!$self->tokens) return 0.0;
        $v = $self->parseExpr();
        if ($self->i < count($self->tokens)) {
            throw new RuntimeException('表达式解析未在末尾停下：' . substr($expr, 0, 80));
        }
        return (float)$v;
    }

    private static function tokenize(string $s): array
    {
        $out = []; $n = strlen($s); $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { $i++; continue; }
            if (is_numeric($c) || ($c === '.' && $i + 1 < $n && is_numeric($s[$i + 1]))) {
                $j = $i;
                while ($j < $n && (is_numeric($s[$j]) || $s[$j] === '.')) $j++;
                if ($j < $n && ($s[$j] === 'e' || $s[$j] === 'E')) {
                    $k = $j + 1;
                    if ($k < $n && ($s[$k] === '+' || $s[$k] === '-')) $k++;
                    while ($k < $n && is_numeric($s[$k])) $k++;
                    $j = $k;
                }
                $out[] = ['num', (float)substr($s, $i, $j - $i)]; $i = $j; continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) $j++;
                $out[] = ['id', substr($s, $i, $j - $i)]; $i = $j; continue;
            }
            // CJK 标识符（自定义薪酬字段允许中文名，与前端 evalFormulaSafe 对齐）：
            // 从当前字节偏移消费连续的中日韩统一表意文字，独立成一个 id token
            if (ord($c) >= 0x80 && preg_match('/[\x{4e00}-\x{9fff}]+/u', $s, $m, PREG_OFFSET_CAPTURE, $i) && $m[0][1] === $i) {
                $out[] = ['id', $m[0][0]];
                $i += strlen($m[0][0]);
                continue;
            }
            // '^' 明确不支持（解析器无幂运算），落到下面的非法字符分支给出清晰报错
            if (strpos('+-*/(),%', $c) !== false) { $out[] = [$c, null]; $i++; continue; }
            throw new RuntimeException("非法字符 '{$c}' 位于位置 {$i}");
        }
        return $out;
    }

    private function peek(): ?array { return $this->tokens[$this->i] ?? null; }
    private function eat(): array { return $this->tokens[$this->i++]; }
    private function expect(string $type): void
    {
        $t = $this->peek();
        if (!$t || $t[0] !== $type) throw new RuntimeException("缺少 {$type}");
        $this->i++;
    }

    private function parseExpr(): float
    {
        $v = $this->parseTerm();
        while (($t = $this->peek()) && ($t[0] === '+' || $t[0] === '-')) {
            $this->i++;
            $r = $this->parseTerm();
            $v = $t[0] === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private function parseTerm(): float
    {
        $v = $this->parseFactor();
        while (($t = $this->peek()) && ($t[0] === '*' || $t[0] === '/' || $t[0] === '%')) {
            $this->i++;
            $r = $this->parseFactor();
            if ($t[0] === '*') $v *= $r;
            elseif ($t[0] === '/') { if ($r == 0.0) throw new RuntimeException('除以零'); $v /= $r; }
            else { if ($r == 0.0) throw new RuntimeException('模零'); $v = fmod($v, $r); }
        }
        return $v;
    }

    private function parseFactor(): float
    {
        $t = $this->peek();
        if ($t === null) throw new RuntimeException('表达式意外结束');
        if ($t[0] === '-') { $this->i++; return -$this->parseFactor(); }
        if ($t[0] === '+') { $this->i++; return  $this->parseFactor(); }
        if ($t[0] === '(') { $this->i++; $v = $this->parseExpr(); $this->expect(')'); return $v; }
        if ($t[0] === 'num') { $this->i++; return (float)$t[1]; }
        if ($t[0] === 'id') {
            $this->i++;
            $name = (string)$t[1];
            if (($n = $this->peek()) && $n[0] === '(') {
                $fnName = strtolower($name);
                if (!in_array($fnName, self::FUNCTIONS, true)) {
                    throw new RuntimeException("未授权函数：{$name}");
                }
                $this->i++;

                // if() 短路求值：只计算被选中的分支，避免除零等错误
                if ($fnName === 'if') {
                    $cond = $this->parseExpr();
                    $this->expect(',');
                    if (!empty($cond)) {
                        $thenVal = $this->parseExpr();
                        // 跳过 else 分支（如果存在）
                        $tk = $this->peek();
                        if ($tk && $tk[0] === ',') {
                            $this->i++;
                            $depth = 1;
                            while ($depth > 0) {
                                $tk = $this->peek();
                                if (!$tk) break;
                                if ($tk[0] === '(') $depth++;
                                elseif ($tk[0] === ')') {
                                    if ($depth === 1) break;
                                    $depth--;
                                }
                                $this->i++;
                            }
                        }
                    } else {
                        // 跳过 then 分支，找到分隔逗号或结束括号
                        $depth = 1;
                        $foundComma = false;
                        while ($depth > 0) {
                            $tk = $this->peek();
                            if (!$tk) break;
                            if ($tk[0] === '(') $depth++;
                            elseif ($tk[0] === ')') {
                                if ($depth === 1) break;
                                $depth--;
                            }
                            elseif ($tk[0] === ',' && $depth === 1) {
                                $foundComma = true;
                                break;
                            }
                            $this->i++;
                        }
                        if ($foundComma) {
                            $this->i++; // 跳过逗号
                            $thenVal = $this->parseExpr();
                        } else {
                            $thenVal = 0.0;
                        }
                    }
                    $this->expect(')');
                    return (float)$thenVal;
                }

                // 其他函数正常求值所有参数
                $args = [];
                if (($p = $this->peek()) && $p[0] !== ')') {
                    $args[] = $this->parseExpr();
                    while (($c = $this->peek()) && $c[0] === ',') { $this->i++; $args[] = $this->parseExpr(); }
                }
                $this->expect(')');
                return self::callFn($fnName, $args);
            }
            if (array_key_exists($name, $this->vars)) return (float)$this->vars[$name];
            // 未定义变量不再静默按 0：变量名打错时应显式失败，避免整月工资算错
            throw new RuntimeException("未定义变量：{$name}");
        }
        throw new RuntimeException("无法解析 token：{$t[0]}");
    }

    private static function callFn(string $fn, array $a): float
    {
        return match ($fn) {
            'min'   => (float)min(...$a),
            'max'   => (float)max(...$a),
            'abs'   => (float)abs($a[0] ?? 0),
            'round' => (float)round((float)($a[0] ?? 0), (int)($a[1] ?? 0)),
            'floor' => (float)floor($a[0] ?? 0),
            'ceil'  => (float)ceil($a[0] ?? 0),
            default => 0.0,
        };
    }
}
