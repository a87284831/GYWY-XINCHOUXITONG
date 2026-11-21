# 前端统一（单一 Vue3 SPA）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 用一个 Vue3 SPA（`laravel-app/frontend/` → 产物 `public/app/`）复刻并取代现有 4 个独立前端（主 SPA 30 页 + 采购 12 逻辑页重建 + 财务 5 页），后端 API 零改动，一步到位切换，旧版原地保留作回滚锚点。

**Architecture:** Vite + Vue3 + Pinia + Vue Router 平铺路由（path 复用旧页面 key）；统一壳承载导航与登录；API client 对齐现 `app.js` 的 `api()` 行为；采购按后端 handler 契约重建；部署=静态产物上传 + Nginx 切换。

**Tech Stack:** Vite 5 / Vue 3 / Pinia / Vue Router 4 / Element Plus / ECharts / Vitest（仅 api client 纯逻辑）

**Spec:** `docs/superpowers/specs/2026-09-26-frontend-unification-design.md`

## Global Constraints

- **任何服务器（旧机（已退役））操作必须先经用户逐项确认；本地开发/构建/提交不需要确认**
- 后端 API 契约零改动：不改 `routes/api.php`、不改任何 `app/` 下的 PHP（唯一例外：既定性能计划的 `dashboard.php` 缓存补丁，属部署阶段）
- **UI 复刻现有版本**：布局、交互、文案、金额/日期格式均以线上页面为基准，不自创设计
- 采购 SPA 打包产物（`public/purchase/assets/*.js`）只读，不修改；重建版写在新工程内
- 旧版四应用（`public/index.html`、`static/`、`purchase/`、`finance/`、`payslip.html`）**部署后也不删除**（回滚锚点）
- 新工程源码入 git；构建产物 `public/app/` 入 git（服务器无需 Node）
- git 提交信息用中文；本机为 PowerShell 5，命令链接用 `;` 不用 `&&`
- 每个页面任务完成定义 = `npm run build` 通过 + dev server 对照走查核对点通过 + 提交
- dev server 走查时代理到线上 API：**只做只读验证**（列表、图表、详情）；写操作（新增/编辑/删除/提交/审批）留到 Task 18 staging 验证

## Review Focus

1. **401 会话失效** → 任意页面 API 返回 401 必须跳登录页（复刻 `app.js:31` 的 `showLogin()` 行为），不允许白屏或静默失败。验证：Task 3 单测 + Task 18 清除 token 后走查。
2. **权限可见性** → 无权限的模块菜单不渲染、直接输 URL 进入被拦截（复刻 `appEnabled`/`perm` 行为，`app.js:465`、`app.js:172` 菜单 perm 字段）。验证：Task 4 单测/走查 + Task 18 只读账号登录复核。
3. **导出下载流** → blob 下载必须保留"正在生成导出文件"进度提示与失败提示（复刻 `app.js:39-60` `download()`）。验证：Task 3 单测 + 各模块走查点含导出。
4. **统一 token 对采购 API 的鉴权** → 采购后端接受主系统 `gw_token`（`__dashAllShim` 已证明 X-Token 兼容），但 auth.php 可能存在独立账号行为差异。验证：Task 12 契约侦察记录 + Task 13 驾驶舱带 token 请求 200。
5. **驾驶舱数据一致性** → `/dashboard/all` 聚合渲染的 12 组图表数字与线上采购驾驶舱一致。验证：Task 13 逐组对照。

---

### Task 1: git 基线提交（回滚锚点）

**Files:** 无新文件；提交当前工作区全部既有改动（此前会话的清理与修复，尚未提交）

**Interfaces:** Produces: 干净基线 commit，后续任务 diff 只含本次改动

- [ ] **Step 1: 确认工作区状态**

Run: `git -C c:\Users\87284\Documents\trae_projects\1\laravel-app status --short`
预期：约 36 个删除 + 若干修改，无敏感文件（.env 不入）

- [ ] **Step 2: 全量提交为基线**

```powershell
git add -A; git commit -m "基线: 与线上部署状态对齐(含文件清理与既有修复), 前端统一工程起点"
```

- [ ] **Step 3: 记录基线 hash 告知用户**

Run: `git log --oneline -1`

### Task 2: Vue3 工程骨架（frontend/）

