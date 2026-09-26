<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_json_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 120)->unique();
            $table->json('payload');
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_json_snapshots');
    }
};
