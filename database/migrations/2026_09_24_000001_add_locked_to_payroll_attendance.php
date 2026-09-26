<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payroll_attendance', 'locked')) {
            Schema::table('payroll_attendance', function (Blueprint $table) {
                $table->boolean('locked')->default(false)->after('rows');
                $table->timestamp('locked_at')->nullable()->after('locked');
                $table->string('locked_by', 120)->nullable()->after('locked_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_attendance', 'locked')) {
            Schema::table('payroll_attendance', function (Blueprint $table) {
                $table->dropColumn(['locked', 'locked_at', 'locked_by']);
            });
        }
    }
};