**Files:**
- Create: `frontend/package.json`, `frontend/vite.config.js`, `frontend/index.html`
- Create: `frontend/src/main.js`, `frontend/src/App.vue`, `frontend/src/router/index.js`（先含 /login、/ 两条路由与占位组件）

**Interfaces:** Produces:
- `npm run dev`（dev server，代理 /api）与 `npm run build`（输出 `../public/app/`）两条命令
- Vite 别名 `@` → `src/`
- 路由实例导出 `router`（后续任务 `router.addRoute` 不需要——路由表集中在本文件维护，但新增路由条目的格式由此任务定型：`{ path: '/payroll', name: 'payroll', component: () => import('@/modules/hr/Payroll.vue'), meta: { perm: '' } }`）

- [ ] **Step 1: 脚手架**

手写（不用 create-vue 交互式）：package.json 依赖 `vue@^3`、`vue-router@^4`、`pinia@^2`、`element-plus@^2`、`echarts@^5`；devDeps `vite@^5`、`@vitejs/plugin-vue`、`vitest`。

- [ ] **Step 2: vite.config.js 关键配置（精确值）**

```js
export default defineConfig({
  base: '/app/',
  resolve: { alias: { '@': '/src' } },
  server: { proxy: { '/api': { target: '旧域名（已退役）', changeOrigin: true, secure: true } } },
  build: { outDir: '../public/app', emptyOutDir: true }
})
```

- [ ] **Step 3: 最小可跑**

`index.html` + `App.vue` + `main.js`（createPinia、ElementPlus 全量注册、router）+ 登录页占位 + 首页占位。

- [ ] **Step 4: 安装并验证**

Run: `cd frontend; npm install; npm run build`
Expected: 构建成功，`public/app/index.html` 生成
Run: `npm run dev`，浏览器开 `http://localhost:5173/app/`，两个占位页可切换

- [ ] **Step 5: Commit**

```powershell
git add frontend package-lock.json public/app; git commit -m "前端统一: Vue3 工程骨架(vite+pinia+router+element-plus), dev 代理与产物输出配置"
```

（若 `public/app` 被 .gitignore 命中，调整 .gitignore 显式放行该目录）

### Task 3: API client + auth store（含 Vitest）

**Files:**
- Create: `frontend/src/api/client.js`, `frontend/src/stores/auth.js`
- Test: `frontend/src/api/client.test.js`, `frontend/src/stores/auth.test.js`

**Interfaces:** Produces（后续所有页面任务依赖）:
- `api(path, opts?) => Promise<any>` — 签名与 `app.js:22-37` `api()` 一致：`opts.form`(FormData)、`opts.body`(对象→JSON)、`opts.method`；自动带 `X-Token` 头；`Content-Type: application/json` 才 JSON；响应 `application/json` 且 `!data.ok` → throw `data.error`；401（非 /api/login）→ 跳转 `/login`；非 JSON 且非 2xx → throw "下载失败"，否则返回 blob
- `download(path, fallbackName) => Promise<void>` — 复刻 `app.js:39-60`：进度提示浮层 → blob → `<a download>` 触发 → 成功/失败提示
- `useAuthStore()`（Pinia）：`state: { user, token }`；`login(username, password) => Promise`（POST `/api/login`，成功写 `this.token` 且 `sessionStorage.setItem('gw_token', token)`）；`logout()`（清 store + `sessionStorage.removeItem('gw_token')` + 跳 `/login`）；`restore()`（从 `sessionStorage.gw_token` 恢复）；getter `can(perm)`：perm 空串=所有人可见，否则按 `state.user.perm` 判断（实现前先 grep `app.js` 中 `perm` 的既有判定逻辑并对齐）

- [ ] **Step 1: 写失败测试（Vitest）**

`client.test.js`：mock fetch —— ① 请求带 X-Token 头且取自 auth store；② `data.ok===false` 时 throw error 文案；③ 401 时触发跳转 `/login`；④ 非 JSON 2xx 返回 blob；⑤ `download()` 失败时显示"导出失败"提示、成功时触发 `<a download>` 点击（Review Focus #3）。
`auth.test.js`：① login 成功后 token 写入 sessionStorage.gw_token；② logout 清除；③ restore 从 gw_token 恢复。

- [ ] **Step 2: 运行确认失败**

Run: `cd frontend; npx vitest run`
Expected: FAIL（模块不存在）

- [ ] **Step 3: 实现 client.js 与 auth.js**

