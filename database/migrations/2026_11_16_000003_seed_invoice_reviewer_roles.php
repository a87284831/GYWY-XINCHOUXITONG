<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            [
                'role_key' => 'invoice_reviewer',
                'name' => '账套审核人',
                'scope' => 'account_set',
                'permissions' => ['invoice_view', 'invoice_review', 'invoice_issue', 'invoice_stats'],
            ],
            [
                'role_key' => 'project_finance',
                'name' => '项目财务',
                'scope' => 'project',
                'permissions' => ['invoice_view', 'invoice_review'],
            ],
        ];

        foreach ($roles as $role) {
            if (DB::table('payroll_roles')->where('role_key', $role['role_key'])->exists()) {
                continue;
            }
            DB::table('payroll_roles')->insert([
                'role_key' => $role['role_key'],
                'name' => $role['name'],
                'scope' => $role['scope'],
                'permissions' => json_encode($role['permissions'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('payroll_roles')->whereIn('role_key', ['invoice_reviewer', 'project_finance'])->delete();
    }
};
