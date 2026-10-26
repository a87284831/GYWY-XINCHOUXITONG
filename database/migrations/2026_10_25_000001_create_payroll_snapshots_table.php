<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('ym', 7)->comment('核算月份 YYYY-MM');
            $table->string('person_type', 20)->comment('人员类型 staff/mgr/case/hq');
            $table->integer('version')->default(1)->comment('版本号（每类每月递增）');
            $table->longText('snapshot_data')->nullable()->comment('工资行JSON快照');
            $table->decimal('gross_total', 14, 2)->default(0)->comment('应发合计');
            $table->decimal('net_total', 14, 2)->default(0)->comment('实发合计');
            $table->integer('headcount')->default(0)->comment('人数');
            $table->string('operator', 100)->nullable()->comment('操作人');
            $table->timestamps();
            $table->index(['ym', 'person_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_snapshots');
    }
};