`api()` 逐行对照 `app.js:22-37` 行为；`download()` 对照 `app.js:39-60`（提示浮层 DOM、颜色值、超时清理照搬）。

- [ ] **Step 4: 运行确认通过**

Run: `npx vitest run`
Expected: PASS（全部用例）

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: api client(X-Token/401/blob)与 auth store(登录态/gw_token 兼容), 含单测"
```

### Task 4: 统一壳 + 登录页 + 首页

**Files:**
- Create: `frontend/src/layouts/MainLayout.vue`, `frontend/src/modules/home/Home.vue`, `frontend/src/views/Login.vue`
- Modify: `frontend/src/router/index.js`（加 `/login`、`/`，壳包裹其余路由的嵌套骨架；全局前置守卫：无 token → /login，`can(perm)` 不过 → 首页）

**Interfaces:** Consumes: Task 3 的 `useAuthStore`/`api`。Produces: `MainLayout.vue`（侧边菜单 = 复刻 `app.js` APPS 数组与 `HOME_META`(`app.js:434-442`) 的分组结构；菜单项 `meta.perm` 驱动可见性）；路由守卫逻辑。

- [ ] **Step 1: 侦察旧导航与首页**

Grep `app.js`：`APPS` 数组定义、`appEnabled`、`pageHome`(`app.js:457` 起)、`homeTodoCard`、`MSG_ICON`(`app.js:581`)、首页调用的端点（`/api/approval/list?scope=approve`、`/api/approval/onboard/list`、消息列表端点）。把菜单分组、文案、图标、端点记入核对清单。

- [ ] **Step 2: 实现登录页**

复刻旧登录界面（对照线上 `showLogin` 表单）：用户名/密码 → `authStore.login` → 跳 `/`。

- [ ] **Step 3: 实现 MainLayout**

侧边分组菜单（按 APPS 分组 + `can(perm)` 过滤）、顶栏（用户名/角色徽标——复刻 `app.js:460-462` 角色文案规则 admin/项目/只读）、登出按钮调 `logout()`。

- [ ] **Step 4: 实现首页**

复刻 `pageHome`：问候语（`homeGreeting`/`homeToday` 规则照搬）、模块入口卡（APPS × HOME_META，建设中/未授权 tag 规则照搬 `app.js:463-471`）、待办卡片（旧端点与排序照搬）。

- [ ] **Step 5: 构建与走查**

Run: `npm run build` → 成功
dev server 走查：登录 → 首页卡片/待办与线上 `旧域名（已退役）` 同账号对照（菜单分组数、卡片数、待办数字一致）

- [ ] **Step 6: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 统一壳/登录页/首页复刻(菜单分组+权限过滤+待办卡片)"
```

### Task 5: hr 页组一（summary / payroll / taxMode / attendance）

**Files:**
- Create: `frontend/src/modules/hr/Summary.vue`, `Payroll.vue`, `TaxMode.vue`, `Attendance.vue`
- Modify: `frontend/src/router/index.js`（4 条路由，path 用旧 key：`/summary` `/payroll` `/taxMode` `/attendance`）

**Interfaces:** Consumes: Task 3 `api`/`download`、Task 4 壳与守卫。

- [ ] **Step 1: 侦察旧实现**

Grep `app.js` 定位 `pageSummary`、`pagePayroll`、`pageTaxMode`、`pageAttendance` 四函数；逐一提取：调用的 API 端点与参数、表格列、筛选器（月份控件复用 `monthInput` 语义）、按钮与导出、权限差异。记入核对清单（本任务的临时产物，走查后随提交删除或保留在任务描述）。

- [ ] **Step 2: 逐页实现 Vue 组件**

每页一个组件，Element Plus 表格/表单复刻布局；导出按钮统一走 `download()`；数字格式复用旧 `money`/`pct` 规则（千分位 2 位小数、百分比 2 位）。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: dev server 对照走查（只读）**

