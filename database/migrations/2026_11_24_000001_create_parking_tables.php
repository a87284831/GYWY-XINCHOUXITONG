<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parking_gates')) {
            Schema::create('parking_gates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id')->index();
                $table->string('name', 60);
                $table->string('entry_token', 64)->unique();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->unique(['project_id', 'name']);
            });
        }
        if (!Schema::hasTable('parking_registrations')) {
            Schema::create('parking_registrations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gate_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->string('gate_name', 60)->default('');
                $table->string('project_name', 120)->default('');
                $table->string('plate', 20);
                $table->string('name', 60);
                $table->string('phone', 20);
                $table->string('visit_target', 120)->default('');
                $table->string('reason', 30)->default('');
                $table->json('extra')->nullable();
                $table->string('ip', 45)->default('');
                $table->timestamps();
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_registrations');
        Schema::dropIfExists('parking_gates');
    }
};
