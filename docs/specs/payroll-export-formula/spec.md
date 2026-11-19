# 工资表导出「带公式 / 纯数值」双版本 · 实现规格（Spec）

> **变更记录（2026-11-19）**：按用户要求去掉带公式版的「扣款合计」列。两版列结构现在完全一致（39 列，列名/顺序/位置逐列相同）；扣款项改为在应发工资合计公式中内联 `SUM(V:Z)`。本文中凡出现「40 列」「扣款合计列」或 `AB/AH/AL` 等旧列地址的表述，均以本节为准：应发=AA、五险一金合计=AG、本月个税=AI、实发=AK、备注=AL、季度绩效说明=AM。

> 范围：数据导出页的工资表导出（整包、全部项目 zip、单项目/单类别）。
> 不动：绩效导出（`PerformanceExportController`）、薪酬计算逻辑、生产库。
> 发布：测试全部通过后先汇报验收，**等用户确认后再按既有流程发布**。

## 1. 已确认需求

| # | 需求 | 落地口径 |
|---|---|---|
| 1 | 导出模式二选一 | `mode=plain`（现有行为） / `mode=formula`（带公式） |
| 2 | 默认值 | 后端配置 `config/payroll.php` 一行：`export_mode`，默认 `formula` |
| 3 | 纯数值版 | 代码路径、39 列、版式、内容逐字节保持；不加说明页/校验区 |
| 4 | 带公式版 | 39 列（与纯数值版逐列一致，不新增列）、合计行 ROUND 公式、校验区、说明页、汇总跨表引用 |
| 5 | 公式列 | 应发工资合计、五险一金合计、实发工资（扣款项内联 SUM）；合计行；汇总页引用 |
| 6 | 快照列 | 应发基本工资、应发绩效工资、本月个税等复杂/系统口径列保留系统值 |
| 7 | 个税 | 直接使用系统结果，不新建税率表、不出现第二套口径 |
| 8 | 缓存与重算 | 每个公式写入缓存值；`calcPr` 设置 `fullCalcOnLoad=1`、`forceFullCalc=1` |
| 9 | 校验区 | 每张明细页合计行下方：系统值 / 公式值 / 差异；差异非 0 红底加粗 |
| 10 | 全表校验 | 说明页：明细页合计之和 vs 汇总页总计 |
| 11 | 文本注入 | `=`、`+`、`-`、`@` 开头的文本单元格按文本存（显式 string） |
| 12 | 列地址 | 按列名动态生成，不写死列号 |

## 2. 模式开关与配置

### 2.1 新增配置
`config/payroll.php`（新建）：

```php
<?php
return [
    // 工资表导出默认模式：formula=带公式版；plain=纯数值版（数据导出页二选一）
    'export_mode' => env('PAYROLL_EXPORT_MODE', 'formula'),
];
```

### 2.2 后端解析
`ExportController` 新增：

```php
private function exportMode(Request $request): string
{
    $mode = strtolower(trim($request->string('mode')->toString()));
    if (in_array($mode, ['plain', 'formula'], true)) return $mode;
    $configured = (string) config('payroll.export_mode', 'formula');
    return in_array($configured, ['plain', 'formula'], true) ? $configured : 'formula';
}
```

- 请求未带 `mode` → 取配置默认值。
- 请求带非法值 → 回退配置默认值（不新增错误码，避免影响既有调用）。
- 作用端点：`summary`、`projectsAll`、`project`、`projectCaseStaff`、`projectManagers`、`caseStaff`、`hqStaff`、`managers`、`managersBundle`。

### 2.3 默认值下发给前端
新增 `GET /api/export/mode`（需登录）：

```json
{ "ok": true, "default": "formula",
  "options": [
    {"value":"formula","label":"带公式（合计自动重算，可核对）"},
    {"value":"plain","label":"纯数值（固定数值，兼容旧版）"}
  ] }
```

前端页面挂载时读取 `default`，保证「改配置一行 → 页面默认值同步变化」。

### 2.4 测试环境
`phpunit.xml` 增加：

```xml
<env name="PAYROLL_EXPORT_MODE" value="plain"/>
```

保证既有测试不带 `mode` 时仍走纯数值版，现有断言全部原样通过；新公式测试显式传 `mode=formula`。

## 3. 纯数值版（plain）

- 完全沿用现有 `fillSheet()`、`summary()` 原代码路径，不新增任何列/页/校验区。
- `xlsx()` 的 plain 分支保持原有 `php://memory` 输出，不经过任何后处理。
- 既有断言必须原样通过：
  - 39 列，末列「季度绩效说明」；
  - 整数不出现尾点、金额为数值；
  - 无「小计（项目）」行；
  - 全量数据包第 1 张 Sheet 为「汇总」。

