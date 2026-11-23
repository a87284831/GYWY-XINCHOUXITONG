<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 采购计划统计表「汇总收集表」列口径（2026-11-23 改版）：
 * - 删除「采购理由」列（存档月取不到 reason 恒为空，误导），表头固定 12 列；
 * - 「主要采购内容」只显示金额前 3 项（品名(规格)×数量），更多时追加「等共 N 项」；
 * - 明细按 商品名+规格 聚合（数量累加），排序分 = 数量×单价（无价回退参考价，仍无价按数量）。
 */
class PurchasePlanExportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/export_import.php';
    }

    public function test_summary_headers_have_12_columns_without_reason(): void
    {
        $headers = \purchase_plan_summary_headers();
        $this->assertCount(12, $headers, '删除「采购理由」后汇总收集表必须固定 12 列');
        $this->assertSame('主要采购内容', $headers[11], '第 12 列（L）为「主要采购内容」');
        $this->assertNotContains('采购理由', $headers);
    }

    private function agg(array $rows, ?callable $priceOf = null): array
    {
        return \purchase_plan_aggregate_items($rows, '2026-11', $priceOf);
    }

    public function test_aggregate_merges_same_product_and_ranks_by_amount(): void
    {
        $rows = [
            ['item_name' => '垃圾袋', 'spec' => '45×60', 'quantity' => 10, 'price' => 3.0],   // 30
            ['item_name' => '垃圾袋', 'spec' => '45×60', 'quantity' => 10, 'price' => 3.0],   // 合并 → 20 只 / 60
            ['item_name' => 'LED灯管', 'spec' => '', 'quantity' => 8, 'price' => 10.0],       // 80
            ['item_name' => '洗手液', 'spec' => '500ml', 'quantity' => 5, 'price' => 12.0],   // 60
        ];
        $agg = $this->agg($rows, fn($n, $s) => 0.0);
        $this->assertCount(3, $agg, '同名同规格必须合并为一条');
        $this->assertSame(['LED灯管', '垃圾袋', '洗手液'], array_column($agg, 'name'), '按数量×单价从大到小');
        $this->assertSame(20.0, $agg[1]['qty'], '同商品数量累加');
        $this->assertSame(80.0, $agg[0]['amount']);
    }

    public function test_aggregate_falls_back_to_quantity_when_no_price(): void
    {
        $rows = [
            ['item_name' => '抹布', 'spec' => '', 'quantity' => 100, 'price' => 0],
            ['item_name' => '拖把', 'spec' => '', 'quantity' => 2, 'price' => 0],
        ];
        $agg = $this->agg($rows, fn($n, $s) => 0.0);
        $this->assertSame(['抹布', '拖把'], array_column($agg, 'name'), '取不到价时按数量兜底排序');
        $this->assertSame(100.0, $agg[0]['amount']);
    }

    public function test_top_items_formats_qty_and_appends_total(): void
    {
        $agg = [
            ['name' => '垃圾袋', 'spec' => '45×60', 'qty' => 20.0, 'amount' => 60.0],
            ['name' => '洗手液', 'spec' => '500ml', 'qty' => 2.5, 'amount' => 30.0],
            ['name' => '抹布', 'spec' => '', 'qty' => 100.0, 'amount' => 100.0],
            ['name' => '草籽', 'spec' => '', 'qty' => 3.0, 'amount' => 9.0],
            ['name' => '灯管', 'spec' => '', 'qty' => 8.0, 'amount' => 80.0],
        ];
        $text = \purchase_plan_top_items($agg);
        $this->assertSame('垃圾袋(45×60)×20、洗手液(500ml)×2.5、抹布×100 等共 5 项', $text);
        $this->assertSame('垃圾袋(45×60)×20、洗手液(500ml)×2.5、抹布×100', \purchase_plan_top_items(array_slice($agg, 0, 3)), '不超过 3 项时不加总数后缀');
        $this->assertSame('', \purchase_plan_top_items([]), '无明细留空');
    }
}
