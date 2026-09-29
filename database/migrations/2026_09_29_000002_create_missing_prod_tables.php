<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐生产库中历史手工创建、无迁移记录的 5 张表。
 * 结构来源：deploy/prod_missing_tables_schema_20260929.sql（生产 mysqldump 导出）。
 * 全部加 hasTable 守卫，对已有这些表的库幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sys_dingtalk_config')) {
            Schema::create('sys_dingtalk_config', function (Blueprint $table) {
                $table->increments('id');
                $table->string('config_key', 64)->unique();
                $table->text('config_value')->nullable();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('payroll_adjust_logs')) {
            Schema::create('payroll_adjust_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('staff_legacy_id');
                $table->string('staff_name', 120)->default('');
                $table->string('project_name', 120)->default('');
                $table->string('ym', 7);
                $table->json('changes')->nullable();
                $table->json('row_after')->nullable();
                $table->string('operator', 120)->default('');
                $table->string('operator_role', 40)->default('');
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (!Schema::hasTable('approval_drafts')) {
            Schema::create('approval_drafts', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('flow_key', 60);
                $table->string('flow_no', 32)->nullable();
                $table->string('title', 200)->default('');
                $table->text('form_data')->nullable();
                $table->string('project_name', 100)->default('');
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('approval_shares')) {
            Schema::create('approval_shares', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('instance_id');
                $table->string('flow_no', 32)->default('');
                $table->integer('from_user_id');
                $table->string('from_name', 100)->default('');
                $table->integer('to_user_id');
                $table->string('to_name', 100)->default('');
                $table->timestamp('created_at')->nullable();

                $table->index('to_user_id');
                $table->index('instance_id');
            });
        }

        if (!Schema::hasTable('payroll_staff_person_type_log')) {
            Schema::create('payroll_staff_person_type_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('staff_id');
                $table->string('person_type', 10);
                $table->date('effective_date');
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->index(['staff_id', 'effective_date'], 'idx_staff_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_staff_person_type_log');
        Schema::dropIfExists('approval_shares');
        Schema::dropIfExists('approval_drafts');
        Schema::dropIfExists('payroll_adjust_logs');
        Schema::dropIfExists('sys_dingtalk_config');
    }
};