## 4. 带公式版：明细页

### 4.1 列结构（39 列，与纯数值版完全一致）

不新增任何列。关键列地址：应发工资合计=AA、养老保险=AB、五险一金合计=AG、专项附加扣除=AH、本月个税=AI、个税补差=AJ、实发工资=AK、备注=AL、季度绩效说明=AM。

实现方式：

```php
private function formulaLayout(): array // 返回 [headers, name=>letter 映射, colCount]
```

后续所有公式、合计、校验区、汇总引用均通过 `$col['列名']` 取列地址，禁止写死 `AA/AB`。

### 4.2 行内公式（每行同时写缓存值）

| 列 | Excel 公式 | 缓存值（系统值） |
|---|---|---|
| 应发工资合计 AA | `=ROUND(SUM(M{r}:U{r})-SUM(V{r}:Z{r}),2)`（扣款项内联） | `row.gross` |
| 五险一金合计 AG | `=ROUND(SUM(AB{r}:AF{r}),2)` | `row.soc_total` |
| 实发工资 AK | `=ROUND(AA{r}-AG{r}-AI{r}-U{r}+AJ{r},2)` | `row.net` |

- 公式语义与 `PayrollCalculator` 默认口径一致：应发 = 各项应发合计 − 各项扣款；实发 = 应发 − 五险一金 − 本月个税 − 已发福利 + 个税补差。
- **不写公式、保留系统值**：应发基本工资、应发绩效工资、病假工资、夜班/话费补贴、餐补、其他补贴、月度奖励、补发工资、已发福利、月度扣罚、迟到早退扣款、缺卡扣款、其他扣款、工装扣款、养老保险、医疗保险、失业保险、住房公积金、大病、专项附加扣除、本月个税、个税补差。
- 个税不建税率表、不重算。

### 4.3 合计行

- “合计（N 人）”仍合并 `A:K`。
- 金额列（按列名集合：固定月薪、基本工资、应发基本工资 … 实发工资）逐个写：

```excel
=ROUND(SUM(<列>3:<列>{totalRow-1}),2)
```

- 同时写入缓存值 = 该列系统值之和（`round(...,2)`）。
- 汇总合计行也采用相同缓存口径。

### 4.4 校验区（每张明细页底部）

合计行下方空一行开始：

```
A: 校验区（系统值 vs 公式值）
第2行表头：校验项 | 系统值 | 公式值 | 差异 | 结论
行：应发工资合计 / 五险一金合计 / 实发工资
  B = 该列系统值合计（数值）
  C = =<列>{totalRow}                 // 引用本页合计行
  D = =ROUND(B{r}-C{r},2)             // 缓存 0
  E = =IF(D{r}=0,"一致","不一致")      // 缓存“一致”
```

- 条件格式：`D` 列 `cellIs notEqual 0` → 红底（`FFC7CE`）+ 红字加粗（`9C0006`）。
- 空值、0、0.5 等半点金额均按 `(float)($value ?? 0)` 处理，不抛异常。

### 4.5 文本注入转义

文本列：项目、部门、岗位、姓名、人员状态、入职日期、备注、季度绩效说明。

- 统一用 `setCellValueExplicit($coord, (string)$value, DataType::TYPE_STRING)` 写入。
- `=`、`+`、`-`、`@` 开头的内容不会被当公式执行。

### 4.6 版式

- 标题、表头分区配色、边框、冻结 `F3`、部门合并、列宽沿用纯数值版风格。
- 分区配色按新列序调整：1–9 浅蓝、10–12 浅灰、13–27 浅绿、28–34 浅黄、35–40 浅橙。
- 列宽：在原 39 列宽基础上，于「工装扣款」后插入 12，其余顺延。
- 加粗列：应发工资合计、实发工资。

## 5. 带公式版：汇总页

> 明细页按 39 列布局：应发=AA、五险一金合计=AG、本月个税=AI、实发=AK、个税补差=AJ、月度奖励=S、已发福利=U。

### 5.1 主「汇总」Sheet（仍为第 1 张）

逐项目行改为跨表引用：

| 单元格 | 公式 |
|---|---|
| B 基层员工人数 | `=COUNTA('<项目明细页>'!E3:E{last})` |
| C 基层应发工资合计 | `='<项目明细页>'!AA{total}` |
| D 管理人员人数 | `=COUNTIF('<管理人员>'!B3:B{last},A{r})` |
| E 管理人员应发合计 | `=ROUND(SUMIF('<管理人员>'!B3:B{last},A{r},'<管理人员>'!AA3:AA{last}),2)` |
| F 人数合计 | `=B{r}+D{r}` |
| G 应发合计 | `=ROUND(C{r}+E{r},2)` |
| I 月度预算执行率 | `=IF(H{r}>0,G{r}/H{r},"")` |
| L 较上月增减 | `=IF(K{r}>0,G{r}-K{r},"")` |
| M 较上月幅度 | `=IF(K{r}>0,L{r}/K{r},"")` |

