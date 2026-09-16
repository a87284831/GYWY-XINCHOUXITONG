<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('type', 30)->default('notice')->index();
            $table->string('title', 200);
            $table->string('content', 500)->default('');
            $table->string('link', 200)->default('');
            $table->string('project_name', 120)->default('')->index();
            $table->boolean('read')->default(false)->index();
            $table->timestamps();
            $table->index(['account_id', 'read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_messages');
    }
};
