<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 组织架构（主数据底座）：组织树节点 + 人员调动记录；
 * 人员档案扩展 组织节点/直属上级/部门路径；账号扩展 绑定人员/启用状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('type', 20)->default('department'); // company|region|project|department|team
            $table->string('name', 120)->index();
            $table->string('code', 80)->nullable();
            $table->unsignedBigInteger('manager_staff_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            // 仅 project 节点使用（承接原项目档案字段）
            $table->string('contact', 120)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->json('aliases')->nullable();
            $table->string('note', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('org_transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_legacy_id')->index();
            $table->string('staff_name', 120);
            $table->unsignedBigInteger('from_org_id')->nullable();
            $table->unsignedBigInteger('to_org_id')->nullable();
            $table->string('from_path', 255)->nullable();
            $table->string('to_path', 255)->nullable();
            $table->date('change_date')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('by_user', 80)->nullable();
            $table->timestamps();
        });

        Schema::table('payroll_staff', function (Blueprint $table) {
            $table->unsignedBigInteger('org_id')->nullable()->index();
            $table->unsignedBigInteger('leader_id')->nullable();
            $table->string('dept_path', 255)->nullable();
        });

        Schema::table('payroll_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_id')->nullable()->index();
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_accounts', function (Blueprint $table) {
            $table->dropColumn(['staff_id', 'enabled']);
        });
        Schema::table('payroll_staff', function (Blueprint $table) {
            $table->dropColumn(['org_id', 'leader_id', 'dept_path']);
        });
        Schema::dropIfExists('org_transfer_logs');
        Schema::dropIfExists('org_nodes');
    }
};
