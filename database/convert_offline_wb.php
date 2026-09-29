<?php

/**
 * 线下工资工作簿（Excel COM 导出的值版 JSON）→ 标准 35 列导入 xlsx。
 * 用法: php database/convert_offline_wb.php <值版json> <月份如2026-01> <输出xlsx>
 *
 * 线下列映射（1 起）：
 *   2公司→项目 3部门 4岗位 5姓名 7人员状态(转正→正式) 8固定月薪 9基本工资 10应出勤 11出勤→实际出勤
 *   12绩效系数 13→应发基本 14→应发绩效 15病假 16工程值班+17话补→夜班/话费 18餐补 19其他奖励→其他补贴
 *   20已发福利 21缺卡→缺卡扣款 22迟到→迟到早退 23其他处罚→月度扣罚 24工装→工装扣款
 *   25本月工资合计→应发合计 26-30五险一金明细 31合计→五险一金合计 32-36专项附加五项相加→专项附加扣除
 *   42本月实交个税→本月个税 43实发 44备注
 *   跳过：1序号 6入职时间 37扣除合计 38-41旧口径累计税列 45银行卡号
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

[, $jsonPath, $ym, $outPath] = $argv;
$raw = file_get_contents($jsonPath);
if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
$wb = json_decode($raw, true);
if (!$wb) { fwrite(STDERR, 'JSON 解析失败: ' . json_last_error_msg() . "\n"); exit(1); }

$num = function ($v): float {
    if ($v === null || $v === '' || $v === '#ERR') return 0.0;
    return is_numeric($v) ? (float)$v : 0.0;
};

$out = new Spreadsheet();
$out->removeSheetByIndex(0);
$sheet = $out->createSheet();
$sheet->setTitle($ym);
$sheet->setCellValue('A1', "$ym 历史工资导入");
$sheet->fromArray(['序号', '项目', '部门', '岗位', '姓名', '人员状态', '固定月薪', '基本工资', '应出勤', '实际出勤',
    '绩效系数', '应发基本工资', '应发绩效工资', '病假工资', '夜班/话费补贴', '餐补', '其他补贴', '月度奖励',
    '已发福利', '月度扣罚', '迟到早退扣款', '缺卡扣款', '其他扣款', '工装扣款', '应发工资合计', '养老保险',
    '医疗保险', '失业保险', '住房公积金', '大病', '五险一金合计', '专项附加扣除', '本月个税', '实发工资', '备注'], null, 'A2');

$rOut = 3;
$rows = 0; $errCells = 0; $zeroGross = []; $merged = [];

// 线下表姓名异体字修正（2026-01 工作簿实测：莒南花开 sheet「髙述升」U+9AD9 ≠ 档案「高述升」U+9AD8）
$nameFix = ['髙' => '高'];
// 线下项目简称 → 系统项目全名（依据：同 sheet 其余人员档案项目归属）
//   罗庄沂州 sheet 11 人项目列均写「沂州天珺」，档案全在「罗庄沂州花开」
//   莒南花开 sheet 的岚山写法，9 人中 8 人档案在「日照万城花开」（陈斌重名按项目归位日照）
$projectMap = [
    '沂州天珺' => '罗庄沂州花开',
    '岚山万城花开' => '日照万城花开',
];

foreach ($wb as $sheetName => $data) {
    if (str_contains($sheetName, '汇总')) continue;
    foreach ($data as $idx => $row) {
        $r = $idx + 1;
        if ($r <= 3) continue;                       // 1 标题 / 2-3 表头
        $name = trim(strtr((string)($row[4] ?? ''), $nameFix));
        if ($name === '' || str_contains($name, '合计') || str_contains($name, '总计')) continue;
        $project = trim((string)($row[1] ?? ''));
        $project = $projectMap[$project] ?? $project;
        if ($project === '') continue;

        $errHere = 0;
        foreach ([16, 17, 19, 20, 25, 31, 32, 33, 34, 35, 36, 42, 43] as $ci) {
            if (($row[$ci - 1] ?? null) === '#ERR') $errHere++;
        }
        $errCells += $errHere;

        $status = trim((string)($row[6] ?? ''));
        if ($status === '转正') { $status = '正式'; $merged[] = "{$sheetName}:{$name} 状态转正→正式"; }

        $spec = $num($row[31] ?? null) + $num($row[32] ?? null) + $num($row[33] ?? null)
            + $num($row[34] ?? null) + $num($row[35] ?? null);
        $night = $num($row[15] ?? null) + $num($row[16] ?? null);
        $gross = $num($row[24] ?? null);

        $vals = [
            $rOut - 2,                                    // 序号
            $project,
            trim((string)($row[2] ?? '')),
            trim((string)($row[3] ?? '')),
            $name,
            $status ?: '',
            $num($row[7] ?? null),                        // 固定月薪
            $num($row[8] ?? null),                        // 基本工资
            $num($row[9] ?? null),                        // 应出勤
            $num($row[10] ?? null),                       // 实际出勤
            $num($row[11] ?? null),                       // 绩效系数
            $num($row[12] ?? null),                       // 应发基本
            $num($row[13] ?? null),                       // 应发绩效
            $num($row[14] ?? null),                       // 病假
            $night,                                       // 夜班/话费
            $num($row[17] ?? null),                       // 餐补
            $num($row[18] ?? null),                       // 其他补贴（其他奖励）
            0,                                            // 月度奖励（线下无独立列）
            $num($row[19] ?? null),                       // 已发福利
            $num($row[22] ?? null),                       // 月度扣罚（其他处罚）
            $num($row[21] ?? null),                       // 迟到早退
            $num($row[20] ?? null),                       // 缺卡
            0,                                            // 其他扣款
            $num($row[23] ?? null),                       // 工装
            $gross,                                       // 应发合计
            $num($row[25] ?? null), $num($row[26] ?? null), $num($row[27] ?? null),
            $num($row[28] ?? null), $num($row[29] ?? null),
            $num($row[30] ?? null),                       // 五险一金合计
            $spec,
            $num($row[41] ?? null),                       // 本月个税
            $num($row[42] ?? null),                       // 实发
            trim((string)($row[43] ?? '')),               // 备注
        ];
        $sheet->fromArray($vals, null, "A{$rOut}");
        if ($gross == 0) $zeroGross[] = "{$sheetName} 第{$r}行 {$name}";
        $rOut++; $rows++;
    }
}

(new Xlsx($out))->save($outPath);
echo "输出: $outPath\n";
echo "数据行: $rows  #ERR 影响单元格: $errCells\n";
echo "应发合计=0 的行: " . (count($zeroGross) ? implode('; ', array_slice($zeroGross, 0, 10)) . (count($zeroGross) > 10 ? ' …共' . count($zeroGross) . '行' : '') : '无') . "\n";
echo "状态映射: " . (count($merged) ? implode('; ', array_slice($merged, 0, 5)) . (count($merged) > 5 ? ' …共' . count($merged) . '条' : '') : '无') . "\n";
