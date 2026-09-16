<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 审批流程定义（表单设计器 + 审批流配置）
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->string('flow_key', 60)->unique();
            $table->string('name', 120);
            $table->json('form_schema');   // 表单字段定义
            $table->json('flow_config');   // 节点 + 条件分支
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // 审批单实例
        Schema::create('approval_instances', function (Blueprint $table) {
            $table->id();
            $table->string('flow_key', 60)->index();
            $table->string('flow_name', 120);
            $table->string('title', 200);
            $table->json('form_data');
            $table->json('node_chain');          // 解析后的节点链（含审批人+状态）
            $table->json('opinions')->nullable();// 各节点审批意见
            $table->string('status', 20)->default('pending')->index(); // pending/approved/rejected/withdrawn/voided
            $table->unsignedInteger('current_node')->default(0);
            $table->unsignedBigInteger('applicant_id')->index();
            $table->string('applicant_name', 60);
            $table->string('project_name', 120)->default('');
            $table->json('result')->nullable();  // 通过后联动动作产生的业务结果
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'flow_key']);
        });

        // 入职办理清单（录用审批通过后生成）
        Schema::create('onboard_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instance_id')->index(); // 对应录用审批单
            $table->string('staff_name', 60);
            $table->string('project_name', 120)->default('');
            $table->json('items');   // [{key,label,done,by}]
            $table->json('extra')->nullable(); // 补录资料（身份证/银行卡等）
            $table->string('status', 20)->default('pending'); // pending/done
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboard_checklists');
        Schema::dropIfExists('approval_instances');
        Schema::dropIfExists('approval_flows');
    }
};
