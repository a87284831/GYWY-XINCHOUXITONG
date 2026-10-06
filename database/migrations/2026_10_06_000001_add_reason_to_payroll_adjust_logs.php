<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微调日志表 payroll_adjust_logs 此前由「从生产 dump 反向补建」迁移创建，
 * 但应用代码从未写入；2026-10-06 启用微调日志时发现缺少「修改原因」列
 * （前端微调弹窗一直强制填写 reason，后端从未接收）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_adjust_logs') && !Schema::hasColumn('payroll_adjust_logs', 'reason')) {
            Schema::table('payroll_adjust_logs', function (Blueprint $table) {
                $table->string('reason', 255)->default('')->after('row_after')->comment('微调原因（前端必填）');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_adjust_logs') && Schema::hasColumn('payroll_adjust_logs', 'reason')) {
            Schema::table('payroll_adjust_logs', function (Blueprint $table) {
                $table->dropColumn('reason');
            });
        }
    }
};
