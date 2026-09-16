<?php

namespace App\Console\Commands;

use App\Services\PayrollCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalcPayroll extends Command
{
    protected $signature = 'payroll:recalc
        {--ym= : 仅重算指定月份 YYYY-MM}
        {--project= : 仅重算指定项目}
        {--dry-run : 只列出待重算的 (月份, 项目) 组合，不写库}';

    protected $description = '按最新"工资计算规则"重算历史薪资核算（默认重算所有已存在核算结果或考勤的月份×项目）';

    public function handle(PayrollCalculator $calc): int
    {
        $ym = $this->option('ym');
        $project = $this->option('project');
        $dry = (bool)$this->option('dry-run');

        if ($ym !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$ym)) {
            $this->error("--ym 需为 YYYY-MM 格式");
            return self::FAILURE;
        }

        $pairs = $this->collectPairs($ym, $project);
        if (!$pairs) { $this->warn('没有找到待重算的 (月份, 项目) 组合'); return self::SUCCESS; }
        $this->info('待重算 ' . count($pairs) . ' 组');
        if ($dry) { foreach ($pairs as $p) $this->line("  {$p['ym']}  {$p['project']}"); return self::SUCCESS; }

        $bar = $this->output->createProgressBar(count($pairs));
        $bar->start();
        $totals = ['count' => 0, 'preserved' => 0, 'failed' => 0];
        $errors = [];
        foreach ($pairs as $p) {
            try {
                $r = $calc->calculate($p['ym'], [$p['project']]);
                $totals['count'] += $r['count'];
                $totals['preserved'] += $r['preserved_archived'] ?? 0;
                if (($r['skipped'][0] ?? '') === 'no_attendance') {
                    $errors[] = "  {$p['ym']} / {$p['project']}：无考勤数据，已跳过（原结果保留）";
                }
            } catch (\Throwable $e) {
                $totals['failed']++;
                $errors[] = "  {$p['ym']} / {$p['project']}：" . $e->getMessage();
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("完成。写入 {$totals['count']} 行，保留归档 {$totals['preserved']} 行，失败 {$totals['failed']} 组。");
        if ($errors) { $this->warn('提示：'); foreach ($errors as $m) $this->line($m); }
        return self::SUCCESS;
    }

    /** @return array<int, array{ym:string,project:string}> */
    private function collectPairs(?string $ym, ?string $project): array
    {
        $q = DB::table('payroll_results')->select('year_month', 'project_name')->distinct();
        if ($ym) $q->where('year_month', $ym);
        if ($project) $q->where('project_name', $project);
        $fromResults = $q->get()->map(fn($r) => ['ym' => $r->year_month, 'project' => $r->project_name])->all();

        $q2 = DB::table('payroll_attendance')->select('year_month', 'project_name')->distinct();
        if ($ym) $q2->where('year_month', $ym);
        if ($project) $q2->where('project_name', $project);
        foreach ($q2->get() as $r) {
            $k = $r->year_month . '|' . $r->project_name;
            $seen = false;
            foreach ($fromResults as $f) if ($f['ym'] . '|' . $f['project'] === $k) { $seen = true; break; }
            if (!$seen) $fromResults[] = ['ym' => $r->year_month, 'project' => $r->project_name];
        }
        usort($fromResults, fn($a, $b) => [$a['ym'], $a['project']] <=> [$b['ym'], $b['project']]);
        return $fromResults;
    }
}
