<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('status', 20)->default('启用');
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_roles', function (Blueprint $table) {
            $table->string('role_key', 80)->primary();
            $table->string('name', 120);
            $table->string('scope', 20)->default('all');
            $table->json('permissions')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('name', 120);
            $table->string('project_name', 120)->index();
            $table->string('position', 120)->nullable();
            $table->string('status', 20)->nullable();
            $table->decimal('fixed_monthly', 12, 2)->default(0);
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->date('hire_date')->nullable();
            $table->date('regular_date')->nullable();
            $table->date('resign_date')->nullable();
            $table->boolean('deleted')->default(false);
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('username', 80)->unique();
            $table->string('name', 120)->nullable();
            $table->string('role', 80);
            $table->string('project_name', 120)->nullable()->index();
            $table->string('password_hash', 255);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_accounts');
        Schema::dropIfExists('payroll_staff');
        Schema::dropIfExists('payroll_roles');
        Schema::dropIfExists('payroll_projects');
    }
};