同账号对照线上 4 页：表格列头一致、首屏数据一致、筛选联动一致、导出点击出现进度提示且文件下载。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: hr 页组一(summary/payroll/taxMode/attendance)复刻"
```

### Task 6: hr 页组二（org / staff / adjust / budget / export / projects）

**Files:**
- Create: `frontend/src/modules/hr/Org.vue`, `Staff.vue`, `Adjust.vue`, `Budget.vue`, `Export.vue`, `Projects.vue`
- Modify: `frontend/src/router/index.js`（6 条路由）

**Interfaces:** Consumes: 同 Task 5。

- [ ] **Step 1: 侦察旧实现**

同 Task 5 Step 1 方法，定位 `pageOrg`、`pageStaff`、`pageAdjust`、`pageBudget`、`pageExport`、`pageProjects`。注意 staff 页含增删改与文件上传（FormData），export 页含多类导出。

- [ ] **Step 2: 逐页实现**

CRUD 交互（弹窗表单、删除确认）复刻旧交互形态；上传用 `api(path, { form: fd, method: 'POST' })`。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读部分）**

6 页列表/筛选/详情对照线上一致；导出可用。写操作（新增/编辑/删除按钮点击后的请求体）用浏览器 Network 面板与旧版对照至**发出请求前一步**（不提交）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: hr 页组二(org/staff/adjust/budget/export/projects)复刻"
```

### Task 7: 绩效页组（perfCreate / perfApprove / perfReport / perfMine / perfRecords）

**Files:**
- Create: `frontend/src/modules/hr/PerfEditor.vue`, `PerfList.vue`（一组件两态：`props.mode` = approve/report/mine/all）
- Modify: `frontend/src/router/index.js`（5 条路由；`/perfCreate` → PerfEditor，其余 4 条 → PerfList 传 mode）

**Interfaces:** Consumes: 同 Task 5。

- [ ] **Step 1: 侦察旧实现**

Grep 定位 `pagePerfEditor`（旧签名 `pagePerfEditor(0)`）与 `pagePerfList(mode)`；提取端点、列表状态流转、审批操作按钮。

- [ ] **Step 2: 实现两组件**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

5 个入口渲染内容与线上对应视图一致（同一列表数据、状态标签、筛选）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 绩效页组(编辑器+四视图列表)复刻"
```

### Task 8: 报表中心（reportHome / hrReport / salaryReport / attReport）

**Files:**
- Create: `frontend/src/modules/report/ReportHome.vue`, `HrReport.vue`, `SalaryReport.vue`, `AttReport.vue`
- Modify: `frontend/src/router/index.js`（4 条路由）

**Interfaces:** Consumes: 同 Task 5；图表用 ECharts（按需引入组件，勿全量打包）。

- [ ] **Step 1: 侦察旧实现**

Grep 定位 `pageReportHome`、`pageHrReport`、`pageSalaryReport`、`pageAttReport`；提取端点、图表类型与数据键、导出。

- [ ] **Step 2: 逐页实现**

图表复刻类型/维度/配色语义；导出走 `download()`。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

4 页图表与线上同月份数据一致（形状、极值、合计）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 报表中心 4 页复刻(ECharts 按需引入)"
```

### Task 9: 维保页组（fireReport / elevReport / maintFire / maintElev / maintPartners）

**Files:**
- Create: `frontend/src/modules/maint/FireReport.vue`, `ElevReport.vue`, `MaintLedger.vue`（`props.type: 'fire'|'elevator'`）, `MaintPartners.vue`
- Modify: `frontend/src/router/index.js`（5 条路由；maintFire/maintElev → MaintLedger 传 type）

**Interfaces:** Consumes: 同 Task 5。

- [ ] **Step 1: 侦察旧实现**

Grep 定位 `pageMaintFireReport`、`pageMaintElevReport`、`pageMaintLedger(type)`、`pageMaintPartners`。

- [ ] **Step 2: 逐页实现**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

5 页对照线上一致。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 维保页组(两报表+双类型台账+签约方)复刻"
```

### Task 10: OA 审批中心 + 系统设置页组

**Files:**
- Create: `frontend/src/modules/oa/ApprovalCenter.vue`
- Create: `frontend/src/modules/settings/Settings.vue`（tab 组件化：perm/staffField/company/security/backup/logs/approvalFlow 七 tab）
- Create: `frontend/src/modules/settings/SalarySettings.vue`
- Modify: `frontend/src/router/index.js`（`/approvalCenter` `/settings` `/salarySettings` `/users`(→Settings perm) `/logs`(→Settings logs) `/backup`(→Settings backup)）

**Interfaces:** Consumes: 同 Task 5。

- [ ] **Step 1: 侦察旧实现**

Grep 定位 `pageApprovalCenter`(`app.js:5815` 起一段：drafts/inbox/detail/send/flows_public/upload 全家桶端点)、`pageSettings(tab)`(`app.js:3818` 分发)、`pageSalarySettings`。审批的写操作端点清单要完整（draft_submit/draft_delete/send/inbox_delete/upload 等）。

- [ ] **Step 2: 实现三块**

审批中心含：草稿列表、收件箱、审批详情操作、发送目标选择（`app.js:5884` send_targets）、流程设置视图（`app.js:5975` flows_public，流程 key：hire/regular/resign）。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

审批草稿/收件箱/流程设置与线上一致；设置各 tab 表单项与线上一致（不保存）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: OA 审批中心与系统设置页组复刻"
```

