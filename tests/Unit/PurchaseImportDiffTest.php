<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 报价导入防呆（v2026.11.23.3）：
 * - 数量非正但填了单价 → 整单拒绝（purchase_import_row_invalid）；
 * - 导入与填报的数量/规格/品牌差异 → 只提示不拦截（purchase_import_diff_report）。
 */
class PurchaseImportDiffTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/export_import.php';
    }

    private function fillRow(array $over = []): array
    {
        return array_merge(['project_id' => 1, 'line' => '环境', 'item_name' => '垃圾袋', 'brand' => '',
            'spec' => '45×60', 'unit' => '包', 'quantity' => 10], $over);
    }

    private function impRow(array $over = []): array
    {
        return array_merge(['project_id' => 1, 'line' => '环境', 'item_name' => '垃圾袋', 'brand' => '',
            'spec' => '45×60', 'unit' => '包', 'quantity' => 10], $over);
    }

    public function test_row_invalid_only_when_non_positive_qty_with_price(): void
    {
        $this->assertTrue(\purchase_import_row_invalid(-2, 10.0), '负数量配正单价必须拒绝');
        $this->assertTrue(\purchase_import_row_invalid(0, 5.0), '零数量配正单价必须拒绝');
        $this->assertFalse(\purchase_import_row_invalid(5, 0.0), '有量无价是占位行，沿用原行为');
        $this->assertFalse(\purchase_import_row_invalid(5, 10.0));
        $this->assertFalse(\purchase_import_row_invalid(0, 0.0), '双空行走原有跳过逻辑');
    }

    public function test_diff_reports_quantity_change(): void
    {
        $diffs = \purchase_import_diff_report([$this->fillRow()], [$this->impRow(['quantity' => 20])]);
        $this->assertSame(['垃圾袋(45×60) 数量 10→20'], $diffs);
    }

    public function test_diff_empty_when_identical_or_merged(): void
    {
        $this->assertSame([], \purchase_import_diff_report([$this->fillRow()], [$this->impRow()]));
        // 填报 10+10 两行，导入合并为一行 20：聚合后数量一致，不算差异
        $diffs = \purchase_import_diff_report(
            [$this->fillRow(), $this->fillRow()],
            [$this->impRow(['quantity' => 20])]
        );
        $this->assertSame([], $diffs);
    }

    public function test_diff_reports_spec_change_via_relaxed_match(): void
    {
        $diffs = \purchase_import_diff_report([$this->fillRow()], [$this->impRow(['spec' => '75g'])]);
        $this->assertCount(1, $diffs);
        $this->assertSame('垃圾袋 规格「45×60」→「75g」', $diffs[0]);
    }

    public function test_diff_reports_spec_and_qty_together(): void
    {
        $diffs = \purchase_import_diff_report([$this->fillRow()], [$this->impRow(['spec' => '75g', 'quantity' => 3])]);
        $this->assertSame(['垃圾袋 规格「45×60」→「75g」、数量 10→3'], $diffs);
    }

    public function test_diff_reports_deleted_and_added_rows(): void
    {
        $diffs = \purchase_import_diff_report(
            [$this->fillRow(), $this->fillRow(['item_name' => '拖把', 'spec' => '标准', 'quantity' => 5])],
            [$this->impRow(), $this->impRow(['item_name' => '新商品', 'spec' => '', 'quantity' => 2])]
        );
        $this->assertContains('拖把(标准) 已删除', $diffs);
        $this->assertContains('新商品 新增', $diffs);
        $this->assertCount(2, $diffs);
    }

    public function test_diff_normalizes_full_width_spec(): void
    {
        $diffs = \purchase_import_diff_report([$this->fillRow(['spec' => '４５×６０'])], [$this->impRow(['spec' => '45×60'])]);
        $this->assertSame([], $diffs, '全角/半角规格归一后应视为相同');
    }

    public function test_diff_does_not_cross_project_or_line(): void
    {
        $diffs = \purchase_import_diff_report(
            [$this->fillRow(['project_id' => 2])],
            [$this->impRow(['project_id' => 1])]
        );
        $this->assertContains('垃圾袋(45×60) 新增', $diffs, '项目 1 的导入行在项目 2 无对应 → 新增');
        $this->assertContains('垃圾袋(45×60) 已删除', $diffs, '项目 2 的填报行在项目 1 无对应 → 已删除');
        $this->assertCount(2, $diffs);
    }
}
