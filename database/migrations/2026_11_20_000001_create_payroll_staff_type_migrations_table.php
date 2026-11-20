<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_staff_type_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_legacy_id')->index();
            $table->string('staff_name', 100)->nullable();
            $table->string('from_type', 20)->nullable();
            $table->string('to_type', 20);
            $table->string('from_project', 100)->nullable();
            $table->string('to_project', 100)->nullable();
            $table->json('moved_months')->nullable();
            $table->unsignedInteger('moved_rows')->default(0);
            $table->unsignedInteger('deleted_rows')->default(0);
            $table->json('archived_months')->nullable();
            $table->string('operator', 100)->nullable();
            $table->string('source', 30)->default('staff_edit');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_staff_type_migrations');
    }
};