### Task 11: 财务 5 页重写

**Files:**
- Create: `frontend/src/modules/finance/Dashboard.vue`, `Ledger.vue`, `Payments.vue`, `Summary.vue`, `Import.vue`
- Modify: `frontend/src/router/index.js`（5 条路由）

**Interfaces:** Consumes: 同 Task 5。**API 端点已勘察**（`public/finance/index.html`，BASE=`/api/finance`，X-Token 头）：`meta`、`chart/dashboard?year&project_id`、`ledger?project_id&year`、`ledger/save`(POST)、`ledger/attachments`、`ledger/attachment_upload`(FormData)、`ledger/attachment_delete`(POST)、`payments`、`payments/save`(POST)、`summary/annual`、`summary/projects`、`summary/payments`、`import/parse`(FormData)、`import/run`(POST)。

- [ ] **Step 1: 通读旧财务页**

Read `public/finance/index.html` 全文（约 800 行），提取每页的渲染结构、字段、校验、echarts 配置，记入核对清单。

- [ ] **Step 2: 逐页实现**

驾驶舱复刻 `chart/dashboard` 的图表组；台账含附件上传/删除；导入页复刻 parse→预览→run 两步流。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

5 页对照 `旧域名（已退役）/finance/index.html` 同年份数据一致（图表、台账行、付款记录、汇总数字）。特别注意旧版 echarts 引用 bug（stylesheet 引 JS）——新版行为以"echarts 正常渲染图表"为基准。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 财务 5 页重写(14 端点全量接入, echarts 正常渲染)"
```

### Task 12: 采购 API 契约清单（重建基准）

**Files:**
- Create: `frontend/docs/purchase-api-contract.md`（本次侦察产物，作为 Task 13-17 的契约基准，长期保留）

**Interfaces:** Consumes: `app/Purchase/handlers/{auth,dashboard,fill,products,summary,budget,admin,system,export_import,log}.php`（10 文件）与 `routes/api.php:190`（`Route::any('/purchase/{path?}', PurchaseController@index)`）。Produces: 端点表（端点、方法、入参、出参键、鉴权行为、权限差异）。

- [ ] **Step 1: 通读 PurchaseController 与 10 个 handler**

记录每个 handler 分发的 action → 入参 → 返回结构；重点核实 `auth.php`：① 是否接受主系统 token（X-Token / gw_token 语义）；② 独立登录端点及其角色体系；③ 与采购前端各 hash 路由（`app.js:405-416`：#/dashboard #/fill #/overview #/customs #/budget #/summary #/export-import #/products #/windows #/oplogs #/my-items）的对应关系。

- [ ] **Step 2: 写契约文档**

按 11 个前端路由分节，每节列端点+参数+返回键+权限；auth 节明确统一 token 的接入方式（若存在差异，在此文档给出处理决策）。

- [ ] **Step 3: 核对线上行为（只读）**

dev server 或 curl 带有效 token 逐端点抽样请求，确认契约文档与实际响应一致。

- [ ] **Step 4: Commit**

```powershell
git add frontend/docs; git commit -m "前端统一: 采购 API 契约清单(10 handler 全量勘察, 统一 token 接入确认)"
```

### Task 13: 采购驾驶舱（#/dashboard）

**Files:**
- Create: `frontend/src/modules/purchase/Dashboard.vue`
- Modify: `frontend/src/router/index.js`（`/purchase/dashboard`）

**Interfaces:** Consumes: Task 12 契约；`/api/purchase/dashboard/all?month=`（聚合 12 组：monthly/compare/yoy/ytd/annual/annual_lines/annual_projects/top/fill_progress/budget_exec/custom_ratio/price_anomalies，键名见 `public/purchase/index.html` 的 `__dashAllShim` MERGED_KEYS）。Produces: `ECharts 封装组件 frontend/src/components/ChartBox.vue`（props: option；负责实例生命周期与 resize——后续有图页面复用）。

- [ ] **Step 1: 侦察旧驾驶舱渲染**

`Dashboard-z4nKb73E.js` 是压缩产物，不可读——以**线上页面为基准**：dev server 打开旧 `旧域名（已退役）/purchase/` 驾驶舱，逐组记录 12 个图表/卡片的类型、维度、交互（月份切换、项目筛选）。

- [ ] **Step 2: 实现 ChartBox 组件 + Dashboard 页**

一次请求 `/dashboard/all` 渲染全部 12 组（不再 12 次子请求）。

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 数据一致性对照**

同月份与线上驾驶舱逐组对照数字/形状一致（Review Focus #5）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 采购驾驶舱重建(/dashboard/all 单请求渲染 12 组, ChartBox 封装)"
```

