# 绩效导出独立模块 · 实现规格（Spec）

> 目标：在「薪资模块」下新增独立菜单「绩效导出」，承载多类别、多发放法、多 Sheet 的绩效 Excel 导出。
> 约束：不改动「数据导出 → ③ 绩效工资专项表」（`ExportController::performance` / `Export.vue` 的 `go('perf')` 保持原样）；不写库；不部署。

## 1. 已确认需求（不再提问）

| # | 需求 | 实现口径 |
|---|---|---|
| 1 | 人员类别多选，每类一张 Sheet | 基层员工 / 管理人员 / 案场人员 / 总部人员；勾选哪几类导出哪几类 |
| 2 | 管理人员「跟随」模式拆两张 Sheet | 「管理人员-季度」（季度型档位）与「管理人员-月度」（月度型档位）；其他模式按所选发放法单表 |
| 3 | 汇总 Sheet 按「类别 → 项目」 | 项目行 → 类别小计 → 总计 |
| 4 | 出勤系数 > 1 | Sheet 末列「备注」标注 + 导出提示区/说明 Sheet 清单 |
| 5 | 系数 0 与 1.0 | 均按实际值计算，不视为未录入；仅空值/缺档位/未配规则标异常 |
| 6 | 出勤系数 | 不封顶，保留 2 位小数 |
| 7 | 发放法 | 跟随 pay_rules（默认）/ 统一月度 / 统一季度 / 统一分期兑现 |
| 8 | 导出方式 | 整包（类别 Sheet + 汇总 + 说明）/ 仅汇总 / 分类打包 zip |
| 9 | 菜单与权限 | 新增权限点 `perf_export`，菜单、路由、后端接口三处一致校验 |
| 10 | 旧专项表 | 「数据导出 → ③ 绩效工资专项表」保留不动 |

## 2. 现状结论（已核实，不再探索）

- 季度归属：结算月 4→Q1、7→Q2、10→Q3、1→上年 Q4（`PayrollCalculator` L1707-1726）；季内月份为结算月前 3 个月。
- 季度结果：结算月工资行 `row_data.perf_quarter = {period, months[{ym,base,perf_att,perf_req,amount}], base, att, req, ratio, coef, amount, pay_grade}`；`amount = round(base×att/req×coef,2)`。
- 旧季度结果：`row_data.perf_detail`（可能含 `ratio`、`half_year`）；`months[].amount` 在 quarterly 法下为「基数×出勤比」，在旧 quarter_grade 法下为月基数。
- 月度结果：`row_data.coef`、`req_att`、`perf_att`、`fixed`、`base`、`perf_pay`、`perf_base_month`（仅季度型写入）、`perf_req`（仅季度型写入）。
- 发放规则：`legacy_json_snapshots(calc_rules.json).rules.pay_rules.{staff|case|manager|hq}`，由 `CalcRules::getPayRule()` 解析：
  - `monthly` → 月度；
  - `quarter_grade` → 按档位 `levels[档位].mode`：`quarter`→季度型，`monthly`→月度型；
  - `quarterly` → 分期兑现。
- 人员档案：`payroll_staff.regular_date` 与 `data.pay_grade`，用于「转正日期 / 薪酬档位」。
- 权限：账号角色 → `payroll_roles.permissions`（JSON 数组，可含 `*`）；admin 全通过；项目账号由 `payroll_roles.scope=project` 判定。
- 现有导出均为 `X-Token` 鉴权 + 控制器内校验，无 Laravel 中间件鉴权。

## 3. 后端实现

### 3.1 新增文件

- `app/Services/PerformanceExportService.php`
  - `dataset(string $ym, array $cats, string $project, string $method, bool $projectScope, ?string $scopeProject): array`
  - 负责：取数、类别/发放法归属、季度块解析（新 `perf_quarter` / 旧 `perf_detail`）、列定义、行值、Sheet 分组、汇总分组、提示清单。
  - 返回结构：
    ```
    [
      ym, quarter, quarter_months, is_quarter_end, method, method_label,
      sheets: [{title, cat, cat_label, method, method_label, columns:[{label,type}], entries:[...]}],
      categories: [{key,label,count,archived_count,status,level,method_label}],
      warnings: [{level:'danger'|'warn'|'info'|'ok', text}],
      ratio_over_one: [{cat,project,name,ratio}],
      config: [{label,value}],
      filename: '绩效导出_YYYY-MM_...xlsx'
    ]
    ```
  - 行值（entry）核心字段：`fixed/base/coef/req_att/perf_att/ratio/standard/period_amount/quarter_amount/monthly_amount/half_amount/quarter/installment/status/notes`。
  - 列类型：`text` / `money` / `ratio` / `count` / `date`，供控制器统一填充与求和。
