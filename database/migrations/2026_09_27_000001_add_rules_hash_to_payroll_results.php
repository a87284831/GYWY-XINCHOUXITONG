<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payroll_results 增加 rules_hash：记录该行核算时使用的 calc_rules + symbols 快照指纹，
 * 便于事后追溯"这个月按哪套规则算的"。服务端上线（Task 20 部署清单）时执行迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_results', 'rules_hash')) {
                $table->string('rules_hash', 40)->nullable()->after('row_data');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_results', 'rules_hash')) {
                $table->dropColumn('rules_hash');
            }
        });
    }
};
