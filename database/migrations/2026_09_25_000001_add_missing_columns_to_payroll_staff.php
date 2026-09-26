<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_staff', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_staff', 'person_type')) {
                $table->string('person_type', 10)->default('staff')->after('deleted');
            }
            if (!Schema::hasColumn('payroll_staff', 'person_type_since')) {
                $table->date('person_type_since')->nullable()->after('person_type');
            }
            if (!Schema::hasColumn('payroll_staff', 'is_manager')) {
                $table->boolean('is_manager')->default(false)->after('person_type_since');
            }
            if (!Schema::hasColumn('payroll_staff', 'is_case_field')) {
                $table->boolean('is_case_field')->default(false)->after('is_manager');
            }
            if (!Schema::hasColumn('payroll_staff', 'dingtalk_userid')) {
                $table->string('dingtalk_userid', 100)->nullable()->index()->after('is_case_field');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_staff', function (Blueprint $table) {
            $table->dropColumn(['person_type', 'person_type_since', 'is_manager', 'is_case_field', 'dingtalk_userid']);
        });
    }
};
