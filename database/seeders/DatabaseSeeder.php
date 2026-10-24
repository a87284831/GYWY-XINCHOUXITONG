<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 本项目不使用 Laravel 默认 User Model，通过业务 seeders 初始化
        $this->command->info('DatabaseSeeder: 跳过默认 User 脚手架，使用业务 seeders');
    }
}
