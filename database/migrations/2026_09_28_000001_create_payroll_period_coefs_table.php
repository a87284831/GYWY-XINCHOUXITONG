<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_period_coefs', function (Blueprint $table) {
            $table->id();
            $table->integer('staff_legacy_id')->comment('人员 legacy_id');
            $table->enum('period_type', ['quarterly', 'half_year'])->comment('周期类型：quarterly=季度，half_year=半年度');
            $table->string('period_key', 10)->comment('周期标识，如 2026-Q1 / 2026-H1');
            $table->decimal('coef', 5, 2)->default(1.00)->comment('绩效系数');
            $table->timestamps();

            $table->unique(['staff_legacy_id', 'period_type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_period_coefs');
    }
};
