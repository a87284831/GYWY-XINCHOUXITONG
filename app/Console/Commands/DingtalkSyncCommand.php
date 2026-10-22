<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Api\DingtalkCallbackController;

class DingtalkSyncCommand extends Command
{
    protected $signature = 'dingtalk:sync';
    protected $description = 'Full sync from DingTalk: departments, users, dismissed, roster';

    public function handle(): int
    {
        // flock 进程锁（兜底）：不依赖 Laravel cache，防止上次同步 240s 未完成又启动新进程，
        // 多个同步并发会把 MySQL+CPU 吃满导致整站白屏（2026-10-22 事故）。
        $lockFile = storage_path('framework/dingtalk-sync.lock');
        $lockFp = fopen($lockFile, 'w');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $this->warn('另一实例正在运行，跳过本次同步');
            return 0;
        }
        try {
            $this->info('Starting DingTalk full sync...');
            $start = microtime(true);

            $controller = app(DingtalkCallbackController::class);
            $report = $controller->runFullSync();

            $elapsed = round(microtime(true) - $start, 1);

            $this->info("Sync completed in {$elapsed}s");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['DingTalk departments', $report['dingtalk_depts'] ?? 0],
                    ['DingTalk users', $report['dingtalk_users'] ?? 0],
                    ['DingTalk dismissed', $report['dingtalk_dismissed'] ?? 0],
                    ['New staff', $report['new'] ?? 0],
                    ['Updated staff', $report['updated'] ?? 0],
                    ['Marked resigned', $report['offboard'] ?? 0],
                    ['New resigned', $report['offboard_new'] ?? 0],
                    ['Roster synced', $report['roster'] ?? 0],
                    ['Roster field warnings', $report['roster_field_errors'] ?? 0],
                    ['Roster missing (no HR record)', $report['roster_missing'] ?? 0],
                ]
            );

            foreach ($report['roster_field_error_items'] ?? [] as $item) {
                $this->warn("  ! {$item['name']}: {$item['detail']}");
            }

            return 0;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
        }
    }
}
