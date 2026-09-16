<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('employee_name', 120)->nullable();
            $table->string('project_name', 160)->nullable()->index();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_plans');
    }
};
