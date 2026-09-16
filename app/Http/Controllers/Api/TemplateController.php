<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Services\StaffProfile;

class TemplateController extends ApiController
{
    public function staff(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $book = new Spreadsheet();
        // ===== Sheet1：导入表（表头 + 一行示例，数据从第 3 行开始）=====
        $sheet = $book->getActiveSheet(); $sheet->setTitle('人员批量导入');
        $headers = ['姓名*', '性别', '所属项目*', '所属部门(可空,留空按岗位自动匹配)', '岗位', '固定工资*', '基本工资*',
            '本人联系方式', '入职日期', '转正日期', '身份证号', '出生日期(可空,按身份证推算)', '民族', '婚姻状况',
            '毕业院校', '所学专业', '学历', '毕业时间', '资格证书', '政治面貌',
            '家庭住址', '紧急联系人', '紧急联系人电话', '银行卡号', '招聘渠道', '籍贯', '直属上级(姓名)',
            '离职日期',
            '养老保险(仅参考)', '医疗保险(仅参考)', '失业保险(仅参考)', '住房公积金(仅参考)', '大病(仅参考)',
            '专项-租房租金', '专项-住房贷款利息', '专项-子女教育', '专项-赡养老人', '专项-继续教育', '专项-婴幼儿照护'];
        $example = ['张三(示例,导入前删除本行)', '男', '物业总部', '客服部', '客服管家', 4200, 3000, '13800000000',
            '2024-03-01', '2024-06-01', '371300199001011234', '', '汉族', '未婚',
            '临沂大学', '物业管理', '大专', '2019-06-30', '物业管理员证', '群众',
            '山东省临沂市兰山区xxx路xx号', '李四', '13900000000', '6222000000000000000', '网络招聘', '山东临沂', '',
            '',
            192, 48, 12, 288, 0,
            0, 0, 2000, 0, 0, 0];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($example, null, 'A2');
        // 表头样式：深蓝底白字加粗居中
        $hcol = count($headers);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hcol);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->setSize(10)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5496');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        // 示例行：浅灰底
        $sheet->getStyle("A2:{$lastCol}2")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $sheet->getStyle("A2:{$lastCol}2")->getFont()->setItalic(true)->getColor()->setRGB('808080');
        $sheet->getStyle("A2:{$lastCol}2")->getAlignment()->setVertical('center');
        // 全表边框
        $sheet->getStyle("A1:{$lastCol}2")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getStyle("A1:{$lastCol}2")->getBorders()->getAllBorders()->getColor()->setRGB('D9D9D9');
        $sheet->freezePane('A2');
        // 列宽（按内容合理分配）
        $widths = ['姓名*' => 14, '性别' => 6, '所属项目*' => 12, '所属部门(可空,留空按岗位自动匹配)' => 18, '岗位' => 12,
            '固定工资*' => 10, '基本工资*' => 10, '本人联系方式' => 14, '入职日期' => 12, '转正日期' => 12,
            '身份证号' => 22, '出生日期(可空,按身份证推算)' => 12, '民族' => 8, '婚姻状况' => 8,
            '毕业院校' => 16, '所学专业' => 14, '学历' => 8, '毕业时间' => 12, '资格证书' => 14, '政治面貌' => 10,
            '家庭住址' => 26, '紧急联系人' => 10, '紧急联系人电话' => 14, '银行卡号' => 22, '招聘渠道' => 12,
            '籍贯' => 12, '直属上级(姓名)' => 12, '离职日期' => 12,
            '养老保险(仅参考)' => 10, '医疗保险(仅参考)' => 10, '失业保险(仅参考)' => 10, '住房公积金(仅参考)' => 10, '大病(仅参考)' => 8,
            '专项-租房租金' => 12, '专项-住房贷款利息' => 12, '专项-子女教育' => 12, '专项-赡养老人' => 12,
            '专项-继续教育' => 12, '专项-婴幼儿照护' => 12];
        foreach ($headers as $i => $h) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($letter)->setWidth($widths[$h] ?? 12);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        // 身份证号、银行卡号列设为文本格式（防止科学计数法/丢失精度）
        $idCardCol = array_search('身份证号', $headers, true) + 1;
        $bankCol = array_search('银行卡号', $headers, true) + 1;
        foreach ([$idCardCol, $bankCol] as $ci) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
            $sheet->getStyle("{$letter}2:{$letter}1000")->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle("{$letter}1")->getNumberFormat()->setFormatCode('@');
        }
        // 枚举列数据验证下拉（性别/民族/婚姻/学历/政治面貌/招聘渠道）
        $this->attachValidation($sheet, $headers, '性别', StaffProfile::GENDERS);
        $this->attachValidation($sheet, $headers, '民族', StaffProfile::NATIONS);
        $this->attachValidation($sheet, $headers, '婚姻状况', StaffProfile::MARITAL);
        $this->attachValidation($sheet, $headers, '学历', StaffProfile::EDUCATS);
        $this->attachValidation($sheet, $headers, '政治面貌', StaffProfile::POLITICS);
        $this->attachValidation($sheet, $headers, '招聘渠道', StaffProfile::RECRUITS);

        // ===== Sheet2：填写说明 =====
        $help = $book->createSheet(); $help->setTitle('填写说明');
        $rules = [
            ['列名', '是否必填', '填写说明'],
            ['姓名', '必填', '同一项目下不可重名；已存在的“姓名+项目”将按本行内容更新'],
            ['性别 / 民族 / 婚姻状况 / 学历 / 政治面貌 / 招聘渠道', '可空', '已内置下拉选择；内容必须与下拉项一致，否则整批拒绝'],
            ['所属项目', '必填', '必须与系统“项目档案”中的项目名完全一致（如：物业总部 / 临沂万城花开）'],
            ['所属部门', '可空', '可填：客服部/工程部/秩序部/环境部/总经理办公室；留空则按“岗位”关键词自动匹配'],
            ['岗位', '建议填', '如“客服管家/工程维修/秩序保安/保洁/项目经理”，用于自动归部门与核算'],
            ['固定工资 / 基本工资', '建议填', '元/月，数值型（可填小数）；固定工资=岗位固定月薪，基本工资用于基本工资与绩效工资核算；留空按 0 处理'],
            ['本人联系方式', '可空', '11 位手机号，填写则校验格式（1 开头 11 位数字）'],
            ['入职 / 转正 / 离职日期', '可空', '格式 YYYY-MM-DD；有离职日期且≤今天自动判为离职，次月停止核算'],
            ['身份证号', '可空', '18 位，须通过校验位校验；用于员工自助查询工资条（已设为文本格式，不会变科学计数法）'],
            ['出生日期', '可空', '格式 YYYY-MM-DD；留空则按身份证号第 7-14 位自动推算'],
            ['毕业院校 / 所学专业 / 毕业时间 / 资格证书 / 家庭住址', '可空', '自由文本；资格证书如“物业管理员证/电工证”等，直接填写即可'],
            ['紧急联系人 / 紧急联系人电话', '可空', '联系电话建议 11 位手机号，填写则校验格式'],
            ['籍贯', '可空', '籍贯省市（如：山东临沂）；用于人力资源报表“籍贯分布（按市聚合）”，同一市不同县会自动聚合为市'],
            ['银行卡号', '可空', '用于工资发放（已设为文本格式，长数字不会变科学计数法）'],
            ['直属上级(姓名)', '可空', '按姓名在全库（不限项目）匹配人员；上级必须在系统档案中存在，否则整批拒绝'],
            ['养老/医疗/失业/公积金/大病(仅参考)', '可空', '仅作员工档案参考；每月实际扣缴金额在“考勤表”里填写并参与核算'],
            ['专项附加扣除 6 列', '可空', '元/月，对应个税专项附加：租房租金、住房贷款利息、子女教育、赡养老人、继续教育、婴幼儿照护'],
            ['必填项校验', '说明', '姓名、所属项目始终必填；固定工资、基本工资及其余字段可由管理员在“系统设置→人员档案字段设置”中勾选为必填。任一必填为空、身份证/手机/日期/枚举校验失败、上级不存在、工资非数字 → 整批拒绝导入'],
            ['', '', ''],
            ['操作步骤', '', '① 本模板第 2 行为示例，正式导入前请删除；② 数据从第 2/3 行起逐行填写；③ 保存为 .xlsx 后在“人员档案”页点“批量上传人员”'],
        ];
        $help->fromArray($rules, null, 'A1');
        $help->getStyle('A1:C1')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(30); $help->getColumnDimension('B')->setWidth(14); $help->getColumnDimension('C')->setWidth(95);
        $help->getStyle('C2:C19')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0); // 打开默认显示导入表
        return $this->xlsxResponse($book, '人员批量导入模板.xlsx');
    }

    /** 为指定表头列追加下拉数据验证 */
    private function attachValidation($sheet, array $headers, string $headLabel, array $options): void
    {
        $col = array_search($headLabel, $headers, true);
        if ($col === false) return;
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
        $validation = $sheet->getDataValidation("{$letter}2:{$letter}1000");
        $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $validation->setFormula1('"' . implode(',', array_slice($options, 0, 100)) . '"');
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
    }

    public function budget(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('预算');
        $sheet->fromArray(array_merge(['项目'], array_map(fn ($m) => $m . '月', range(1, 12)), ['年度总预算']), null, 'A1');
        return $this->xlsxResponse($book, $year . '年度预算导入模板.xlsx');
    }

    public function attendance(Request $request)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $ym = $request->string('ym')->toString();
        $project = $this->isProjectScope($account) ? (string) $account->project_name : $request->string('project')->toString();
        if ($project === '' || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return response()->json(['ok' => false, 'error' => '月份或项目无效'], 400);
        }
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('考勤表');

        $daysInMonth = (int) date('t', strtotime($ym . '-01'));
        $baseCols = ['序号', '姓名', '人员状态', '岗位'];
        $dateCols = [];
        for ($day = 1; $day <= $daysInMonth; $day++) $dateCols[] = (string) $day;
        // 出勤统计：应出勤(手填)、实际出勤(天)、月度绩效系数(挪至实际出勤后)、是否满勤、各假缺卡旷工迟到早退
        $statCols = ['应出勤(手填)', '实际出勤(天)', '月度绩效系数', '是否满勤', '事假(天)', '病假(天)', '产假(天)',
            '带薪假(天)', '缺卡(次)', '旷工(天)', '迟到(次)', '早退(次)'];
        // 奖惩/补贴/五险一金/扣款：删除缺卡扣款、迟到早退扣款（薪酬核算自动按次数计），保留其他扣款与工装扣款
        $moneyCols = ['月度奖励金额', '月度扣罚金额', '餐补', '夜班/话费补贴', '职称/证书补贴',
            '养老保险', '医疗保险', '失业保险', '住房公积金', '大病',
            '其他扣款', '工装扣款'];
        $tailCols = ['备注'];
        $headers = array_merge($baseCols, $dateCols, $statCols, $moneyCols, $tailCols);
        $lastCol = count($headers);
        $lastLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);

        // ---- 列区间（与用户模板分区一一对应）----
        $dateStart = count($baseCols) + 1;               // 每日出勤首列
        $dateEnd = count($baseCols) + count($dateCols);   // 每日出勤末列
        $statStart = $dateEnd + 1;                        // 出勤统计首列
        $statEnd = $dateEnd + count($statCols);
        $moneyStart = $statEnd + 1;                       // 奖惩/补贴/五险一金/扣款首列
        $moneyEnd = $statEnd + count($moneyCols);
        $tailStart = $moneyEnd + 1;                       // 绩效/其他（备注）
        $tailEnd = $lastCol;

        // 分区配色（与用户模板完全一致）
        $COL_BASE = 'DDEBF7';   // 基本信息：浅蓝
        $COL_DATE = 'E2EFDA';   // 每日出勤：浅绿
        $COL_STAT = 'F2F2F2';   // 出勤统计：浅灰
        $COL_MONEY = 'FFF2CC';  // 奖惩/补贴/五险一金/扣款：浅黄
        $COL_TAIL = 'EDEDED';   // 绩效/其他：浅灰

        // ===== 第1行：大标题 =====
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->setCellValue('A1', "【{$project}】" . substr($ym, 0, 4) . '年' . (int) substr($ym, 5, 2) . "月考勤表（月度上传模板）");
        $sheet->getStyle('A1')->getFont()->setName('微软雅黑')->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(26);

        // ===== 第2行：五个分区标题 =====
        $d1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateStart);
        $d2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateEnd);
        $s1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart);
        $s2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statEnd);
        $m1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStart);
        $m2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyEnd);
        $t1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($tailStart);
        $t2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($tailEnd);
        $sheet->mergeCells("A2:D2");
        $sheet->setCellValue('A2', '基本信息');
        $sheet->mergeCells("{$d1}2:{$d2}2");
        $sheet->setCellValue("{$d1}2", '每日出勤记录（符号录入）');
        $sheet->mergeCells("{$s1}2:{$s2}2");
        $sheet->setCellValue("{$s1}2", '出勤统计（系统自动核算，无需填写）');
        $sheet->mergeCells("{$m1}2:{$m2}2");
        $sheet->setCellValue("{$m1}2", '奖惩/补贴/五险一金/扣款（人力填写）');
        $sheet->mergeCells("{$t1}2:{$t2}2");
        $sheet->setCellValue("{$t1}2", '绩效/其他');
        $sheet->getStyle("A2:{$lastLetter}2")->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
        $sheet->getStyle("A2:{$lastLetter}2")->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $sheet->getStyle("A2:D2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_BASE);
        $sheet->getStyle("{$d1}2:{$d2}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_DATE);
        $sheet->getStyle("{$s1}2:{$s2}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_STAT);
        $sheet->getStyle("{$m1}2:{$m2}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_MONEY);
        $sheet->getStyle("{$t1}2:{$t2}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_TAIL);
        $sheet->getStyle("A2:{$lastLetter}2")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // ===== 第3行：表头 =====
        $sheet->fromArray($headers, null, 'A3');
        $sheet->getStyle("A3:{$lastLetter}3")->getFont()->setName('微软雅黑')->setBold(true)->setSize(10);
        $sheet->getStyle("A3:{$lastLetter}3")->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $sheet->getStyle("A3:D3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_BASE);
        $sheet->getStyle("{$d1}3:{$d2}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_DATE);
        $sheet->getStyle("{$s1}3:{$s2}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_STAT);
        $sheet->getStyle("{$m1}3:{$m2}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_MONEY);
        $sheet->getStyle("{$t1}3:{$t2}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_TAIL);
        $sheet->getStyle("A3:{$lastLetter}3")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getRowDimension(3)->setRowHeight(30);

        // ===== 第4行：星期 =====
        $sheet->mergeCells("A4:D4");
        $sheet->setCellValue('A4', '星期');
        $sheet->getStyle('A4:D4')->getFont()->setName('微软雅黑')->setSize(9);
        $sheet->getStyle('A4:D4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_BASE);
        $weekNames = ['日', '一', '二', '三', '四', '五', '六'];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = $dateStart + $day - 1;
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}4", $weekNames[(int) date('w', strtotime(sprintf('%s-%02d', $ym, $day)))]);
            $sheet->getStyle("{$letter}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COL_DATE);
        }
        $sheet->getStyle("A4:{$lastLetter}4")->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle("A4:{$lastLetter}4")->getFont()->getColor()->setRGB('808080');
        $sheet->getStyle("A4:{$lastLetter}4")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getRowDimension(4)->setRowHeight(16);

        // ===== 第5行起：人员数据 =====
        // 离职人员过滤：仅包含未离职（resign_date 为空）或离职日期 >= 当月1日的人员
        // （如 8/20 离职出现在 8 月模板、9 月模板不出现）
        $staff = \Illuminate\Support\Facades\DB::table('payroll_staff')
            ->where('project_name', $project)->where('deleted', false)
            ->where(function ($q) use ($ym) {
                $q->whereNull('resign_date')->orWhere('resign_date', '>=', $ym . '-01');
            })
            ->orderBy('name')->get();
        $row = 5;
        // 统计列公式（第2行标注"系统自动核算"，此处写入 Excel 公式让模板打开即自动统计；应出勤列保持手填不带公式）
        $dStartLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateStart); // 日期首列
        $dEndLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateEnd);   // 日期末列
        $reqLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart);   // 应出勤(手填) 列
        $actLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 1); // 实际出勤(天) 列
        $formulaRows = max($staff->count(), 1); // 至少给第5行预留公式行，便于增行复制
        for ($i = 0; $i < $formulaRows; $i++) {
            $r = $row + $i;
            $range = "\${$dStartLetter}{$r}:\${$dEndLetter}{$r}";
            $sheet->setCellValue("{$actLetter}{$r}", '=COUNTIF(' . $range . ',"√")+0.5*COUNTIF(' . $range . ',"半")'
                . '+COUNTIF(' . $range . ',"值")+COUNTIF(' . $range . ',"假")+COUNTIF(' . $range . ',"缺")'
                . '+COUNTIF(' . $range . ',"迟")+COUNTIF(' . $range . ',"早")');
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 3) . $r,
                '=IF(' . $reqLetter . $r . '="","",IF(' . $actLetter . $r . '>=' . $reqLetter . $r . ',"是","否"))');
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 4) . $r, '=COUNTIF(' . $range . ',"事")'); // 事假
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 5) . $r, '=COUNTIF(' . $range . ',"病")'); // 病假
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 6) . $r, '=COUNTIF(' . $range . ',"产")'); // 产假
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 7) . $r, '=COUNTIF(' . $range . ',"假")'); // 带薪假
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 8) . $r, '=COUNTIF(' . $range . ',"缺")'); // 缺卡
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 9) . $r, '=COUNTIF(' . $range . ',"旷")'); // 旷工
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 10) . $r, '=COUNTIF(' . $range . ',"迟")'); // 迟到
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + 11) . $r, '=COUNTIF(' . $range . ',"早")'); // 早退
        }
        foreach ($staff as $index => $person) {
            $sheet->setCellValue("A{$row}", $index + 1); $sheet->setCellValue("B{$row}", $person->name);
            $sheet->setCellValue("C{$row}", $person->status ?: '正式'); $sheet->setCellValue("D{$row}", $person->position ?: '');
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->getAlignment()->setVertical('center');
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->getFont()->setName('微软雅黑')->setSize(10);
            $row++;
        }

        // ===== 每日出勤符号下拉框（从 symbols.json 读取，与上传解析一致）=====
        $symbols = [];
        $symSnap = \Illuminate\Support\Facades\DB::table('legacy_json_snapshots')->where('file_name', 'symbols.json')->first();
        if ($symSnap) {
            foreach (($this->jsonValue((string) $symSnap->payload)['items'] ?? []) as $it) {
                if (!empty($it['symbol'])) $symbols[] = (string) $it['symbol'];
            }
        }
        if (!$symbols) $symbols = ['√', '半', '值', '假', '缺', '迟', '早', '休', '事', '病', '产', '旷'];
        $dropStart = 5;
        $dropEnd = max(4 + $formulaRows + 15, $dropStart + 9); // 覆盖人员行并预留增行余量
        for ($c = $dateStart; $c <= $dateEnd; $c++) {
            $cLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $validation = $sheet->getDataValidation("{$cLetter}{$dropStart}:{$cLetter}{$dropEnd}");
            $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $validation->setFormula1('"' . implode(',', array_slice($symbols, 0, 100)) . '"');
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $validation->setErrorTitle('考勤符号无效');
            $validation->setError('只能填写考勤符号：' . implode('、', $symbols) . '（可点击单元格右侧箭头选择）');
        }

        // ===== 列宽（与用户模板一致）=====
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(9);
        $sheet->getColumnDimension('C')->setWidth(9);
        $sheet->getColumnDimension('D')->setWidth(11);
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dateStart + $day - 1))->setWidth(3.4);
        }
        // 出勤统计区列宽（12列：应出勤6 实际出勤6 月度绩效7 满勤6 事假5 病假5 产假5 带薪假6 缺卡5 旷工5 迟到5 早退5）
        $statWidths = [6, 6, 7, 6, 5, 5, 5, 6, 5, 5, 5, 5];
        foreach ($statWidths as $i => $w) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statStart + $i))->setWidth($w);
        }
        // 奖惩/补贴/五险一金/扣款区列宽（12列：奖励8 扣罚8 餐补6 夜班8 职称8 养老8 医疗8 失业8 公积金7 大病6 其他7 工装7）
        $moneyWidths = [8, 8, 6, 8, 8, 8, 8, 8, 7, 6, 7, 7];
        foreach ($moneyWidths as $i => $w) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStart + $i))->setWidth($w);
        }
        // 备注列
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($tailStart))->setWidth(18);
        $sheet->freezePane('E5');
        return $this->xlsxResponse($book, '考勤表模板_' . $project . '_' . $ym . '.xlsx');
    }

    private function xlsxResponse(Spreadsheet $book, string $filename)
    {
        $stream = fopen('php://memory', 'w+b'); (new Xlsx($book))->save($stream); rewind($stream);
        return response()->streamDownload(fn () => fpassthru($stream), $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
