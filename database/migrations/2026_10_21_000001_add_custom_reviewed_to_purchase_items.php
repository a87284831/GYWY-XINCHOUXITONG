<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 采购清单外商品审核流程修正（2026-10-21）：
 * 清单外商品在管理员「全部确认」后进入清单外审核模块，
 * 管理员可选择归入标准商品（is_custom→0）或忽略（不加入标准库）。
 * 忽略需标记已审核，避免重复出现在待审核列表，故新增 custom_reviewed 列。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_items') && !Schema::hasColumn('purchase_items', 'custom_reviewed')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->tinyInteger('custom_reviewed')->default(0)->after('is_custom')->comment('清单外商品是否已审核（忽略操作置1）');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_items') && Schema::hasColumn('purchase_items', 'custom_reviewed')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->dropColumn('custom_reviewed');
            });
        }
    }
};