### Task 14: 采购填报（#/fill，含 FillMonths/FillForm 两步）

**Files:**
- Create: `frontend/src/modules/purchase/Fill.vue`（月份选择 + 表单两步，或拆 `FillMonths.vue`+`FillForm.vue`，以旧交互为准）
- Modify: `frontend/src/router/index.js`（`/purchase/fill`）

**Interfaces:** Consumes: Task 12 契约（fill.php）。

- [ ] **Step 1: 侦察旧填报流程**

线上走查 #/fill 全流程至**提交前一步**；对照 `fill.php` 确认保存端点与校验规则。

- [ ] **Step 2: 实现**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读至提交前）**

表单项、联动、金额计算与线上一致；提交按钮到发请求前一步（staging 验证放 Task 18）。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 采购填报(月份+表单)重建"
```

### Task 15: 采购 my-items / products / summary

**Files:**
- Create: `frontend/src/modules/purchase/MyItems.vue`, `Products.vue`, `Summary.vue`
- Modify: `frontend/src/router/index.js`（`/purchase/my-items` `/purchase/products` `/purchase/summary`）

**Interfaces:** Consumes: Task 12 契约。

- [ ] **Step 1: 侦察旧实现**（线上走查三页 + log.php/products.php/summary.php 契约核对）

- [ ] **Step 2: 逐页实现**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

三页列表/筛选/导出与线上一致。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 采购我的物资/商品库/汇总页重建"
```

### Task 16: 采购 budget / customs / overview

**Files:**
- Create: `frontend/src/modules/purchase/Budget.vue`, `Customs.vue`, `Overview.vue`
- Modify: `frontend/src/router/index.js`（`/purchase/budget` `/purchase/customs` `/purchase/overview`）

**Interfaces:** Consumes: Task 12 契约（budget.php + admin.php 中的 customs/overview 部分）。

- [ ] **Step 1: 侦察旧实现**（线上走查 + budget.php/admin.php 核对；customs 为定制审核流，确认审核状态机）

- [ ] **Step 2: 逐页实现**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读至操作前）**

