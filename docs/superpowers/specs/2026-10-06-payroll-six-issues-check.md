# 8月薪资核算六项问题 — 检查结果与解决方案（待审核，未修复）

- 日期：2026-10-06
- 检查方式：全量静态代码核查（后端 Laravel + 前端 Vue3 + 迁移 + 现有测试），未修改任何文件
- 当前版本：`c921d9c`
- 涉及人员口径：经核实系统实际是**四类**——员工（staff）/ 管理人员（manager）/ 案场人员（case）/ 总部人员（hq），四类共用同一套核算主体与微调接口，差异仅在人员过滤、考勤块来源、季度绩效（仅 manager/hq）

## 结论速览

| # | 用户反馈 | 核查结论 | 修复复杂度 |
|---|---|---|---|
| 1 | 取消所有四舍五入、精确到分位 | **部分属实但方向需修正**：真实问题是 round 口径不统一导致的勾稽尾差；"取消所有四舍五入"在数学/税法/财务上不可行（见下详述），需你决策后再定方案 | 中-大 |
| 2 | 滚轮误改微调数字框 | **成立**，20+ 个裸 `type=number`，全前端无 wheel 处理 | 小 |
| 3 | 多次微调前值丢失 | **成立，但范围比描述窄**：仅"手工个税"会被后续微调冲掉；其他字段是 JSON 合并不会丢 | 中 |
| 4 | 微调日志可视化 | **成立**：后端零实现（空表 `payroll_adjust_logs` 已存在、前端展示区已预埋但恒不渲染、reason 后端从未接收） | 中 |
| 5 | 锁定后仍可微调 | **部分成立**：保存接口有归档拦截（400），但前端不禁用按钮；**另发现更大漏洞：锁定后重新核算会用公式结果覆盖锁定行的全部微调** | 中 |
| 6 | 分项目导出加案场/管理选项 | **功能已存在**（单项目管理/案场导出接口+按钮均已上线，仅按钮分散在两个卡片、且管理按钮仅总部可见）；需要的是交互整合与权限确认 | 小 |

---

## 问题 1：计算精度 —— 必须先纠正的前提

### 1.1 检查结果（事实）

金额全部为 PHP float 运算，落库于 `payroll_results.row_data`（JSON，MySQL 内部 DOUBLE），**数据库无 decimal 金额列**，精度完全由 PHP 端控制。

现有 `round(..., 2)` 分布（[PayrollCalculator.php](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Services/PayrollCalculator.php)）：

