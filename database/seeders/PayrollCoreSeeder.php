<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollCoreSeeder extends Seeder
{
    public function run(): void
    {
        $source = env('LEGACY_DATA_PATH', base_path('../data'));
        if (!is_dir($source)) {
            throw new RuntimeException("Legacy data directory not found: {$source}");
        }

        $read = static function (string $name) use ($source): array {
            $path = $source . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                return [];
            }
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        };
        $date = static fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value)
            ? substr((string) $value, 0, 10) : null;
        $now = now();

        foreach ($read('projects.json') as $project) {
            DB::table('payroll_projects')->updateOrInsert(
                ['name' => (string) ($project['name'] ?? '')],
                ['status' => (string) ($project['status'] ?? '启用'),
                 'data' => json_encode($project, JSON_UNESCAPED_UNICODE),
                 'updated_at' => $now, 'created_at' => $now]
            );
        }
        foreach ($read('roles.json') as $role) {
            if (empty($role['id'])) {
                continue;
            }
            DB::table('payroll_roles')->updateOrInsert(
                ['role_key' => (string) $role['id']],
                ['name' => (string) ($role['name'] ?? $role['id']),
                 'scope' => (string) ($role['scope'] ?? 'all'),
                 'permissions' => json_encode($role['perms'] ?? [], JSON_UNESCAPED_UNICODE),
                 'data' => json_encode($role, JSON_UNESCAPED_UNICODE),
                 'updated_at' => $now, 'created_at' => $now]
            );
        }
        foreach ($read('staff.json') as $staff) {
            if (!isset($staff['id'], $staff['name'])) {
                continue;
            }
            DB::table('payroll_staff')->updateOrInsert(
                ['legacy_id' => (int) $staff['id']],
                ['name' => (string) $staff['name'],
                 'project_name' => (string) ($staff['project'] ?? ''),
                 'position' => $staff['position'] ?? null,
                 'status' => $staff['status'] ?? null,
                 'fixed_monthly' => (float) ($staff['fixed_monthly'] ?? 0),
                 'base_salary' => (float) ($staff['base_salary'] ?? 0),
                 'hire_date' => $date($staff['hire_date'] ?? null),
                 'regular_date' => $date($staff['regular_date'] ?? null),
                 'resign_date' => $date($staff['resign_date'] ?? null),
                 'deleted' => (bool) ($staff['deleted'] ?? false),
                 'data' => json_encode($staff, JSON_UNESCAPED_UNICODE),
                 'updated_at' => $now, 'created_at' => $now]
            );
        }
        foreach ($read('users.json') as $user) {
            if (!isset($user['id'], $user['username'], $user['password'])) {
                continue;
            }
            DB::table('payroll_accounts')->updateOrInsert(
                ['legacy_id' => (int) $user['id']],
                ['username' => (string) $user['username'],
                 'name' => $user['name'] ?? null,
                 'role' => (string) ($user['role'] ?? 'viewer'),
                 'project_name' => $user['project'] ?? null,
                 'password_hash' => (string) $user['password'],
                 'data' => json_encode($user, JSON_UNESCAPED_UNICODE),
                 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}
