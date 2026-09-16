<?php

namespace App\Services;

use RuntimeException;

/**
 * 安全算术表达式求值器（不使用 eval），支持：
 *   + - * / % ( ) 数字 变量 白名单函数 min/max/abs/round/floor/ceil/if
 * 未定义变量按 0 处理，避免用户新加变量导致整链断掉。
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
            if (strpos('+-*/(),%^', $c) !== false) { $out[] = [$c, null]; $i++; continue; }
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
                if (!in_array(strtolower($name), self::FUNCTIONS, true)) {
                    throw new RuntimeException("未授权函数：{$name}");
                }
                $this->i++;
                $args = [];
                if (($p = $this->peek()) && $p[0] !== ')') {
                    $args[] = $this->parseExpr();
                    while (($c = $this->peek()) && $c[0] === ',') { $this->i++; $args[] = $this->parseExpr(); }
                }
                $this->expect(')');
                return self::callFn(strtolower($name), $args);
            }
            if (array_key_exists($name, $this->vars)) return (float)$this->vars[$name];
            return 0.0;
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
            'if'    => !empty($a[0]) ? (float)($a[1] ?? 0) : (float)($a[2] ?? 0),
            default => 0.0,
        };
    }
}
