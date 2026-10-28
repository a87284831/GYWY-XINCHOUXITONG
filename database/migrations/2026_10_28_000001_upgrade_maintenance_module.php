<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 维保模块升级：
 * 1. 合同字段扩充（合同编号/甲方/乙方负责人/服务内容/是否含配件耗材）
 * 2. 签约方资质有效期
 * 3. 合同变更留痕 / 多附件 / 设备台账 / 履约记录 / 预警去重日志
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->string('contract_no', 80)->nullable();
            $table->string('party_a', 160)->nullable();
            $table->string('party_contact', 80)->nullable();
            $table->string('party_phone', 40)->nullable();
            $table->text('service_scope')->nullable();
            $table->boolean('includes_supplies')->default(false);
        });
        Schema::table('maintenance_partners', function (Blueprint $table) {
            $table->date('qualification_expire')->nullable();
        });
        Schema::create('maintenance_contract_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->index();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_name', 80)->default('');
            $table->string('action', 20)->default('update'); // create | update
            $table->json('changes')->nullable();             // {字段: {old, new}}
            $table->timestamps();
        });
        Schema::create('maintenance_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('contract_id')->index();
            $table->unsignedBigInteger('record_id')->nullable()->index(); // 履约记录附件
            $table->string('kind', 30)->default('other'); // contract/supplement/inspection/qualification/record/other
            $table->string('name', 220)->default('');      // 原始文件名
            $table->string('file_name', 220)->default(''); // 存储文件名
            $table->unsignedBigInteger('size')->default(0);
            $table->string('uploaded_by', 80)->default('');
            $table->timestamps();
        });
        Schema::create('maintenance_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('type', 20)->index();           // fire | elevator
            $table->string('project_name', 160)->index();
            $table->string('device_no', 80)->default('');  // 设备编号
            $table->unsignedInteger('load_kg')->nullable();        // 电梯：载重(kg)
            $table->string('floors', 40)->nullable();              // 电梯：层站
            $table->string('system_type', 80)->nullable();         // 消防：系统类型
            $table->decimal('cover_area_sqm', 14, 2)->nullable();  // 消防：覆盖面积
            $table->date('inspect_date')->nullable();      // 年检日期
            $table->text('remark')->nullable();
            $table->timestamps();
        });
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('contract_id')->index();
            $table->date('record_date')->nullable();
            $table->string('kind', 20)->default('maintain'); // maintain | rectify | inspection
            $table->text('content')->nullable();
            $table->string('result', 200)->default('');
            $table->timestamps();
        });
        // 预警去重：同对象同档位同基准日只发一次
        Schema::create('maintenance_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->string('obj_type', 20);    // contract | device | partner
            $table->unsignedBigInteger('obj_id');
            $table->string('level', 20);       // expired | d30 | d60 | d90 | missing | inspect | qualification
            $table->date('ref_date')->nullable();
            $table->timestamps();
            $table->unique(['obj_type', 'obj_id', 'level', 'ref_date'], 'maint_alert_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_alert_logs');
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('maintenance_devices');
        Schema::dropIfExists('maintenance_attachments');
        Schema::dropIfExists('maintenance_contract_logs');
        Schema::table('maintenance_partners', function (Blueprint $table) {
            $table->dropColumn('qualification_expire');
        });
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn(['contract_no', 'party_a', 'party_contact', 'party_phone', 'service_scope', 'includes_supplies']);
        });
    }
};