- `app/Http/Controllers/Api/PerformanceExportController.php`
  - `preview(Request)` → JSON（不生成文件，供页面提示区/Sheet 卡片/表样前 3 行）。
  - `export(Request)` → xlsx 或 zip（按 `mode`）。
  - `requireExportPerm()`：admin 或 `payroll_roles.permissions` 含 `perf_export` / `*`。
  - 私有填充：`fillDetailSheet` / `fillSummarySheet` / `fillNotesSheet` / `sheetTitle`。
- `tests/Feature/PerformanceExportTest.php`

### 3.2 修改文件

- `routes/api.php`：在 `/export/performance` 附近新增
  - `GET /api/export/performance-detail/preview`
  - `GET /api/export/performance-detail`
- `app/Services/AppModules.php`：`hr.perms` 追加 `['perf_export','绩效导出']`。

### 3.3 请求参数与校验

| 参数 | 规则 | 失败码 |
|---|---|---|
| ym | 必填 `YYYY-MM` | 400 |
| cats | 逗号分隔，子集 `staff,manager,case,hq`，至少 1 类 | 422 |
| project | 可选；项目账号强制为自身项目 | — |
| method | `follow|monthly|quarter|installment`，默认 follow | 422 |
| mode | `book|summary|split`，默认 book | 422 |

- 项目账号：须该项目本月已归档（`payroll_results.archived=true`），否则 403；只返回本项目基层/案场，管理人员/总部行不可见。
- 无数据：404。
- 非季度末月：
  - `method=quarter|installment` → 422 `季度/分期兑现仅支持季度末月（4/7/10/1 月）导出`；
  - `method=follow` 且勾选管理人员/总部人员 → 422 `跟随发放法下管理人员/总部人员需选择季度末月（4/7/10/1 月）`。
- 未登录：401；无 `perf_export` 权限：403。

### 3.4 发放法归属

- `follow`：按 `pay_rules` + 薪酬档位解析：
  - `monthly` → 月度；
  - `quarter_grade` + `mode=quarter` → 季度；`mode=monthly` → 月度；
  - `quarterly` → 分期兑现。
- 覆盖参数：`monthly/quarter/installment` 对全部人员强制该列结构。
- 分组：按「类别 + 发放法」；管理人员在 follow 下自然拆为「管理人员-季度 / 管理人员-月度 /（如有）管理人员-分期兑现」；同一类别出现多种发放法时加后缀；其他类别单一发放法时标题为类别名。
- 统一发放法为「口径视图」：
  - 月度：季度型人员用 `perf_base_month × (perf_att/perf_req) × coef` 折算；月度型人员取落库 `perf_pay`。
  - 季度：取 `perf_quarter`；无则回退旧 `perf_detail`；均无 → 该行季度列显示 `—` 并在备注说明，不伪造 0。
  - 分期兑现：仅旧/新数据中具备 `ratio` 的 quarterly 人员可算；其余显示 `—` 并备注「非分期兑现人员」。

### 3.5 列结构（严格按原型）

- 公共头 6 列：`序号 | 项目 | 部门 | 姓名 | 岗位 | 薪酬档位`
- 月度：`固定月薪 | 基本工资 | 月系数 | 应出勤天数 | 绩效出勤天数 | 出勤比例 | 标准绩效工资（月） | 应发绩效工资（月）`
- 季度：`固定月薪 | {7,8,9}月应发 | {7,8,9}月转正 | 季度合计应发 | 季度合计转正 | 出勤系数 | 季度系数 | 标准绩效工资 | 应发绩效工资`
- 分期兑现：`固定月薪 | 逐月应发/转正 | 季度合计应发 | 季度合计转正 | 出勤系数 | 季度系数 | 职级季度比例 | 季度计提基数 | 季度应发 | 半年度系数 | 职级半年度比例 | 半年度应发 | 本期应发`
- 公共尾 3 列：`入职日期 | 转正日期 | 备注`
- 金额 `#,##0.00`；系数/比例 `0.00`；天数 `0.##`；缺失值 `—`。
- 每张明细 Sheet：按项目分组，项目后插入「小计（项目）· N 人」行，末尾「总计 · N 人」行；小计/总计对金额列求和。
- 出勤系数 = `Σ转正出勤 / Σ应发天数`（2 位）；>1 时备注 `出勤系数 X.XX > 1.00（出勤天数大于应出勤）`。

### 3.6 汇总 Sheet

列：`人员类别 | 项目 | 发放法 | 人数 | 固定月薪合计 | 标准绩效工资 | 本期应发绩效工资 | 季度兑现 | 月度发放 | 半年度兑现 | 备注`

- 每个「类别+发放法」：先列各项目行，再「类别小计」行；末尾「总计」行。
- 人员类别在管理人员拆分时显示「管理人员（季度）/（月度）/（分期兑现）」。
- 备注列：`异常 N 人`、`出勤系数>1 N 人`（无则空）。

### 3.7 说明 Sheet

两列表：`项目 | 内容`。包含：
- 周期归属、月度系数、季度系数、出勤系数、0/1.0 口径、各发放法公式；
- 本次导出参数（月份、季度、项目范围、发放法、勾选类别、导出方式）；
- 数据提示清单（未归档/无数据/异常/出勤系数>1）；
- 出勤系数 >1 人员清单（类别/项目/姓名/系数）。

