# 前端统一（单一 Vue3 SPA）设计文档

日期：2026-09-26
状态：已经用户批准设计方向，待用户审阅本 spec

## 1. 背景与问题

昊轩云枢（Laravel 12，旧域名（已退役））当前由 **4 个独立前端应用**组成：

| 应用 | 形态 | 规模 | 源码 |
|------|------|------|------|
| 主 SPA（public/index.html + static/app.js） | 手写 JS，无构建无模块化 | 469KB / 7150 行 | 有 |
| 采购 SPA（public/purchase/） | Vite 打包产物 | 主 chunk 1.18MB，14 个页面 chunk | **无源码**（无 sourcemap，无法反编译） |
| 财务 SPA（public/finance/） | 手写内联 JS + echarts | ~48KB + 1MB 图表库 | 有 |
| 工资条查询（public/payslip.html） | 静态页 | 小 | 有 |

问题：体验割裂（iframe 嵌套、token 走 URL、二次登录感）、工程上三套形态无法统一构建维护、采购模块加载慢（1.18MB 主 chunk + iframe 冷启动 + Nginx 无 gzip/缓存）。

## 2. 已确认的决策

用户逐项确认：

1. **统一目标：两者都要** —— 体验统一（一个入口一套导航一次登录）+ 工程统一（一个代码库一套构建）。
2. **实施节奏：一步到位** —— 新前端全部重写完成、自测通过后一次性切换；切换前线上保持旧版。
3. **UI 策略：复刻现有 UI** —— 新前端照搬现有各模块布局/交互/操作习惯，用户零学习成本；验收以旧版页面为基准。
4. **架构形态：单一 Vue3 SPA**（已否决 monorepo 多包、微前端方案——采购重建源码后无独立应用需要隔离）。
5. **旧版回滚锚点**：切换后旧四应用原地保留在服务器（不删），出问题通过 Nginx 配置秒级切回。
6. **主 SPA 重写风险形态**：接受"部署前完整自测清单 + 部署后用户抽查"作为验证手段。

## 3. 目标与非目标

**目标**

- 一个 Vue3 SPA 承载全部业务：统一登录、统一导航、统一 token，消除 iframe。
- 采购前端源码重建（参照线上功能 + 后端 API），纳入同一工程。
- 构建产物部署后显著改善加载性能（配合 Nginx gzip/缓存）。
- 后端 API 契约零改动。

**非目标**

- 不重设计 UI（明确复刻现有界面）。
- 不改后端业务逻辑、不改数据库。
- 不做移动端适配改造（保持现有桌面形态）。
- 不动 payslip.html（公开免登录轻量页，保留独立，避免公开页加载整壳 bundle）。

## 4. 架构方案

### 4.1 技术栈

- **Vite + Vue 3（组合式 API）+ Vue Router 4 + Pinia**
- UI 库 **Element Plus**（采购现有产物即此系，复刻采购 UI 最省力）
- 图表 **ECharts**（财务已用）
- 本地 Node 构建，产物输出到 `laravel-app/public/app/`，**构建产物提交 git**（部署=上传静态文件，服务器无需 Node；与现有采购产物入库模式一致）

### 4.2 工程结构

```
laravel-app/
  frontend/                     # 新 Vue3 工程
    package.json  vite.config.js
    src/
      api/client.js             # fetch 封装：X-Token 头、401 处理、错误提示（对齐现 app.js api()）
      stores/auth.js            # 登录态、token（写 sessionStorage.gw_token 保持兼容）、用户信息、权限
      layouts/MainLayout.vue    # 统一壳：侧边导航 + 顶栏 + 页面路由出口
      modules/
        home/                   # 首页门户（待办卡片、消息）
        hr/                     # 人事薪酬 14 页
        report/                 # 报表中心 4 页
        maint/                  # 维保 5 页
        oa/                     # 审批中心
        settings/               # 系统设置页组
        finance/                # 财务 5 页（重写）
        purchase/               # 采购 12 逻辑页（重建）
  public/app/                   # Vite 构建产物（入 git）
```

### 4.3 页面清单（复刻范围，页面名取自现有代码）

**主 SPA（app.js 7150 行，逐页重写为 Vue 组件）：**

- home：首页
- HR_PAGES（14）：summary, payroll, taxMode, attendance, org, staff, adjust, budget, export, perfCreate, perfApprove, perfReport, perfMine, perfRecords
- REPORT_PAGES（4）：reportHome, hrReport, salaryReport, attReport
- MAINT_PAGES（5）：fireReport, elevReport, maintFire, maintElev, maintPartners
- OA：approvalCenter（含草稿/收件箱/审批详情/流程设置视图）
- 系统设置页组：settingsPerm, settingsStaffField, settingsCompany, settingsSecurity, settingsBackup, settingsLogs, approvalFlow
- PURCHASE_PAGES / FINANCE_PAGES 中的 iframe 宿主页 → 直接变为新 SPA 内部路由，宿主页面本身消失

