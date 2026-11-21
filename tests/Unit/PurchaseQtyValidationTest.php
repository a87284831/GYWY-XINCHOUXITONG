<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 采购填报数量/库存校验（M4）：数量必须 > 0，库存允许 0 不允许负数，最多两位小数。
 */
class PurchaseQtyValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/fill.php';
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/admin.php';
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/export_import.php';
    }

    private function parse($value, bool $allowZero = false, bool $required = true): array
    {
        $err = null;
        $val = \purchase_qty_value($value, '数量', $allowZero, $required, $err);
        return [$val, $err];
    }

    public function test_quantity_must_be_positive_number(): void
    {
        foreach ([-5, 0, '0', 'abc', ''] as $bad) {
            [$val, $err] = $this->parse($bad);
            $this->assertNull($val, '非法数量应被拒绝：' . var_export($bad, true));
            $this->assertNotNull($err);
        }
        [$val, $err] = $this->parse('12.50');
        $this->assertNull($err);
        $this->assertSame(12.5, $val);
    }

    public function test_stock_allows_zero_but_not_negative(): void
    {
        [$val, $err] = $this->parse(0, true, false);
        $this->assertNull($err);
        $this->assertSame(0.0, $val);

        [$val, $err] = $this->parse(-1, true, false);
        $this->assertNull($val);
        $this->assertNotNull($err);
    }

    public function test_more_than_two_decimals_is_rejected(): void
    {
        [$val, $err] = $this->parse('1.234');
        $this->assertNull($val);
        $this->assertStringContainsString('两位小数', $err);
    }

    public function test_upper_bound_is_enforced(): void
    {
        [$val, $err] = $this->parse(1000000);
        $this->assertNull($val);
        $this->assertStringContainsString('999999', $err);
    }

    public function test_purchase_state_machine_guards(): void
    {
        $this->assertNull(\purchase_confirm_guard('submitted'));
        $this->assertNotNull(\purchase_confirm_guard('draft'));
        $this->assertNotNull(\purchase_confirm_guard('confirmed'));
        $this->assertStringContainsString('重新提交', \purchase_confirm_guard('returned'));

        $this->assertNull(\purchase_unconfirm_guard('confirmed'));
        $this->assertNotNull(\purchase_unconfirm_guard('submitted'));
        $this->assertNotNull(\purchase_unconfirm_guard('returned'));
    }

    public function test_archive_requires_quote_confirmation(): void
    {
        $block = \purchase_archive_quote_guard(0, false);
        $this->assertNotNull($block);
        $this->assertTrue($block['need_confirm']);
        $this->assertSame('no_quote', $block['code']);
        $this->assertNull(\purchase_archive_quote_guard(0, true));
        $this->assertNull(\purchase_archive_quote_guard(3, false));
    }

    public function test_import_requires_all_items_confirmed(): void
    {
        $this->assertStringContainsString('无填报记录', \purchase_import_status_guard(0, 0));
        $this->assertStringContainsString('1 条填报未确认', \purchase_import_status_guard(3, 2));
        $this->assertNull(\purchase_import_status_guard(3, 3));
    }
}
