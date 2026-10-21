<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 薪资核算整月删除审计日志（仅 admin 可删、删前 409 确认、删除事务化）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_delete_logs')) {
            Schema::create('payroll_delete_logs', function (Blueprint $table) {
                $table->id();
                $table->string('ym', 7)->comment('被删除的核算月份');
                $table->json('scope_info')->comment('删除范围快照：各类型行数/项目列表/应发实发合计/微调日志条数');
                $table->string('operator', 64);
                $table->string('operator_role', 32);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_delete_logs');
    }
};