- 保留数值（无明细来源）：月度预算 H、年度预算执行率 J、上月应发合计 K。
- 项目无基层明细页时 B/C 写 0；无管理人员 Sheet 时 D/E 写 0。
- 合计行：`F=SUM(B3:B{lastProj})`、`G=ROUND(SUM(C3:C{lastProj}),2)`，I/L/M 同上。
- 管理人员合计行：`F=COUNTA('<管理人员>'!E3:E{last})`、`G='<管理人员>'!AA{total}`，I/L/M 同上。
- 案场人员合计行 / 物业总部合计行：同样引用各自明细页合计行。
- 总计行：
  - `B=F{基层合计行}`、`C=G{基层合计行}`；
  - `D=F{管理合计行}`（无则 0）、`E=G{管理合计行}`；
  - `F=F{基层}+F{管理}+F{案场}+F{总部}`；
  - `G=ROUND(G{基层}+G{管理}+G{案场}+G{总部},2)`；
  - I/L/M 同上。

所有单元格同时写入原系统汇总值作为缓存。

### 5.2 辅助汇总 Sheet（管理人员汇总 / 案场人员汇总 / 总部人员汇总）

- 每个项目行：
  - B 人数 `=COUNTIF`；
  - C 应发 `=ROUND(SUMIF(...AB),2)`；
  - D 五险 `=ROUND(SUMIF(...AH),2)`；
  - E 本月个税 `=ROUND(SUMIF(...AJ),2)`；
  - F 已发/福利奖金 `=ROUND(SUMIF(...S)+SUMIF(...U),2)`；
  - G 实发 `=ROUND(SUMIF(...AL),2)`；
  - H 调个税差额 `=ROUND(SUMIF(...AK),2)`。
- 合计行直接引用对应明细页合计行：
  - B `=COUNTA(...)`；C `=AB{total}`；D `=AH{total}`；E `=AJ{total}`；
  - F `=ROUND(S{total}+U{total},2)`；G `=AL{total}`；H `=AK{total}`。
- 明细页标题从 `$sheet->getTitle()` 读取，公式中单引号转义（`'` → `''`）。

### 5.3 说明页（仅公式版，最后一张）

标题「导出说明（带公式版）」，内容：

1. 公式列清单与四条公式（扣款合计、应发工资合计、五险一金合计、实发工资）。
2. 系统快照列清单；明确说明应发基本工资/应发绩效工资受缺勤、补差、绩效系数折算影响，直接采用系统值；本月个税采用系统结果，不另设税率表。
3. 校验区用法：系统值 / 公式值 / 差异含义；差异非 0 红底加粗，以系统值为准。
4. 全表校验（仅整包含「汇总」时）：
   - `明细页应发合计之和 = ROUND(SUM('<页1>'!AB{t1}, '<页2>'!AB{t2}, ...),2)`
   - `汇总页总计应发 = '汇总'!G{总计行}`
   - `差异 = ROUND(明细之和-汇总总计,2)`，缓存 0
   - `结论 = IF(差异=0,"一致","不一致")`，差异非 0 红底加粗。
   - 明细页 = 各项目明细 + 管理人员 + 案场人员 + 总部人员（不含汇总页与说明页）。
5. 单项目导出无「汇总」页时，注明「本文件为单项目导出，全表校验见各明细页底部校验区」。

## 6. 缓存值、重算与输出

- 公式单元格用 `setValue('=...')` + `setCalculatedValue($systemValue)` 双写。
- 写出时保持 `Xlsx` 默认 `preCalculateFormulas=true`：PhpSpreadsheet 会计算并写入 `<v>` 缓存（不支持的公式回退到 `setCalculatedValue`）。
- 公式版文件在 `xlsx()` 输出前做一次 zip 后处理，把 `xl/workbook.xml` 的 `<calcPr>` 改为：
  - `fullCalcOnLoad="1"`
  - `forceFullCalc="1"`
  - `calcCompleted="0"`
- plain 分支不做任何后处理。

## 7. 代码改动清单

