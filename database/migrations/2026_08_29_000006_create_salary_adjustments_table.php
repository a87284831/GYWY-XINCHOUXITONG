<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_legacy_id')->index();
            $table->string('name', 120);
            $table->string('project_name', 120)->index();
            $table->string('type', 40)->default('调薪');
            $table->date('effective_date');
            $table->decimal('old_fixed', 12, 2)->default(0);
            $table->decimal('old_base', 12, 2)->default(0);
            $table->decimal('new_fixed', 12, 2)->default(0);
            $table->decimal('new_base', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('created_by', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_adjustments');
    }
};
