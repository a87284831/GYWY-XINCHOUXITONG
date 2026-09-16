<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_attendance', function (Blueprint $table) {
            $table->id();
            $table->string('record_key', 180)->unique();
            $table->string('year_month', 7)->index();
            $table->string('project_name', 120)->index();
            $table->json('rows');
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_results', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->index();
            $table->unsignedBigInteger('staff_legacy_id')->index();
            $table->string('project_name', 120)->index();
            $table->json('row_data');
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique(['year_month', 'staff_legacy_id']);
        });

        Schema::create('payroll_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->string('project_name', 120)->index();
            $table->json('data');
            $table->timestamps();
            $table->unique(['year', 'project_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_budgets');
        Schema::dropIfExists('payroll_results');
        Schema::dropIfExists('payroll_attendance');
    }
};
