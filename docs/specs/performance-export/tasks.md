# 绩效导出独立模块 · 任务清单（Tasks）

> 规格见同目录 `spec.md`。按顺序执行；每步完成后更新状态。

## T1 后端数据服务
- [x] 新建 `app/Services/PerformanceExportService.php`
  - 常量：类别、发放法、错误码中文；
  - 季度归属 `quarterPeriod()`（4/7/10/1 月）；
  - 取数：`payroll_results` 按月份/项目/类别标记筛选，项目账号排除管理人员/总部；
  - 人员档案补充 `regular_date`、`pay_grade`；
  - 发放法解析（pay_rules / 档位 mode / quarterly）；
  - 季度块解析：`perf_quarter` 优先，回退 `perf_detail`（含 half_year）；
  - 生成列定义、行值、Sheet 分组（管理人员 follow 拆分）、汇总分组、提示与 >1 清单。
- 验证：`php -l` 通过。

## T2 后端控制器与路由
- [x] 新建 `app/Http/Controllers/Api/PerformanceExportController.php`
  - `preview()`：参数校验 + 权限 + 数据服务 → JSON；
  - `export()`：`book` / `summary` / `split` 三种输出；
  - 明细 Sheet：标题、表头分区配色、项目小计、总计、备注、冻结、列宽、数字格式；
  - 汇总 Sheet：类别 → 项目 → 类别小计 → 总计；
  - 说明 Sheet：口径、参数、提示、>1 清单；
  - 权限 `perf_export`（admin 或 role.permissions 含 `perf_export`/`*`）。
- [x] `routes/api.php` 新增两条 GET 路由（preview / export）。
- [x] `app/Services/AppModules.php` 增加 `perf_export` 权限点。
- 验证：`php -l` 通过。

## T3 PHPUnit 测试
- [x] 新建 `tests/Feature/PerformanceExportTest.php`，覆盖 spec 5.1 的 12 项。
- [x] 运行 `php artisan test --filter PerformanceExportTest`，全部通过。

## T4 前端逻辑与页面
- [x] 新建 `frontend/src/modules/hr/performanceExportLogic.js`。
- [x] 新建 `frontend/src/modules/hr/PerformanceExport.vue`：
  - 条件面板（月份、类别多选、项目、发放法、导出方式、导出按钮）；
  - 提示区（warnings）、工作表卡片、表样前 3 行、发放周期配置；
  - 调用 `/api/export/performance-detail/preview` 与 `/api/export/performance-detail`。
- [x] `frontend/src/router/index.js` 增加 `/performanceExport` 路由（perm `perf_export`）。
- [x] `frontend/src/nav/menus.js` 增加菜单、页面标题、`HR_PAGES`。

## T5 前端测试与构建
- [x] 新建 `frontend/src/modules/hr/performanceExportLogic.test.js`。
- [x] `cd frontend && npm run test` 全量通过。
- [x] `cd frontend && npm run build` 通过。

## T6 后端全量回归
- [x] `php artisan test` 全量通过。
- [x] 确认 `ExportController::performance` 与 `Export.vue` 未被修改（`git diff` 核对）。

## T7 交付汇报
- [x] 汇总改动文件、测试结果、页面入口、权限点、未部署说明。
- [x] 不提交、不部署，等待用户确认。
