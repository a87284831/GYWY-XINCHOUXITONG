<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PerformanceSeeder extends Seeder
{
    public function run(): void
    {
        $path = env('LEGACY_DATA_PATH', base_path('../data')) . DIRECTORY_SEPARATOR . 'performance.json';
        if (!is_file($path)) throw new RuntimeException("Performance data not found: {$path}");
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'performance.json'], [
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE), 'imported_at' => now(),
        ]);
        foreach (($data['plans'] ?? []) as $plan) {
            if (!isset($plan['id'])) continue;
            DB::table('performance_plans')->updateOrInsert(['legacy_id' => (int) $plan['id']], [
                'employee_id' => isset($plan['employeeId']) ? (int) $plan['employeeId'] : null,
                'employee_name' => $plan['employeeName'] ?? null, 'project_name' => $plan['project'] ?? null,
                'year' => isset($plan['year']) ? (int) $plan['year'] : null, 'quarter' => isset($plan['quarter']) ? (int) $plan['quarter'] : null,
                'status' => $plan['status'] ?? 'draft', 'data' => json_encode($plan, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }
}
