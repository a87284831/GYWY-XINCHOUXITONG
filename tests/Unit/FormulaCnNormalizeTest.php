<?php

namespace Tests\Unit;

use App\Services\CalcRules;
use App\Services\Expr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-03 阻断修复：生产 calc_rules.json 的 gross/net 公式存的是中文展示公式
 * （「应发基本工资 + …」，绕过前端 enFormula 转换写入），Expr 只认 ASCII 标识符，
 * 导致任何真实核算在公式求值处中止。修复两层：
 *  ① CalcRules::evaluate() 入口把中文内置词归一化为英文变量（与前端 VAR_CN 单一词表对齐）
 *  ② Expr 支持 CJK 标识符，使中文自定义薪酬字段（custom_fields 名字为中文）可参与公式
 */
class FormulaCnNormalizeTest extends TestCase
{
    use RefreshDatabase;

    private function rules(): CalcRules
    {
        return new CalcRules();
    }

    // ─── Expr：CJK 标识符支持（对齐前端 evalFormulaSafe）────────

    public function test_expr_accepts_chinese_identifier(): void
    {
        $this->assertSame(5010.0, Expr::evaluate('应发基本工资 + 餐补', ['应发基本工资' => 5000.0, '餐补' => 10.0]));
    }

    public function test_expr_accepts_mixed_cn_and_en_identifiers(): void
    {
        // computeRow 的变量池：内置项为英文键，自定义薪酬项为中文键
        $this->assertSame(7800.0, Expr::evaluate('base_pay + 特殊扣款', ['base_pay' => 8000.0, '特殊扣款' => -200.0]));
    }

    public function test_expr_unknown_chinese_variable_still_throws(): void
    {
        // 未定义变量不静默按 0（既有语义对中文变量同样成立）
        $this->expectException(\RuntimeException::class);
        Expr::evaluate('应发基本工资 + 不存在的项目', ['应发基本工资' => 1.0]);
    }

    public function test_expr_chinese_identifier_inside_whitelist_function(): void
    {
        $this->assertSame(66.67, Expr::evaluate('round(应发绩效工资 / 3, 2)', ['应发绩效工资' => 200.0]));
    }

    // ─── CalcRules：中文内置词归一化（生产原公式直接可求值）──────

    public function test_production_gross_formula_in_chinese_evaluates(): void
    {
        $grossCn = '应发基本工资 + 应发绩效工资 + 病假工资 + 夜班话费补贴 + 餐补 + 其他补贴 + 月度奖励 + 已发福利 - 月度扣罚 - 缺卡扣款 - 迟到早退扣款 - 其他扣款 - 工装扣款';
        // 变量池即 computeRow 实际传入的英文键
        $vars = [
            'base_pay' => 8000.0, 'perf_pay' => 0.0, 'sick_pay' => 0.0, 'night' => 0.0, 'meal' => 300.0,
            'title_sub' => 0.0, 'reward' => 0.0, 'welfare' => 0.0, 'punish' => 0.0, 'late_d' => 0.0,
            'miss_d' => 0.0, 'other_d' => 0.0, 'uniform_d' => 0.0,
        ];
        $r = $this->rules();
        $this->assertSame(8300.0, $r->evaluate($grossCn, $vars, ''));
        $this->assertSame([], $r->getLastError(), '中文原公式直接成功，不得留下 lastError（否则 computeRow 会中止整月核算）');
    }

    public function test_production_net_formula_in_chinese_evaluates(): void
    {
        $netCn = '应发合计 - 五险一金合计 - 本月个税 - 已发福利';
        $vars = ['gross' => 8300.0, 'soc_total' => 500.0, 'actual_tax' => 84.0, 'welfare' => 0.0];
        $this->assertSame(7716.0, $this->rules()->evaluate($netCn, $vars, ''));
    }

    public function test_english_formula_still_works(): void
    {
        $this->assertSame(8300.0, $this->rules()->evaluate(
            'base_pay + perf_pay + meal', ['base_pay' => 8000.0, 'perf_pay' => 0.0, 'meal' => 300.0], ''
        ));
    }

    public function test_chinese_custom_field_passes_through_and_evaluates(): void
    {
        // 「特殊扣款」是生产已启用的自定义字段，不在内置中英词表中：归一化须原样保留，
        // 由 Expr 按变量池中的中文键取值
        $expr = '应发基本工资 - 特殊扣款';
        $this->assertSame(7800.0, $this->rules()->evaluate(
            $expr, ['base_pay' => 8000.0, '特殊扣款' => 200.0], ''
        ));
    }
}
