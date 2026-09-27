<?php

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use PHPUnit\Framework\TestCase;

class PayrollSymbolTest extends TestCase
{
    // 复刻生产故障形态：符号库把正常出勤录成 "V"，考勤表用的是 "√"
    private array $lib = [
        'V'  => ['symbol' => 'V', 'name' => '正常出勤', 'in_required' => true, 'in_actual' => true, 'value' => 1, 'category' => '正常'],
        '休' => ['symbol' => '休', 'name' => '公休', 'in_required' => false, 'in_actual' => false, 'value' => 0, 'category' => '公休'],
    ];

    public function test_exact_match_wins(): void
    {
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('V', $this->lib)['name']);
        $this->assertSame('公休', PayrollCalculator::symbolLookup('休', $this->lib)['name']);
    }

    public function test_check_mark_aliases_match_v_entry(): void
    {
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('√', $this->lib)['name']);
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('v', $this->lib)['name']);
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('∨', $this->lib)['name']);
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('✓', $this->lib)['name']);
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup(' ✔ ', $this->lib)['name']);
    }

    public function test_reverse_direction_library_sqrt_data_v(): void
    {
        $lib = ['√' => ['symbol' => '√', 'name' => '正常出勤', 'in_actual' => true, 'value' => 1]];
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('V', $lib)['name']);
    }

    public function test_unknown_and_empty_return_null(): void
    {
        $this->assertNull(PayrollCalculator::symbolLookup('事', $this->lib));
        $this->assertNull(PayrollCalculator::symbolLookup('×', $this->lib));
        $this->assertNull(PayrollCalculator::symbolLookup('', $this->lib));
        $this->assertNull(PayrollCalculator::symbolLookup('  ', $this->lib));
    }

    public function test_both_present_exact_each_side(): void
    {
        // 库里同时存在 V 与 √ 时各按自身定义，互不串位
        $lib = $this->lib + [
            '√' => ['symbol' => '√', 'name' => '加班出勤', 'in_actual' => true, 'value' => 1, 'category' => '正常'],
        ];
        $this->assertSame('正常出勤', PayrollCalculator::symbolLookup('V', $lib)['name']);
        $this->assertSame('加班出勤', PayrollCalculator::symbolLookup('√', $lib)['name']);
    }
}
