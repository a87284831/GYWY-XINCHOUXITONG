<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 空白安装后的首个管理员初始化：
 * php artisan payroll:init-admin admin 你的密码 --name=管理员
 * 账号存 payroll_accounts，role=admin，密码哈希与登录接口同一算法。
 */
class InitAdmin extends Command
{
    protected $signature = 'payroll:init-admin {username} {password} {--name=管理员}';
    protected $description = '创建首个系统管理员账号（空白安装初始化）';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $password = (string) $this->argument('password');
        $name = trim((string) $this->option('name')) ?: '管理员';

        if (!preg_match('/^[A-Za-z0-9_]{3,80}$/', $username)) {
            $this->error('用户名须为 3-80 位字母/数字/下划线');
            return 1;
        }
        if (strlen($password) < 8) {
            $this->error('密码至少 8 位');
            return 1;
        }
        if (DB::table('payroll_accounts')->where('username', $username)->exists()) {
            $this->error('用户名已存在');
            return 1;
        }

        $newId = (int) (DB::table('payroll_accounts')->max('legacy_id') ?? 0) + 1;
        DB::table('payroll_accounts')->insert([
            'legacy_id' => $newId, 'username' => $username, 'name' => $name,
            'role' => 'admin', 'project_name' => null,
            'password_hash' => hash('sha256', 'gwxy_' . $password),
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->info("管理员账号创建成功：{$username}（role=admin，legacy_id={$newId}）");
        return 0;
    }
}
