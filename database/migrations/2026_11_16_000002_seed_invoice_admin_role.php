<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payroll_roles')->where('role_key', 'invoice_admin')->exists()) {
            return;
        }

        DB::table('payroll_roles')->insert([
            'role_key' => 'invoice_admin',
            'name' => '开票管理员',
            'scope' => 'all',
            'permissions' => json_encode([
                'invoice_view',
                'invoice_review',
                'invoice_issue',
                'invoice_stats',
                'invoice_config',
            ], JSON_UNESCAPED_UNICODE),
            'data' => json_encode(['builtin' => true, 'module' => 'invoice'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('payroll_roles')->where('role_key', 'invoice_admin')->delete();
    }
};
