<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 钉钉全量同步：每日凌晨 2:00 执行，保证花名册字段（性别/学历/薪酬档位等）修改在 24 小时内同步到本地
// 依赖服务器 cron: * * * * * cd /path-to-laravel-app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('dingtalk:sync')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// 维保预警扫描：每日 8:30（合同分级到期/无到期日、设备年检、签约方资质 → 站内消息，带防重）
Schedule::command('maintenance:scan-alerts')->dailyAt('08:30')->withoutOverlapping()->onOneServer();
