<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $source = env('LEGACY_DATA_PATH', base_path('../data')) . DIRECTORY_SEPARATOR . 'maintenance.json';
        if (!is_file($source)) throw new RuntimeException("Maintenance data not found: {$source}");
        $data = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $now = now();
        foreach (($data['partners'] ?? []) as $partner) {
            if (!isset($partner['id'], $partner['name'])) continue;
            DB::table('maintenance_partners')->updateOrInsert(['legacy_id' => (int) $partner['id']], [
                'name' => (string) $partner['name'], 'type' => $partner['type'] ?? 'both', 'contact' => $partner['contact'] ?? null,
                'phone' => $partner['phone'] ?? null, 'remark' => $partner['remark'] ?? null,
                'data' => json_encode($partner, JSON_UNESCAPED_UNICODE), 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
        foreach (($data['contracts'] ?? []) as $contract) {
            if (!isset($contract['id'])) continue;
            DB::table('maintenance_contracts')->updateOrInsert(['legacy_id' => (int) $contract['id']], [
                'type' => $contract['type'] ?? 'fire', 'project_name' => $contract['project_name'] ?? '', 'party' => $contract['party'] ?? '',
                'amount' => (float) ($contract['amount'] ?? 0), 'sign_date' => $contract['sign_date'] ?? null,
                'start_date' => $contract['start_date'] ?? null, 'end_date' => $contract['end_date'] ?? null,
                'elevator_count' => (int) ($contract['elevator_count'] ?? 0), 'building_area_sqm' => (float) ($contract['building_area_sqm'] ?? 0),
                'price_per_sqm' => (float) ($contract['price_per_sqm'] ?? 0), 'price_per_unit' => (float) ($contract['price_per_unit'] ?? 0),
                'pdf_name' => $contract['pdf_name'] ?? null, 'remark' => $contract['remark'] ?? null,
                'data' => json_encode($contract, JSON_UNESCAPED_UNICODE), 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
    }
}
