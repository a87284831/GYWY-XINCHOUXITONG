<?php

namespace App\Console\Commands;

use App\Services\MaintenanceService;
use App\Services\MessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 维保预警扫描（每日）：
 * - 合同：90/60/30 天分级到期 + 已过期 + 无到期日
 * - 设备：年检 30 天内或已过期
 * - 签约方：资质 30 天内或已过期
 * 防重：maintenance_alert_logs（同对象同档位同基准日只发一次）。
 * 收件人：总部 admin + 对应项目账号（签约方资质仅总部）。
 */
class MaintenanceScanAlerts extends Command
{
    protected $signature = 'maintenance:scan-alerts';
    protected $description = '扫描维保合同/设备年检/签约方资质到期预警并推送站内消息';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        foreach (DB::table('maintenance_contracts')->get() as $c) {
            $status = MaintenanceService::contractStatus($c->end_date, $today);
            if ($status === 'normal') continue;
            if (!$this->markOnce('contract', (int) $c->legacy_id, $status, $c->end_date)) continue;
            $typeLabel = $c->type === 'elevator' ? '电梯' : '消防';
            $link = $c->type === 'elevator' ? '/maintElev' : '/maintFire';
            $title = "【维保预警】{$c->project_name} {$typeLabel}维保合同" . MaintenanceService::statusLabel($status);
            $content = '签约方：' . ($c->party ?: '未填') . '；到期日：' . ($c->end_date ?: '未登记') . '。请及时跟进续签或重新招标。';
            $sent += $this->notify($c->project_name, $title, $content, $link);
        }

        foreach (DB::table('maintenance_devices')->whereNotNull('inspect_date')->get() as $d) {
            $inspect = now()->parse($d->inspect_date)->startOfDay();
            if ($inspect->gt($today->copy()->addDays(30))) continue;
            if (!$this->markOnce('device', (int) $d->legacy_id, 'inspect', $d->inspect_date)) continue;
            $typeLabel = $d->type === 'elevator' ? '电梯' : '消防';
            $title = "【维保预警】{$d->project_name} {$typeLabel}设备年检" . ($inspect->lt($today) ? '已过期' : '即将到期');
            $content = "设备编号：{$d->device_no}；年检日期：{$d->inspect_date}。请安排年检。";
            $sent += $this->notify($d->project_name, $title, $content, '/maintDevices');
        }

        foreach (DB::table('maintenance_partners')->whereNotNull('qualification_expire')->get() as $p) {
            $exp = now()->parse($p->qualification_expire)->startOfDay();
            if ($exp->gt($today->copy()->addDays(30))) continue;
            if (!$this->markOnce('partner', (int) $p->legacy_id, 'qualification', $p->qualification_expire)) continue;
            $title = "【维保预警】签约方「{$p->name}」资质" . ($exp->lt($today) ? '已过期' : '即将到期');
            $content = "资质有效期至：{$p->qualification_expire}。请索取更新资质证明。";
            $sent += $this->notify(null, $title, $content, '/maintPartners');
        }

        $this->info("maintenance alerts sent: {$sent}");
        return self::SUCCESS;
    }

    /** 同对象同档位同基准日只发一次（首次插入成功才允许发送） */
    private function markOnce(string $objType, int $objId, string $level, ?string $refDate): bool
    {
        return DB::table('maintenance_alert_logs')->insertOrIgnore([
            'obj_type' => $objType, 'obj_id' => $objId, 'level' => $level,
            'ref_date' => $refDate, 'created_at' => now(), 'updated_at' => now(),
        ]) > 0;
    }

    /** 推送给总部 admin + 项目账号（project 为 null 时仅总部） */
    private function notify(?string $project, string $title, string $content, string $link): int
    {
        $ids = [];
        foreach (DB::table('payroll_accounts')->where('enabled', true)->get() as $acc) {
            if ($acc->role === 'admin') { $ids[] = (int) $acc->id; continue; }
            if ($project !== null && (string) $acc->project_name === $project) $ids[] = (int) $acc->id;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) return 0;
        MessageService::push($ids, 'remind', $title, $content, $link, $project ?? '');
        return count($ids);
    }
}
