<?php
/** 财务管理 - 导出 handlers（汇总/台账/付款，生成 xlsx 二进制返回） */

use App\Finance\FinanceStop;
use App\Finance\Support;
use App\Finance\XlsxWriter;

// 依赖同模块 handler（汇总/台账/付款矩阵）
require_once __DIR__ . '/summary.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/payments.php';

/** 返回二进制下载结构（Controller 检测 __binary__ 输出文件流） */
function fin_binary(string $filename, string $content): array
{
    return ['__binary__' => true, 'filename' => $filename, 'content' => $content];
}

/** 汇总导出：应收汇总 + 项目应收汇总 + 付款汇总 三个 sheet */
function handle_export_summary(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);

    $a = handle_summary_annual();
    $pr = handle_summary_projects();
    $pay = handle_summary_payments();
    $all = ($a['year'] === 'all');
    $label = $all ? '截至目前' : $a['year'] . '年';
    $cats = array_keys($a['categories']);
    $catTitles = array_values($a['categories']);

    $w = new XlsxWriter();

    // 表1：应收汇总（月份 × 类别）
    $rows1 = [[$label . '应收汇总（月份 × 类别）', '单位：元']];
    $head = ['月份'];
    foreach ($catTitles as $t) $head[] = $t;
    $head[] = '合计';
    $rows1[] = $head;
    foreach ($a['months'] as $m) {
        $row = [$m];
        foreach ($cats as $k) $row[] = round($a['grid'][$m][$k] ?? 0, 2);
        $row[] = round($a['month_total'][$m] ?? 0, 2);
        $rows1[] = $row;
    }
    $g = [($all ? '累计合计' : '年度合计')];
    foreach ($cats as $k) {
        $s = 0;
        foreach ($a['months'] as $m) $s += $a['grid'][$m][$k] ?? 0;
        $g[] = round($s, 2);
    }
    $g[] = round($a['grand_total'] ?? 0, 2);
    $rows1[] = $g;
    $w->addSheet('应收汇总', $rows1);

    // 表2：项目应收汇总
    $rows2 = [[$label . '项目应收汇总', '单位：元']];
    $head2 = ['项目'];
    foreach ($catTitles as $t) $head2[] = $t;
    $head2[] = '应收合计';
    $head2[] = '减免赠送';
    $rows2[] = $head2;
    foreach ($pr['projects'] as $pj) {
        if ($pj['total'] <= 0) continue;
        $row = [$pj['name']];
        foreach ($cats as $k) $row[] = round($pj['categories'][$k] ?? 0, 2);
        $row[] = round($pj['total'], 2);
        $row[] = round($pj['discount_total'] ?? 0, 2);
        $rows2[] = $row;
    }
    $w->addSheet('项目应收汇总', $rows2);

    // 表3：付款汇总
    $rows3 = [[$label . '付款汇总（项目 × 类型）', '单位：元']];
    $head3 = ['项目'];
    $types = array_keys($pay['types']);
    foreach ($types as $t) $head3[] = $pay['types'][$t];
    $head3[] = '合计';
    $rows3[] = $head3;
    foreach ($pay['projects'] as $pj) {
        if ($pj['total'] <= 0) continue;
        $row = [$pj['name']];
        foreach ($types as $t) $row[] = round($pj['types'][$t] ?? 0, 2);
        $row[] = round($pj['total'], 2);
        $rows3[] = $row;
    }
    $w->addSheet('付款汇总', $rows3);

    $fname = '财务汇总_' . ($all ? '全部年度' : $a['year']) . '.xlsx';
    return fin_binary($fname, $w->bytes());
}

/** 台账导出：某项目 月份×类别 矩阵（含月级状态列） */
function handle_export_ledger(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $d = handle_ledger_matrix();
    $all = ($d['year'] === 'all');
    $label = $all ? '截至目前' : $d['year'] . '年';
    $cats = array_keys($d['categories']);
    $catTitles = array_values($d['categories']);
    $pname = '';
    foreach (fin_projects() as $p) {
        if ((int) $p['id'] === (int) $d['project_id']) { $pname = $p['name']; break; }
    }

    $w = new XlsxWriter();
    $rows = [[$pname . ' 应收费用台账（' . $label . '）', '单位：元']];
    $head = ['月份'];
    foreach ($catTitles as $t) $head[] = $t;
    $head[] = '月度合计';
    $head[] = '地产确认';
    $head[] = '合同签订';
    $head[] = '付款流程';
    $head[] = '减免金额';
    $head[] = '减免户数';
    $head[] = '备注';
    $rows[] = $head;
    $stTxt = fn($v) => $v === null ? '未填' : ($v ? '是' : '否');
    foreach ($d['months'] as $m) {
        $row = [$m['month']];
        foreach ($cats as $k) {
            $row[] = round($m['categories'][$k]['amount'] ?? 0, 2);
        }
        $row[] = round($m['total'], 2);
        $row[] = $stTxt($m['status']['confirm'] ?? null);
        $row[] = $stTxt($m['status']['contract'] ?? null);
        $row[] = $stTxt($m['status']['payment'] ?? null);
        $row[] = round((float) ($m['status']['discount_amount'] ?? 0), 2);
        $row[] = (string) ($m['status']['discount_households'] ?? '');
        $row[] = (string) ($m['status']['remark'] ?? '');
        $rows[] = $row;
    }
    $w->addSheet('应收费用台账', $rows);
    $fname = '应收台账_' . ($all ? '全部年度' : $d['year']) . '.xlsx';
    return fin_binary($fname, $w->bytes());
}

/** 付款导出：三状态区块（已确认数据/已支付/未支付），每块 项目×类型 */
function handle_export_payments(): array
{
    $u = fin_user();
    if (!$u) throw new FinanceStop(['ok' => false, 'msg' => '未登录'], 401);
    $d = handle_payments_matrix();
    $types = array_keys($d['types']);
    $typeTitles = array_values($d['types']);

    $w = new XlsxWriter();
    $w->addSheet('付款记录', [
        ['2026年度关联费用付款记录', '单位：元'],
        array_merge(['项目名称', '总金额'], $typeTitles, ['合计']),
    ]);

    // 三块
    foreach (['confirmed', 'paid', 'unpaid'] as $sk) {
        $block = $d['blocks'][$sk];
        $rows = [[$block['status_name'], '']];
        $head = ['项目名称'];
        foreach ($typeTitles as $t) $head[] = $t;
        $head[] = '合计';
        $rows[] = $head;
        foreach ($block['projects'] as $pj) {
            $row = [$pj['name']];
            foreach ($types as $t) $row[] = round($pj['types'][$t] ?? 0, 2);
            $row[] = round($pj['total'], 2);
            $rows[] = $row;
        }
        $g = ['合计'];
        foreach ($types as $t) {
            $s = 0;
            foreach ($block['projects'] as $pj) $s += $pj['types'][$t] ?? 0;
            $g[] = round($s, 2);
        }
        $g[] = round($block['grand'], 2);
        $rows[] = $g;
        $w->addSheet($block['status_name'], $rows);
    }

    $fname = '付款记录_2026年度.xlsx';
    return fin_binary($fname, $w->bytes());
}
