<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('legacy_json_snapshots', 'created_at')) {
            Schema::table('legacy_json_snapshots', function (Blueprint $table) {
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('payroll_results', 'is_manager_row')) {
            Schema::table('payroll_results', function (Blueprint $table) {
                $table->boolean('is_manager_row')->default(false);
                $table->boolean('is_case_row')->default(false);
                $table->boolean('is_hq_row')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('legacy_json_snapshots', 'created_at')) {
            Schema::table('legacy_json_snapshots', function (Blueprint $table) {
                $table->dropColumn(['created_at', 'updated_at']);
            });
        }

        if (Schema::hasColumn('payroll_results', 'is_manager_row')) {
            Schema::table('payroll_results', function (Blueprint $table) {
                $table->dropColumn(['is_manager_row', 'is_case_row', 'is_hq_row']);
            });
        }
    }
};