- **落库前 round 的分项**：应发基本工资（L572）、绩效工资（L573）、病假工资（L574）、缺卡扣款（L698）、其他扣款（L699）、社保合计（L828）、专项附加（L859-864）、应发合计 gross（L777）、个税（L825/L1087）、实发 net（L833）
- **落库前不 round 的分项**：夜班/话费、餐补、职称补贴、奖励、福利、扣罚、迟到早退扣款、工装扣款、五险**分项**（pen/med/une/house/big，L855-856）——其中考勤录入项在考勤上传时已被 round 到 2 位（[AttendanceController.php:466](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Http/Controllers/Api/AttendanceController.php#L466)），但手填奖励/扣罚等在核算链路上是未 round float
- **季度绩效独有尾差源**（manager/hq）：周期累计基数用未 round 的逐月原始值（L1327-1331），而页面/导出逐月明细展示的是 round 后值，两者相加本身就可能差 1-2 分
- 汇总报表、驾驶舱的 round 属展示层；Excel 明细单元格不 round、合计行是 Excel `=SUM()` 公式（[ExportController.php:923](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Http/Controllers/Api/ExportController.php#L923)）

### 1.2 为什么"取消所有四舍五入"不可行（需要你知道的风险）

1. **"精确到分位"本身就要求舍入**。日工资 = 月薪 ÷ 应出勤天数、个税 = 应税额 × 税率 − 速算扣除数，都存在除不尽/乘不尽。结果要落到"分"，就必须有取舍规则：四舍五入、截断（抹零，员工系统性吃亏）、或保留更多小数（工资不能发厘）。"取消四舍五入"与"精确到分位"二者只能选一。
2. **个税是法定四舍五入到分**。累计预扣差额法（当月税 = 累计应扣 − 往月已扣）依赖每月落库到分的历史值；若中间不 round，跨月会持续积累浮点尾差，且与税局口径不一致。
3. **完全取消 round 会制造新的对账问题**：JSON 里出现多位长小数；Excel `=SUM()` 与页面合计、纸面分项加总三者互不相等；历史月份（已 round）与新月份（不 round）混算时累计预扣必现尾差。
4. 现有对拍测试（PayrollParityTest、PayrollTaxTest、PayrollQuarterlyTest 等，约 40+ 用例）锁死的就是 round 到分的口径，全部需要重写期望值。

### 1.3 真正的缺陷与三个可选方案

**真正缺陷 = 舍入口径不统一**（部分分项 round、部分不 round），可能导致"分项手加 ≠ 合计"1 分差异；以及季度绩效基数与展示值口径不一致。

| 方案 | 内容 | 优点 | 代价/风险 |
|---|---|---|---|
| **A（推荐）** | **保留"到分四舍五入"，但统一全口径**：① 所有分项落库前一律 round(2)（把 meal/reward/punish/五险分项等补齐）；② gross/net 只从已 round 的分项求值后再 round，保证"分项加总=合计"；③ 季度绩效逐月基数与展示统一到同一 round 值；④ 微调重算同口径 | 符合税法与财务惯例；改动集中；勾稽严格；历史数据无需迁移（口径仍是到分） | 极少数人员重算后某分项可能有 1 分变化（从不 round 变为 round） |
| B | 内部全高精度（引入 bcmath，4-6 位小数贯穿计算），仅在落库/展示边界统一到分，合计采用"倒挤法" | 理论最严谨 | 改动面最大（核算+微调+导入全链）、需全量重算历史、测试全改、周期长，收益主要在分摊场景（本系统按月直发，分摊极少） |
| C | 按字面取消全部 round | — | **不建议**：见 1.2 四条风险，工资表会出现长小数且跨月个税尾差 |

**需要你提供的关键信息（决定方案 A 是否对症）**：8 月你看到的具体现象——哪个页面、哪个人、哪个字段、差了多少？最好给一个"分项相加应为 X、系统显示 Y"的实例。如果你看到的是"合计差 1 分"，方案 A 正好对症；如果你看到的是别的（比如个税差 1 分、季度绩效累计不对），修法会不同。

### 1.4 四类人员影响

员工/案场：月度折算路径，受 1.3①② 影响；管理/总部：另有季度绩效尾差源 1.3③。个税预扣四类同口径，同步受益。

### 1.5 测试方法（方案 A）

新增 PHPUnit：构造 21.75 天基数、除不尽日工资、季度累计等场景，断言"各分项之和 = gross、gross−社保−个税−福利 = net（严格相等，非 0.02 容差）"；现有 PayrollAdjustParityTest 的 `assertEqualsWithDelta(0.02)` 收紧为精确到分断言。

---

## 问题 2：微调数字框禁用滚轮（成立，简单）

- **根因**：[Payroll.vue:232-248](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/Payroll.vue#L232) 微调弹窗内 20+ 个输入框均为裸 `<input type="number" step="0.01" v-model="...">`；浏览器对聚焦的 number 框默认响应滚轮步进，全前端 grep `wheel` 零命中。
- **复现**：微调弹窗 → 点进任一金额框 → 滚动鼠标滚轮 → 数值被步进改变 → 差异 >0.001 即被提交（[payrollLogic.js:59](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/payrollLogic.js#L59)），无确认。
- **方案**：新增一个小组件或统一指令 `v-disable-wheel`（`@wheel.prevent` 并在非聚焦时 blur，原生做法 `el.addEventListener('wheel', e => e.target.blur(), {passive:true})` 只在聚焦时触发），套用到弹窗内全部 number 框。
- **影响面**：四类人员共用同一弹窗，一次修复全覆盖。
- **测试**：Vitest 断言指令/组件存在并对 wheel 事件调用 prevent/blur（纯函数可测；真实滚轮交互靠人工抽验）。

---

## 问题 3：多次微调数据保存冲突（成立，根因精确到"手工个税"）

- **根因**：微调保存是"读旧行 → 合并本次字段 → **无条件调 recomputeDerived() 全量重算派生值 → 写回"**（[PayrollWriteController.php:193-245](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Http/Controllers/Api/PayrollWriteController.php#L193)）。
  - 普通补贴/扣款字段：JSON 合并，第二次提交不同字段**不会丢**。
  - 个税特殊：`recomputeDerived()` 每次都按公式重算当月个税并强行写回（[PayrollCalculator.php:962-981](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Services/PayrollCalculator.php#L962)）；只有**本次请求恰好带了 actual_tax** 时才在 L229-241 盖回手工值。
  - 所以：第 1 次手工个税 18 → 保存成功；第 2 次改缺卡 30（不带个税）→ recomputeDerived 用公式算出新个税覆盖 18。全库无 `tax_manual/tax_override` 之类的手工锁定标记。
- **复现**：对同一人①微调个税为 18 保存 → ②微调缺卡扣款 30 保存 → 个税恢复成公式值。
- **方案**：在 row_data 增加手工锁定标记 `actual_tax_manual = true`（随手工设税写入）；`recomputeDerived()` 识别该标记：若为 true，跳过自动个税、保留手工 actual_tax/withhold，net 用现有 net 公式以手工税重算；用户再次手工改税时覆盖；另提供"恢复系统计算个税"入口清除标记。重新核算（公式重算）时清除该标记（以公式为准，符合重算语义）。
- **影响面**：四类人员同一接口，同时修复。
- **测试**：新增 HTTP 级用例"手工改个税→再改其他扣款→个税仍为手工值且 net 正确"；"清除标记后恢复公式值"；补进 PayrollAdjustParityTest。

---

## 问题 4：微调日志可视化（成立，底座已备好）

- **现状**：
  - 表 `payroll_adjust_logs` 已存在（[迁移 L25-38](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/database/migrations/2026_09_29_000002_create_missing_prod_tables.php#L25)，字段：staff_legacy_id/staff_name/project_name/ym/changes/row_after/operator/operator_role/created_at），全应用代码零读写；**无 reason 列**。
  - 前端员工表下方"微调日志（本月）"区已写好（[Payroll.vue:106-120](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/Payroll.vue#L106)，取 empMeta.logs），但后端从不返回 logs，恒不渲染；管理/案场/总部三个 tab 无日志区；微调弹窗内无该人历史区。
  - 前端"修改原因"必填并随 payload 发出（[Payroll.vue:637-642](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/Payroll.vue#L637)），后端从未接收。
- **方案**：
  1. 迁移：`payroll_adjust_logs` 增加 `reason` varchar 列（并在生产库补列）。
  2. 后端：adjust 保存成功后写一条日志（含每字段**原值→新值**、原因、操作人/角色）；`/api/payroll` 列表响应附带本月 logs（按 ym 查，权限沿用列表口径）；新增 `GET /api/payroll/adjust-logs?ym=&staff_id=` 供弹窗查单人历史。
  3. 前端：员工 + 管理/案场/总部四个表格下方统一展示本月全员日志；微调弹窗内增加"该人员历史微调"折叠区（时间/字段/原值→新值/原因/操作人）。
  4. 注意 row_after 体积：日志只存 changes + 关键字段快照，不存整行（避免表膨胀）。
- **影响面**：四类人员同一接口/同一弹窗，统一生效。
- **测试**：HTTP 级断言微调后落日志（含 reason/原值/新值/操作人）；列表接口返回 logs；Vitest 断言日志区与弹窗历史区渲染。

---

## 问题 5：锁定状态控制（部分成立，连带发现更严重的"重算抹锁定"）

- **现状**：工资锁定 = `payroll_results.archived`（没有单独 locked 字段）。
  - ✅ 微调保存接口有行级拦截：已归档返回 400（[PayrollWriteController.php:192](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Http/Controllers/Api/PayrollWriteController.php#L192)，有测试 PayrollAdjustParityTest:219）。
  - ❌ 前端微调按钮在归档后、项目账号下均不隐藏/不置灰（[Payroll.vue:81](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/Payroll.vue#L81)、:189），弹窗照开、原因照填，保存才报错——体感即"锁了还能微调"。
  - ❗ **更大漏洞：锁定后点"重新核算"，锁定行会被删除并用公式结果重插，只恢复 archived 标记，行内全部微调丢失**（[PayrollCalculator.php:340-377](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Services/PayrollCalculator.php#L340)：归档行先快照→删除→新数据插 archived=false→仅把标记改回 true，row_data 已是公式值）。这与界面"归档锁定后禁止重算"的文案承诺矛盾。
- **需要你澄清的复现场景**：你遇到的"锁定后仍可微调"，是（a）锁了还能打开弹窗（保存其实被拒），还是（b）保存真的成功了（若是，需确认锁的是哪一层：员工/管理/案场/总部四层锁定相互独立，只锁员工层时，案场层仍可微调是当前设计），还是（c）锁定后重新核算导致微调被冲掉？
- **方案**：
  1. 前端：四个表格的微调按钮按该层 `allArchived` 状态 + 角色禁用并给 title 提示（与考勤按钮置灰口径一致）；项目账号不显示。
  2. 后端重新核算：当目标范围内存在 archived 行时默认拒绝（409，返回名单），只有显式 `force=true` 且二次确认才放行（对齐现有归档接口的 409+force 模式）；四个 calc 入口（员工/管理/案场/总部）统一。
  3. 顺带修正：员工页锁定徽标聚合 `allArchived()` 未排除案场行（[PayrollController.php:168-174](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/app/Http/Controllers/Api/PayrollController.php#L168)），与列表过滤口径对齐（静态推断，修复时先写测试复现）。
- **测试**：新增"有归档行时 calc 返回 409、force 才放行"用例；前端按钮置灰用例（参照 attendanceGroups.test.js）。

---

## 问题 6：分项目导出案场/管理人员（功能已存在，建议做交互整合）

- **与描述不符的事实**：
  - 后端单项目接口已上线：`/api/export/project-managers`（仅总部可调）、`/api/export/project-case`（总部可选任意项目，项目账号限本项目）。
  - 前端按钮也已存在：「导出该项目管理人员表」（[Export.vue:16](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/hr/Export.vue#L16)，**仅管理员可见**）、「导出该项目案场人员表」（:24，在下方独立卡片）。
- 你看不到/不顺手的可能原因：两个按钮分散在不同卡片；管理按钮项目账号不可见（v-if="isAdmin"）。
- **方案（请确认选哪个）**：
  - **方案 6-A（推荐）**：把"分项目导出"整合成与考勤导出一致的交互——一个项目下拉 + 一个人员类型选择（基层员工/管理人员/案场人员/全部），一个导出按钮，复用现有三个后端接口。
  - 方案 6-B：仅保留现状按钮，把两个入口挪到同一行、加提示文案，零后端改动。
  - **权限决策点**：项目账号是否允许导出"本项目管理人员"工资？现状是不允许（管理人员由总部核算，仅总部导出）。若要放开需你明确授权。
- **测试**：现有接口补 case 类型的导出用例（AttendanceExportStaffTypeTest 只覆盖了 manager 与非法值，工资导出无类型筛选测试——整合后按实际接口形态补）。

---

## 四类人员一致性核查汇总

| 问题 | 员工 staff | 案场 case | 管理 manager | 总部 hq |
|---|---|---|---|---|
| 2 滚轮 | 同一弹窗，全覆盖 | 同 | 同 | 同 |
| 3 个税覆盖 | 同一接口，全覆盖 | 同 | 同 | 同 |
| 4 日志 | 员工区已有预埋 | **缺日志区** | **缺日志区** | **缺日志区** |
| 5 锁后微调/重算 | calc 入口需加拦截 | 同 | 同 | 同；季度绩效另涉问题 1 |
| 1 精度 | 月度路径 | 月度路径 | 季度绩效尾差源额外修 | 同管理 |

## 待你确认的决策点（审核时请逐条回复）

1. **问题 1**：选 A / B / C？并提供 8 月尾差的具体实例（页面+人员+字段+差额）。在你给实例前，问题 1 不动代码。
2. **问题 3**：是否同意 `actual_tax_manual` 标记方案，并在弹窗加"恢复系统计算个税"按钮？
3. **问题 5**：你遇到的是上文 (a)/(b)/(c) 哪种？锁定后重新核算是否按"409 拦截 + force 二次确认"处理？
4. **问题 6**：选 6-A 还是 6-B？项目账号是否放开本项目管理人员工资导出？
5. 修复后历史月份（含 8 月）是否需要统一重算一遍以应用新精度/口径？（8 月若已发放，建议只修代码不重算已发月份，次月生效；重算会影响已发工资表）

## 审核结论（2026-10-06 用户确认）

1. 问题 1：**采纳方案 A**（保留到分四舍五入、统一全口径、保证勾稽），未提供具体实例，按统一口径治理实施。
2. 问题 2：按方案实施（滚轮禁用）。
3. 问题 3：**用户决定不修复**（手工个税锁定标记不做；后续微调冲掉手工个税的现状保留，操作时需注意：手工个税尽量在最后一次微调中设置）。
4. 问题 4：按方案实施（日志落库 + 四表展示 + 弹窗单人历史 + reason 接收）。
5. 问题 5：用户实际场景=(a) 弹窗可打开、未测试保存（保存端本来就 400）。实施：前端按锁定状态/角色置灰微调按钮；重新核算对锁定行 409 拦截 + force 二次确认；顺带修正 allArchived 口径。
6. 问题 6：采纳 **6-A**（项目下拉+人员类型下拉整合），权限维持现状（管理人员工资仅总部可导出，项目账号不放开）。
7. 历史已发月份**不重算**，修复次月核算生效。
8. 实施批次：第一批=问题 2+6；第二批=问题 4+5；第三批=问题 1。每批 TDD + 全量回归 + 提交，统一经用户同意后部署。

## 建议执行顺序（确认后）

1. 第一批（低风险独立项）：问题 2（滚轮）、问题 6（导出整合）
2. 第二批（微调链路，一起做才完整）：问题 3（个税标记）+ 问题 4（日志落库与展示）+ 问题 5（前端置灰 + calc 拦截）
3. 第三批（视决策）：问题 1（精度统一），单独 TDD + 全量回归
4. 每批：先写失败测试 → 实现 → 全量 phpunit/vitest/build → 提交 → 经你同意后部署

## 明确不在本次范围

- 个税税制、社保比例、绩效周期规则等业务口径不变
- 考勤模块、绩效模块、财务/采购模块不动
- 已归档发放的历史工资默认不重算（除非你在决策点 5 明确要求）
