<?php

namespace Tests\Unit;

use App\Services\CalcRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalcRulesTest extends TestCase
{
    use RefreshDatabase;

    private function seedCalcRules(array $rules): void
    {
        \Illuminate\Support\Facades\DB::table('legacy_json_snapshots')->insert([
            'file_name' => 'calc_rules.json',
            'payload' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_get_pay_rule_returns_monthly_for_staff(): void
    {
        $this->seedCalcRules([
            'pay_rules' => [
                'staff' => ['cycle' => 'monthly', 'ratio' => 1.0],
            ],
        ]);
        $rules = new CalcRules();
        $rule = $rules->getPayRule('staff');
        $this->assertEquals('monthly', $rule['cycle']);
        $this->assertEquals(1.0, $rule['ratio']);
    }

    public function test_get_pay_rule_returns_level_ratio_for_manager(): void
    {
        $this->seedCalcRules([
            'pay_rules' => [
                'manager' => [
                    'cycle' => 'quarterly',
                    'levels' => [
                        '经理级' => ['quarter_ratio' => 0.95, 'half_year_ratio' => 0.05],
                        '主管级' => ['quarter_ratio' => 0.90, 'half_year_ratio' => 0.10],
                    ],
                    'default' => ['quarter_ratio' => 1.0, 'half_year_ratio' => 0.0],
                ],
            ],
        ]);
        $rules = new CalcRules();

        $rule = $rules->getPayRule('manager', '经理级');
        $this->assertEquals('quarterly', $rule['cycle']);
        $this->assertEquals(0.95, $rule['quarter_ratio']);
        $this->assertEquals(0.05, $rule['half_year_ratio']);

        $rule = $rules->getPayRule('manager', '主管级');
        $this->assertEquals(0.90, $rule['quarter_ratio']);
        $this->assertEquals(0.10, $rule['half_year_ratio']);

        // 未配置档位：configured=false（显式失败，不再静默 fallback default）
        $rule = $rules->getPayRule('manager', '未知职级');
        $this->assertEquals('quarterly', $rule['cycle']);
        $this->assertFalse($rule['configured']);
        $this->assertEquals(0.0, $rule['quarter_ratio']);
        $this->assertEquals(0.0, $rule['half_year_ratio']);

        // 档位为空同样 configured=false
        $rule = $rules->getPayRule('manager', '');
        $this->assertFalse($rule['configured']);
    }

    public function test_get_pay_rule_defaults_to_monthly_when_no_config(): void
    {
        $this->seedCalcRules([]);
        $rules = new CalcRules();
        $rule = $rules->getPayRule('staff');
        $this->assertEquals('monthly', $rule['cycle']);
        $this->assertEquals(1.0, $rule['ratio']);
    }

    public function test_get_pay_rule_defaults_to_monthly_when_person_type_not_configured(): void
    {
        $this->seedCalcRules([
            'pay_rules' => [
                'manager' => ['cycle' => 'quarterly', 'default' => ['quarter_ratio' => 0.95, 'half_year_ratio' => 0.05]],
            ],
        ]);
        $rules = new CalcRules();
        $rule = $rules->getPayRule('staff'); // staff 未配置，默认月度
        $this->assertEquals('monthly', $rule['cycle']);
    }
}
