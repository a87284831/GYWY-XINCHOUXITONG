<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
$ym = '2026-08';
$block = DB::table('payroll_attendance')->where('year_month',$ym)->where('project_name','罗庄春暖花开')->first();
$rows = json_decode($block->rows, true);
$att = $rows['孙涛'];
$person = DB::table('payroll_staff')->where('project_name','罗庄春暖花开')->where('name','孙涛')->where('deleted',false)->first();
$calc = app(App\Services\PayrollCalculator::class);
$ref = new ReflectionClass($calc);
$m = $ref->getMethod('computeRow'); $m->setAccessible(true);
$ms = $ref->getMethod('symbols'); $ms->setAccessible(true);
$sym = $ms->invoke($calc);
$r = $m->invoke($calc, $person, $att, $sym, $ym);
echo "pen={$r['pen']} med={$r['med']} une={$r['une']} house={$r['house']} big={$r['big']} soc={$r['soc_total']} night={$r['night']} meal={$r['meal']} title={$r['title_sub']} base_pay={$r['base_pay']} perf={$r['perf_pay']} gross={$r['gross']} tax={$r['actual_tax']} net={$r['net']}\n";
