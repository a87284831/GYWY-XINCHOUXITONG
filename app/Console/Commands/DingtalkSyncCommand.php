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
            ]
        );

        return 0;
    }
}
