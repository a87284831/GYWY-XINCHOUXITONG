<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyJsonSeeder extends Seeder
{
    public function run(): void
    {
        $source = env('LEGACY_DATA_PATH', base_path('../data'));
        if (!is_dir($source)) {
            throw new RuntimeException("Legacy data directory not found: {$source}");
        }

        foreach (glob($source . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $payload = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            DB::table('legacy_json_snapshots')->updateOrInsert(
                ['file_name' => basename($file)],
                ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                 'imported_at' => now()]
            );
        }
    }
}