三页与线上一致；审核通过/驳回按钮到发请求前一步。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 采购预算/定制审核/管理总览页重建"
```

### Task 17: 采购 windows / oplogs / export-import

**Files:**
- Create: `frontend/src/modules/purchase/Windows.vue`, `Oplogs.vue`, `ExportImport.vue`
- Modify: `frontend/src/router/index.js`（`/purchase/windows` `/purchase/oplogs` `/purchase/export-import`；至此路由表齐全）

**Interfaces:** Consumes: Task 12 契约（system.php/log.php/export_import.php）。

- [ ] **Step 1: 侦察旧实现**（线上走查三页 + 契约核对；export-import 含上传解析与导出模板）

- [ ] **Step 2: 逐页实现**

- [ ] **Step 3: 构建通过**

Run: `npm run build` → 成功

- [ ] **Step 4: 对照走查（只读）**

三页与线上一致；模板下载可用。

- [ ] **Step 5: Commit**

```powershell
git add frontend/src; git commit -m "前端统一: 采购系统窗口/操作日志/导入导出页重建, 路由表齐全"
```

### Task 18: 写流程 staging 验证 + 全量对照走查

**Files:**
- Create: `frontend/docs/acceptance-checklist.md`（走查清单执行记录，保留作部署依据）

**Interfaces:** Consumes: Task 1-17 全部产出。**staging 方案需先经用户确认**（涉及服务器操作：建议方案 = 服务器建 `/var/www/payroll/staging/` 独立目录 + 测试用数据库副本 + 独立 Nginx 端口/路径，用户批准后才执行）。

- [ ] **Step 1: 与用户确认 staging 方案**（这是服务器操作，必须逐项批准）

- [ ] **Step 2: staging 环境搭建**（经确认后）

- [ ] **Step 3: 写流程全量验证**

填报提交、审批通过/驳回、台账保存、导入执行、商品库增删改、设置保存——每项在 staging 操作并核对结果数据。

- [ ] **Step 4: 全量走查清单执行**

40 页 × 核对维度（元素/数据/交互/导出/权限）逐项打勾记录到 acceptance-checklist.md；含 Review Focus #1（清 token 走查 401）、#2（只读账号登录复核菜单）。

- [ ] **Step 5: 修复走查发现的问题并复验，Commit**

```powershell
git add frontend; git commit -m "前端统一: staging 写流程验证与全量对照走查通过"
```

### Task 19: 生产构建 + Nginx 切换配置准备

**Files:**
- Create: `deploy/nginx-旧域名（已退役）-unified.conf`（本地准备完整 server 块，部署经确认）
- Modify: `public/app/`（最终构建产物）

**Interfaces:** Produces: 可部署的最终产物 + 切换配置。

- [ ] **Step 1: 最终生产构建**

Run: `cd frontend; npm run build`；核对产物体积（主 chunk 应远小于旧采购 1.18MB），记录数字。

- [ ] **Step 2: Nginx 配置编写**

以服务器现有配置为基底（部署时先 `cat /etc/nginx/sites-enabled/旧域名（已退役）`）：
- `root .../public/app;` + `index index.html;` + `location / { try_files $uri $uri/ /app/index.html; }`（SPA history 回退）
- gzip（text/css application/javascript application/json image/svg+xml）
- `location /app/assets/ { expires 1y; add_header Cache-Control "public, immutable"; }`
- `\.php$` 块与 Laravel 逻辑保持原样；`/purchase/` `/finance/` `/static/` `/payslip.html` 旧路径仍可达（回滚锚点）
- `/api` 反代不变（沿用现有 php 处理）

- [ ] **Step 3: 本地静态自检**

通读配置无 location 冲突；旧版路径可达性在配置中保留确认。

- [ ] **Step 4: Commit**

```powershell
git add deploy public/app; git commit -m "前端统一: 生产构建产物与 nginx 切换配置(部署待用户确认)"
```

### Task 20: 服务器部署（【用户逐项确认后执行】）

**Files:** 服务器侧：`/etc/nginx/sites-enabled/旧域名（已退役）`、`/var/www/payroll/laravel-app/public/app/`（新目录）、`app/Purchase/handlers/dashboard.php`（驾驶舱缓存补丁，随切换生效）

**Interfaces:** Consumes: Task 19 产物与配置、Task 18 验收记录。

- [ ] **Step 1: 向用户提交部署清单逐项确认**（备份→上传→配置→验证→回滚预案，每项单独批准）

- [ ] **Step 2: 服务器备份**

```bash
# 经确认后执行：
cp /etc/nginx/sites-enabled/旧域名（已退役） /root/nginx_conf_backup_<date>.conf
tar czf /root/pre_unified_backup_<date>.tar.gz -C /var/www/payroll/laravel-app app/Purchase/handlers/dashboard.php
```

- [ ] **Step 3: 上传产物与配置切换**（经确认后）

上传 `public/app/` 全量、新 nginx 配置；`nginx -t` 通过才 reload，失败立即还原备份。

- [ ] **Step 4: 上传 dashboard.php 缓存补丁**（经确认后）+ `php -l` 验证

- [ ] **Step 5: 健康检查**

`curl -s 旧域名（已退役）/up` → Application up；首页/登录/驾驶舱/财务各抽一页 200；`curl -sI /app/assets/<主chunk>` 见 gzip+immutable。

- [ ] **Step 6: 回滚预案演练说明交付**

书面告知用户回滚命令（还原 nginx 备份 + reload），旧版路径验证可达。

- [ ] **Step 7: 部署后用户抽查**（线上真实账号全流程抽查，问题→回滚或热修）

- [ ] **Step 8: Commit 走查记录**

```powershell
git add frontend/docs; git commit -m "前端统一: 部署完成与线上抽查记录"
```