**采购 SPA（12 逻辑页，参照线上逐页重建）：**

Dashboard, FillMonths, FillForm, MyItems, PurchaseSummary, ProductLibrary, BudgetPlan, CustomReview, AdminOverview, SystemWindows, OpLogs, ExportImport（Login/Layout 并入统一壳，不再独立存在）

**财务 SPA（5 页，重写）：**

financeDashboard, financeLedger, financePayments, financeSummary, financeImport

**payslip**：保留 public/payslip.html 原样（不在重写范围）。

## 5. 鉴权与体验统一

- 统一登录走现有 `/api/login`，获得单一 token；Pinia auth store 持有，API 客户端统一加 `X-Token` 头；同时写 `sessionStorage.gw_token` 保持与现有后端/脚本兼容。
- 采购 API 与财务 API 沿用同一 token（现有 `__dashAllShim` 已证明采购后端接受 `gw_token`；重建采购时若发现独立账号体系行为差异，对照线上处理并在核对清单记录）。
- 按角色/权限决定菜单可见性：沿用主 SPA 现有 perm 模型（如 `perm: "users"`），采购/财务模块沿用其现有角色表现，逐页对照线上。
- 体验收益：无 iframe、无 URL 传 token、无二次登录、模块切换即时。

## 6. 后端边界

- **API 完全不动**：`/api/*`（工资系统）与 `/api/purchase/*`（采购）照旧。
- **唯一后端改动（沿用既有性能计划）**：保留 `/dashboard/all` 聚合接口 + 5 分钟后端缓存（`app/Purchase/handlers/dashboard.php`），统一前端直接调用该聚合接口，驾驶舱一次请求。

## 7. 部署与回滚（一步到位）

1. 本地完成全部重写 + 自测清单通过 → **用户逐项确认后**才动服务器。
2. 部署内容：`public/app/` 产物上传、Nginx 配置更新（站点根改指新入口 + gzip/静态缓存一并生效）、`dashboard.php` 缓存补丁上传。
3. **回滚锚点**：旧四应用（public/index.html、static/、purchase/、finance/）原地保留；回滚 = Nginx 配置切回 + reload（秒级）。
4. 部署前对将被修改的 Nginx 配置、dashboard.php 做服务器侧备份（`/root/<what>_backup_<date>`，既有约定）。

## 8. 开发与验证环境

- 日常开发：Vite dev server 代理 `/api` 到线上 旧机（已退役），只读验证（查询、列表、图表）。
- 写操作验证（填报、审批、删除等）：部署前在服务器建 staging 验证位（独立路径 + 数据库副本或专用测试账号），避免污染生产数据；具体方案在实施计划中确定，部署前经用户确认。

## 9. 验收标准

每个模块建立"旧版对照清单"（页面元素、操作流、导出下载、权限差异、金额/日期格式），逐项核对通过才算完成：

- 主 SPA 每页：以线上页面为基准逐页核对（约 30 页）。
- 采购每页：以线上 12 页为基准（含驾驶舱 12 组图表数据一致性、填报提交流程、审核流转）。
- 财务：图表渲染与数字一致、导入/台账/付款/汇总流程核对。
- 全局：登录/登出、菜单权限、首页待办、导出下载、401 处理。

## 10. 与既有性能优化计划的关系

`docs/superpowers/plans/2026-09-26-haoxuan-integration-performance.md` 中：

- **保留**：Task 0（git 基线）、Task 1（财务 echarts 修复）、Task 3（Nginx gzip+缓存，部署时随统一切换）、Task 4（驾驶舱后端缓存）、Task 6（垃圾文件清理，切换前可先行）、服务器侧备份约定。
- **作废（被统一取代）**：Task 2（token 去 URL 化 shim）、Task 5（iframe 保活）——统一后无 iframe，这些补丁失去作用对象。
- Task 1 修复（echarts 引用错误）建议先行部署生效，与统一工程互不影响。

## 11. 风险与缓解

| 风险 | 缓解 |
|------|------|
| 主 SPA 7150 行重写引入回归 | 逐页对照清单；部署前完整自测；旧版保留秒级回滚 |
| 采购重建漏功能/行为差异（无源码参照） | 以线上 12 页为功能基准 + 后端 handler/API 为契约基准逐项核对 |
| 一步到位切换风险集中 | Nginx 秒级回滚锚点；部署窗口由用户确认 |
| 隐藏细节（金额精度、日期格式、边缘校验） | 对照线上实测，纳入逐页清单 |
| 自测写操作污染生产数据 | staging 验证位承载写流程测试 |
| 采购/财务与主系统 token 兼容性未知项 | 重建时实测线上行为，差异记录并对照处理 |
