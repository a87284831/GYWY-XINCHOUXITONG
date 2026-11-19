# 工资表导出「带公式 / 纯数值」双版本 · 任务清单（Tasks）

> 规格见同目录 `spec.md`。
> 状态：T1–T9 已完成并验证；T10 已汇报并获用户发布授权。
> **2026-11-19 调整**：去掉「扣款合计」列，两版统一 39 列；扣款项在应发公式中内联 `SUM(V:Z)`。

## T1 配置与模式开关
- [x] 新建 `config/payroll.php`（`export_mode`，默认 `formula`）。
- [x] `phpunit.xml` 增加 `PAYROLL_EXPORT_MODE=plain`，保证既有测试走纯数值版。
- [x] `ExportController` 增加 `resolveExportMode(Request)` 解析。
- [x] `routes/api.php` 增加 `GET /api/export/mode`。
- 验证：`php -l` 通过；无 mode 请求落到配置默认值。

## T2 带公式版明细页
- [x] 新增 `formulaLayout()`：39 列头（与纯数值版一致）+ 列名→列字母映射。
- [x] 新增 `fillSheetFormula()`：
  - 标题/表头/边框/冻结/部门合并/列宽；
  - 文本列显式 string 写入（公式注入转义）；
  - 应发工资合计（扣款项内联）、五险一金合计、实发工资三条公式 + 缓存值；
  - 合计行 `=ROUND(SUM(...),2)` + 缓存值；
  - 底部校验区（系统值/公式值/差异/结论）+ 差异非 0 红底加粗条件格式；
  - 返回合计行行号。
- 验证：`php -l`；公式与缓存值一致。

## T3 汇总页跨表引用
- [x] `summary()` 记录项目行/各合计行/总计行行号（不影响 plain 输出）。
- [x] 新增 `applyMainSummaryFormulas()`：人数、基层应发、管理人数/应发、人数合计、应发合计、执行率/环比公式。
- [x] `fillMgrSummarySheet()` 返回行范围；新增 `applyRollupFormulas()` 处理管理人员汇总/案场人员汇总/总部人员汇总。
- [x] `sheetRef()` 统一处理页签引用与单引号转义。

## T4 说明页与全表校验
- [x] 新增 `fillNotesSheet()`：
  - 公式列 / 快照列清单与公式说明；
  - 校验区用法；
  - 全表校验（明细页合计之和 vs 汇总总计），差异非 0 红底加粗；
  - 单项目导出无汇总页时给出对应说明。
- [x] 公式版整包最后追加「说明」页；plain 不追加。

## T5 缓存值与打开时重算
- [x] `xlsx()` 增加 `$forceRecalc`；公式版输出前改写 `xl/workbook.xml` 的 `calcPr`（fullCalcOnLoad/forceFullCalc/calcCompleted）。
- [x] `projectsAll()` 公式模式使用 `fillSheetFormula` 并带重算标记；plain 路径保持原内存输出。
- 验证：解压 xlsx 检查 `<v>` 缓存与 `fullCalcOnLoad="1"`。

## T6 接入各导出端点
- [x] `project` / `projectCaseStaff` / `projectManagers` / `caseStaff` / `hqStaff` / `managers` 公式模式走 `fillSheetFormula` + 说明页。
- [x] `managersBundle` 公式模式：明细公式版 + 管理人员汇总引用明细合计行 + 说明页。
- [x] `summary` / `projectsAll` 按模式分支。
- 验证：每个端点 plain 输出与改动前一致。

## T7 前端开关
- [x] `Export.vue`：
  - 工资表导出卡片增加「导出模式」二选一；
  - 挂载时读取 `/api/export/mode` 的默认值；
  - 所有工资表导出 URL 追加 `mode`；
  - 更新底部说明文案。
- [x] `exportLogic.js`：`projectExportTarget` 增加 `mode` 参数并拼入 URL。
- [x] `exportLogic.test.js`：补充 mode 断言。

## T8 测试
- [x] 新增 `tests/Feature/PayrollExportFormulaTest.php`，覆盖 spec 8.2 的 10 项。
- [x] 纯数值回归断言（39 列、无扣款合计、汇总第一、无说明页）。
- [x] `php artisan test`：仅既有 `ChangelogApiTest` 失败（与本任务无关），其 40 passed / 2734 assertions。
- [x] `cd frontend && npm run test` 通过。
- [x] `cd frontend && npm run build` 通过。

## T9 本地两份样表
- [x] 同月同范围生成纯数值版与带公式版到 `_mockups/`。
- [x] 逐列比对：两版均为 39 列，列名/顺序完全一致，差异为空（`工资表_2026-09_列比对.txt`）。
- [x] 校验差异全部为 0；公式独立重算 0 差异、0 错误。

## T10 汇报与发布
- [x] 汇报：开关位置与默认值、公式覆盖列、校验区表现、测试结果、两份样表路径。
- [x] 用户已确认发布。
- [ ] 按既有流程发布：版本号顺延、changelog、开发记录、项目开发文档、交接文档。
- [x] 不部署生产库、不动绩效导出。