### 3.8 导出方式

- `book`：一个 xlsx = 各类别明细 Sheet + 汇总 + 说明。
- `summary`：一个 xlsx = 仅汇总。
- `split`：zip，每个「类别+发放法」一个 xlsx（文件名 = Sheet 标题 + ym）。

## 4. 前端实现

### 4.1 新增/修改

- 新增 `frontend/src/modules/hr/performanceExportLogic.js`
  - `PERF_CATS / PERF_METHODS / PERF_MODES` 常量；
  - `latestQuarterEndMonth(now)`：取当前月及之前最近的 1/4/7/10 月；
  - `toggleCat(list,key)`、`isQuarterEndMonth(ym)`、`needsQuarterEnd(method,cats)`；
  - `perfExportQuery(params)`、`perfExportTarget(params)`（URL + 下载文件名）、`methodHint(method)`。
- 新增 `frontend/src/modules/hr/performanceExportLogic.test.js`（Vitest）。
- 新增 `frontend/src/modules/hr/PerformanceExport.vue`
  - 左侧条件卡：核算月份（`type=month`）、人员类别多选（4 个复选）、项目范围、发放法、导出方式、导出按钮；
  - 右侧提示卡：预览接口返回的 warnings（危/警/信/成）；
  - 工作表卡片：标题、发放法、行数、列数、列名 chips；
  - 表样预览：各 Sheet 前 3 行（预览接口返回）；
  - 当前发放周期配置卡；
  - 导出点击调用 `download(url, filename)`；仅汇总/分类打包同样走同一接口。
  - 预览在挂载及参数变化时刷新；接口 422/403 错误展示在提示区。
- 修改 `frontend/src/router/index.js`：`/performanceExport` → `PerformanceExport.vue`，`meta.perm='perf_export'`。
- 修改 `frontend/src/nav/menus.js`：
  - `PAGE_TITLES.performanceExport = '绩效导出'`；
  - 薪资模块追加 `{ key:'performanceExport', label:'绩效导出', icon:'up', perm:'perf_export' }`；
  - `HR_PAGES` 加入 `performanceExport`。
- `frontend/src/modules/hr/Export.vue` 不改动。

## 5. 测试计划

### 5.1 PHPUnit（`tests/Feature/PerformanceExportTest.php`）

1. 跟随模式：管理人员拆「管理人员-季度」「管理人员-月度」，基层/案场/总部各一张；Sheet 标题正确。
2. 季度列与金额：`标准绩效工资=q.base`、`季度系数=q.coef`、`应发=q.amount`；逐月应发/转正列正确。
3. 汇总：两项目 → 项目行 + 类别小计 + 总计；金额与人数正确。
4. 系数 0 与 1.0 正常计算不标异常；缺系数（`perf_quarter.error=missing_coef`）在备注标注且不静默 0。
5. 出勤系数 1.11 → 备注列 + 预览 warnings 清单。
6. 统一月度：季度型行按 `perf_base_month×出勤比×coef` 折算，列结构无季度列。
7. 统一分期兑现：quarterly `perf_detail` 行输出季度比例/半年度比例/金额；非 quarterly 行 `—` 备注。
8. 导出方式：book 多 Sheet；summary 仅汇总；split 返回 zip 且含多文件。
9. 权限：无 token 401；非 admin 无 `perf_export` 403；授予后 200。
10. 项目账号：未归档 403；归档后仅本项目基层/案场，管理人员/总部不可见。
11. 非季度末月 + 季度/分期兑现 → 422。
12. 无数据 → 404；非法月份 → 400。

### 5.2 前端

- `performanceExportLogic.test.js`：默认月份、季度末判定、类别切换、URL/文件名拼装、需季度末判定、方法提示。
- `npm run test` 全量通过。
- `npm run build` 通过。

### 5.3 后端全量

- `php artisan test` 全量通过。

## 6. 交付与限制

- 完成后汇报：改动文件清单、测试命令与结果、页面入口 `/performanceExport`（菜单：薪资模块 → 绩效导出）、权限点、未部署说明。
- 不创建/修改数据库数据；不部署；旧「数据导出 → ③」不迁移、不下线。
- 临时脚本/样例不进入正式代码；`docs/samples/` 既有原型与样例保留。

## 7. 风险与取舍

- `perf_export` 为新增权限点：存量非管理员角色需在「系统设置 → 权限」勾选后可见；admin 无需配置。若希望沿用旧 `export` 权限，可在评审时改为复用（本规格默认新增独立权限点）。
- 统一季度/统一分期兑现对非对应发放法人员显示 `—` 并备注，避免把其他周期金额伪装成季度金额。
- 季度列按结算月前 3 个月动态生成（4→1~3 月、7→4~6 月、10→7~9 月、1→上年 10~12 月）。
