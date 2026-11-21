<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 采购存档关联填报明细：删除退回记录时按 item_id 精确删除，
 * 不再依赖“名称 + 规格”猜测（M3 完整修复）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 采购表是历史库直接建表，不走 Laravel 迁移；本地/CI 的 SQLite 可能没有该表
        if (!Schema::hasTable('archived_purchases') || Schema::hasColumn('archived_purchases', 'item_id')) {
            return;
        }
        Schema::table('archived_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('project_id');
            $table->index('item_id', 'idx_archived_item_id');
        });

        // 历史回填：同月同项目同条线同名称/品牌/规格/单位且尚未被占用的填报行，按顺序一对一关联
        $claimed = [];
        DB::table('archived_purchases')->whereNull('item_id')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$claimed) {
                foreach ($rows as $row) {
                    $ids = DB::table('purchase_items')
                        ->where('month', $row->month)
                        ->where('project_id', $row->project_id)
                        ->where('line', $row->line)
                        ->where('item_name', $row->item_name)
                        ->whereRaw("COALESCE(brand,'') = ?", [(string) ($row->brand ?? '')])
                        ->whereRaw("COALESCE(spec,'') = ?", [(string) ($row->spec ?? '')])
                        ->whereRaw("COALESCE(unit,'') = ?", [(string) ($row->unit ?? '')])
                        ->orderBy('id')
                        ->pluck('id');
                    foreach ($ids as $id) {
                        $id = (int) $id;
                        if (isset($claimed[$id])) continue;
                        DB::table('archived_purchases')->where('id', $row->id)->update(['item_id' => $id]);
                        $claimed[$id] = true;
                        break;
                    }
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('archived_purchases') || !Schema::hasColumn('archived_purchases', 'item_id')) {
            return;
        }
        Schema::table('archived_purchases', function (Blueprint $table) {
            $table->dropIndex('idx_archived_item_id');
            $table->dropColumn('item_id');
        });
    }
};
