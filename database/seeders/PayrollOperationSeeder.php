<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollOperationSeeder extends Seeder
{
    public function run(): void
    {
        $source = env('LEGACY_DATA_PATH', base_path('../data'));
        if (!is_dir($source)) {
            throw new RuntimeException("Legacy data directory not found: {$source}");
        }
        $read = static function (string $name) use ($source): array {
            $path = $source . DIRECTORY_SEPARATOR . $name;
            return is_file($path) ? (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
        };
        $now = now();

        foreach ($read('attendance.json') as $key => $block) {
            [$ym, $project] = array_pad(explode('|', (string) $key, 2), 2, '');
            DB::table('payroll_attendance')->updateOrInsert(
                ['record_key' => (string) $key],
                ['year_month' => $ym, 'project_name' => $project,
                 'rows' => json_encode($block['rows'] ?? [], JSON_UNESCAPED_UNICODE),
                 'data' => json_encode($block, JSON_UNESCAPED_UNICODE),
                 'updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach ($read('payroll.json') as $ym => $block) {
            $archived = (bool) ($block['archived'] ?? false);
            foreach (($block['rows'] ?? []) as $staffId => $row) {
                DB::table('payroll_results')->updateOrInsert(
                    ['year_month' => (string) $ym, 'staff_legacy_id' => (int) $staffId],
                    ['project_name' => (string) ($row['project'] ?? ''),
                     'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
                     'archived' => $archived, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        foreach ($read('budgets.json') as $year => $projects) {
            foreach ($projects as $project => $budget) {
                DB::table('payroll_budgets')->updateOrInsert(
                    ['year' => (int) $year, 'project_name' => (string) $project],
                    ['data' => json_encode($budget, JSON_UNESCAPED_UNICODE),
                     'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }
}
