<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_partners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('name', 160)->unique();
            $table->string('type', 20)->default('both');
            $table->string('contact', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('remark')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
        Schema::create('maintenance_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->unique();
            $table->string('type', 20)->index();
            $table->string('project_name', 160)->index();
            $table->string('party', 160)->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('sign_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('elevator_count')->default(0);
            $table->decimal('building_area_sqm', 14, 2)->default(0);
            $table->decimal('price_per_sqm', 12, 2)->default(0);
            $table->decimal('price_per_unit', 12, 2)->default(0);
            $table->string('pdf_name', 220)->nullable();
            $table->text('remark')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contracts');
        Schema::dropIfExists('maintenance_partners');
    }
};
