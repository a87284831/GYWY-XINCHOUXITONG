<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * person_type 改为可空：钉钉离职列表新建的占位行从未经历过在职花名册同步，
 * 岗位职级真实未知，不得伪造为 'staff'（前端应显示「—」而非"基层员工"）。
 * 核算口径不变：categoryMap 等读取处对 null 兜底按 staff 处理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_staff', function (Blueprint $table) {
            $table->string('person_type', 10)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_staff', function (Blueprint $table) {
            $table->string('person_type', 10)->default('staff')->change();
        });
    }
};