### 7.1 后端
- 新建 `config/payroll.php`。
- 修改 `app/Http/Controllers/Api/ExportController.php`：
  - 新增 `exportMode(Request)` 接口方法；
  - `summary()` / `projectsAll()` / `project()` / `projectCaseStaff()` / `projectManagers()` / `caseStaff()` / `hqStaff()` / `managers()` / `managersBundle()` 解析模式并分支；
  - 新增 `formulaLayout()`、`fillSheetFormula()`、`fillCheckArea()`、`fillNotesSheet()`、`applyMainSummaryFormulas()`、`applyRollupFormulas()`、`sheetRef()`、`putFormula()`；
  - `fillMgrSummarySheet()` 返回行范围（不改变 plain 输出）；
  - `xlsx()` 增加 `$forceRecalc` 参数与 workbook.xml 后处理；
  - `projectsAll()` 公式模式使用 `fillSheetFormula` 并输出带重算标记的 xlsx。
- 修改 `routes/api.php`：新增 `GET /api/export/mode`。
- 修改 `phpunit.xml`：`PAYROLL_EXPORT_MODE=plain`。

### 7.2 前端
- 修改 `frontend/src/modules/hr/exportLogic.js`：
  - `projectExportTarget(type, ym, project, isAdmin, isProjectScope, userProject, mode='formula')`，URL 追加 `&mode=`。
- 修改 `frontend/src/modules/hr/Export.vue`：
  - 工资表导出卡片顶部增加「导出模式」二选一（默认取 `/api/export/mode` 返回值）；
  - `go()` 给 summary / projectsAll / projectTyped / mgrAll / caseAll / hqAll 全部追加 `&mode=`;
  - 底部文案说明公式版新增扣款合计、校验区、说明页；纯数值版保持 39 列。
- 修改 `frontend/src/modules/hr/exportLogic.test.js`：
  - 增加 mode 参数断言（默认 formula、显式 plain）。

## 8. 测试计划

### 8.1 纯数值回归（既有全部断言）
- `php artisan test` 原有用例全部通过。
- 新增显式断言：
  - `/api/export/project` 无 mode → 39 列，无「扣款合计」，末列「季度绩效说明」；
  - 合计行 A:K 合并，金额列为 `=SUM(...)`；
  - 整数展示无尾点（单元格为数值类型）；
  - 全量数据包第 1 张为「汇总」，无「说明」页；
  - 无「小计（项目）」行。

### 8.2 带公式版（新增 `tests/Feature/PayrollExportFormulaTest.php`）
1. 明细页 39 列（与纯数值版逐列一致），AA=应发、AG=五险、AK=实发、AM=季度绩效说明。
2. 数据行三条公式均为公式（非数字），缓存值等于系统值。
3. 合计行 `=ROUND(SUM(...),2)`，缓存值等于逐行系统值之和。
4. 校验区四项差异缓存为 0，结论为「一致」；差异列存在条件格式。
5. 「汇总」C 列公式指向正确明细页 `AB{total}`；D/E 使用 COUNTIF/SUMIF；总计行引用小计行。
6. 文本注入：备注 `=1+1`、姓名 `@abc` 写入后为 string 类型，值为原文。
7. 空值行、金额 0.5 行导出成功，校验区差异 0。
8. 多项目、多类别（基层/管理/案场/总部）整包 Sheet 顺序：
   `汇总 → 管理人员汇总 → 案场人员汇总 → 总部人员汇总 → 各项目明细 → 管理人员 → 案场人员 → 总部人员 → 说明`。
9. zip（projects_all，mode=formula）内每个 xlsx 为公式版，含缓存值。
10. workbook.xml 含 `fullCalcOnLoad="1"`。

### 8.3 前端
- `cd frontend && npm run test` 通过。
- `cd frontend && npm run build` 通过。

## 9. 本地对照样表

实现完成后，用同一月份、同一范围生成两份文件：

- `e:\vcfw-薪酬系统\_mockups\工资表_YYYY-MM_纯数值.xlsx`
- `e:\vcfw-薪酬系统\_mockups\工资表_YYYY-MM_带公式.xlsx`

汇报：文件路径、Sheet 数、列数、公式数量、缓存值与系统值差异、校验区结果、关键差异。

## 10. 发布闸门

1. 先本地生成两份样表并汇报。
2. 汇报测试结果与验收步骤。
3. **等待用户确认后**再按既有流程发布：版本号顺延、回填 changelog 与文档。
4. 不部署、不改生产库、不动绩效导出。

## 11. 风险与取舍

- 生产 `formula.net` / `formula.gross` 可能是自定义中文变量公式。公式版按默认口径写公式，并用校验区暴露差异；若差异非 0，以系统值为准。说明页明确此规则。
- 汇总页管理人员按项目金额使用 SUMIF（管理人员明细为跨项目单表，无按项目合计行），其余人员类别直接引用明细合计行。
- 两版列结构完全一致（39 列）；公式版仅多出校验区与说明页，属于用户确认的预期差异。
