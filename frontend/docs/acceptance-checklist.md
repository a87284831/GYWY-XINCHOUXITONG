# 前端统一 · 验收走查清单（Task 18）

- 走查日期：2026-09-27
- 走查方式：本地 Vite dev（localhost:5173）代理生产 API（https://oa.haoxuanyunshu.cn，真实库），admin/admin123 与 song/12345678（项目账号·罗庄春暖花开）双角色
- 对照基准：线上旧三前端（根站 `/static/app.js?v=v20260925f`、`/purchase/`、`/finance/`），同一套后端 API（后端零改动）
- 维度：元素（页面结构/关键控件/文案）、数据（真实库渲染）、交互（写流程/弹窗/键盘）、导出（下载链路）、权限（菜单/路由/接口三层）
- 结论：**全部通过（PASS）**；走查发现 3 处问题均已修复并复验（见第 4 节）

## 1. Review Focus（计划指定两项）

| # | 项 | 结果 | 证据 |
|---|---|---|---|
| 1 | 清 token 后受保护 API 返回 401，前端清登录态并跳 /login | PASS | sessionStorage 移除 gw_token 后深链 /app/purchase/windows → 自动落 /app/login；置入坏 token 后访问 → /api/init 返回 401 → client.js 清 token（复查 gw_token=null）→ /app/login |
| 2 | 只读/填报账号登录复核菜单与越权拦截 | PASS | song 侧栏采购区仅「数据驾驶舱 / 采购填报 / 我的填报记录」3 项；8 个 adminOnly 项（进度确认/清单外/预算/汇总/导出导入/商品库/窗口/日志）全部隐藏；launcher 仅启用 hr/maintain/purchase/report 4 个应用。直访 /app/purchase/windows：页面框架渲染但 GET /windows、PUT /settings 均 403 `{ok:false,msg:"无权限"}`，红 toast「无权限」；/api/purchase/my/months 200。与旧版 SystemWindows chunk 行为同构（旧版 mounted 调 /windows catch ElMessage.error） |

鉴权实现备注：新 SPA token 存 **sessionStorage**（gw_token，关标签即失效）；client.js 对 401 统一清 token + router.push('/login')；路由守卫对无 token 深链、有 token 未 hydrate（ensureReady 复用 /api/init）均有处理。

## 2. 写流程全量验证（Session B–E，真实库）

| 流程 | 角色 | 结果 |
|---|---|---|
| 草稿保存/提交/撤销提交/删除/我的填报/月度卡片计数 | song | PASS |
| 行内确认、清单外归入标准商品库、退回（含超预算批量退回 toast） | admin | PASS |
| 退回后 song 重提、状态机（draft/submitted/confirmed/returned） | song/admin | PASS |
| 微调真验：submitted 行 66→60→66 落库保留单价；confirmed 行后端拒改且 UI 无微调按钮（新旧一致） | admin | PASS |
| 导入存档→预算对比弹窗（danger）→超预算退回→存档表（全角￥）→退回状态/原因回显 | admin/song | PASS |
| 批量导入多文件：200/截断真 xlsx 失败/无月份文件名失败，三文件结果表 | admin | PASS |
| 月度/年度导出：blob 200（年导 7363B/629ms），绿浮层 toast | admin | PASS |
| save_alias：清单外行 map 到标准商品 + 别名写入 product_synonyms，测后行与同义词已删 | admin | PASS |
| 窗口：键盘填三字段新增 2099-09、删除确认框链路、空表单「请填写完整」、编辑键盘改截止日、停用/启用 | admin | PASS |
| 阈值：el-input-number 键盘 30→99（PUT/GET 一致）→恢复 30 | admin | PASS |

## 3. 全量页面走查（40+ 路由，真实数据渲染无错误请求）

全部页面 HTTP 200、核心元素与旧版一致、真实数据正确、无 4xx/5xx（权限设计内 403 除外）、console 无业务错误（仅导航取消 ERR_ABORTED 与 Element Plus 弃用 warn，后者已修）。

| 模块 | 页面（路由） | 数据核对 | 结果 |
|---|---|---|---|
| 工作台 | home (/) | 在职544 / 本月发放43 / 维保合同18（消防11·电梯7），与线上旧根站逐字一致 | PASS |
| 薪资 | summary | 发放43人、项目汇总表、临沂蓝钻庄园行 | PASS |
| 薪资 | payroll | 员工/管理/案场/总部 4 类核算页签、17 项目、核算/归档按钮 | PASS |
| 薪资 | taxMode | 两种个税模式说明、项目选择 | PASS |
| 考勤 | attendance | 模板下载/上传/锁定/导出、17 项目 | PASS |
| 人事 | org | 组织架构树（万城服务/各项目部门人数） | PASS |
| 人事 | staff | 全部1649 / 在职544 / 离职1105；统计接口约 3–5s 返回（初次扫描曾误判为 0，等待后完整，非缺陷） | PASS |
| 人事 | adjust | 调薪/定薪入口、历史流水空态 | PASS |
| 人事 | budget | 2025–2027 年度、12 月预算表、导入入口 | PASS |
| 人事 | export | 汇总/分项目两模式、项目多选 | PASS |
| 基础 | projects | 17 项目档案行（含编辑/停用/删除） | PASS |
| 绩效 | perfCreate | 被考核员工联想（真实员工：全伟/全先艳…） | PASS |
| 绩效 | perfApprove / perfReport / perfMine | 三类空态正确（当前无考核单） | PASS |
| 绩效 | perfRecords | 0 草稿/各状态计数、季度筛选、批量导出 | PASS |
| 报表 | reportHome | 3 张报表卡片 + 描述 | PASS |
| 报表 | hrReport / salaryReport / attReport | 年/月筛选器齐全，图表容器渲染 | PASS |
| 维保 | fireReport | 合同11 / 在管9 / 金额 KPI | PASS |
| 维保 | elevReport | 合同7 / 在管6 KPI | PASS |
| 维保 | maintFire / maintElev | 台账筛选/排序/导出CSV、表头列齐全 | PASS |
| 维保 | maintPartners | 签约方列表（山东久安/广日/涌安…） | PASS |
| OA | approvalCenter | 我的工作/发起与办理/流程中心三组页签 | PASS |
| 设置 | settings（权限管理） | 6 角色与账号数（超级管理员1/项目账号4/员工自助2…） | PASS |
| 设置 | logs | 系统日志页（当前暂无日志，与数据状态一致） | PASS |
| 设置 | backup | 策略/立即备份/危险清除区 | PASS |
| 设置 | salarySettings | 自定义薪酬项、五险一金/专项附加参数 | PASS |
| 财务 | financeDashboard | 年度/项目筛选、KPI | PASS |
| 财务 | financeLedger | 17 项目 × 年度选择、加载/导出 | PASS |
| 财务 | financePayments | 三状态区块、费用类别列、17 项目行 | PASS |
| 财务 | financeSummary | 月份×类别矩阵、累计/年度切换 | PASS |
| 财务 | financeImport | 导入说明（551 应收/47 付款历史）、模板下载 | PASS |
| 采购 | purchaseDashboard | 空库态：0 元、0/17 填报进度、12 图表面板 | PASS |
| 采购 | purchaseFill | 2026 年月卡片、当前 9 月开放/历史只读提示 | PASS |
| 采购 | purchaseFillForm | 过窗态「填报未开放（窗口 2026-09-20 至 2026-09-23）」、批量填写工具栏 | PASS |
| 采购 | purchaseOverview | 17 个项目未填报清单、按项目/按条线切换 | PASS |
| 采购 | purchaseCustoms | 归入说明 + 空态「暂无清单外商品」 | PASS |
| 采购 | purchaseBudget | 12 月速览、0/17 已填、¥0.00（测后恢复态） | PASS |
| 采购 | purchaseSummary | 已存档 0 个月份（测后清空态） | PASS |
| 采购 | purchaseProducts | 标准商品库 1012 条，条线/分类/别名列，编辑/别名/删除操作 | PASS |
| 采购 | purchaseOplogs | 登录动作日志、角色标签（招采/员工） | PASS |
| 采购 | purchaseWindows | 2026-09 ~ 2027-08 共 12 行（20–23），阈值 30，一键生成/新增 | PASS |
| 采购 | purchaseExportImport | 导出报价流程说明、月份选择、批量导入区 | PASS |
| 采购 | purchaseMyItems | staffOnly 菜单；song 侧 Session B 全链路已验 | PASS |

## 4. 走查发现并修复的问题

| # | 问题 | 性质 | 修复 |
|---|---|---|---|
| 1 | 批量导入字段名：新旧前端均用 `files`（同名无括号），PHP 多文件被折叠为单文件，`handle_import_batch()` 必 500（foreach string given）；契约文档本就记载 `files[]`，curl 验证 `files[]` 正确 | **既存生产 bug（旧版同错）** | [ExportImport.vue](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/purchase/ExportImport.vue) FormData 改 `files[]` 并加注释，UI 三文件复测 PASS |
| 2 | Element Plus 未配中文 locale，ElMessageBox 默认按钮 Cancel/OK（旧版 Element UI 为 取消/确定） | 全站性复刻差异 | [main.js](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/main.js) `app.use(ElementPlus, { locale: zhCn })`；复验删除确认框按钮为「取消/确定」 |
| 3 | el-link `:underline="false"` 触发 Element Plus 3.0 弃用 warn（新 API 为 always/hover/never） | 控制台警告 | [Overview.vue:390](file:///c:/Users/87284/Documents/trae_projects/1/laravel-app/frontend/src/modules/purchase/Overview.vue#L390) 改 `underline="never"`（视觉与旧版 false 等价：从不显示下划线） |

## 5. 复核为「保真不改」的观察项

- el-alert `type="danger"`：Element Plus 合法值仅 success/info/warning/error，console 有 warn；但旧版导出导入页同样写 danger，视觉一致属逐字复刻，不改。
- customs 页 save_alias 复选框在窄视口被 fixed 操作列遮挡：旧版同样式。
- confirmed 行无微调按钮：新旧一致（微调仅对非 confirmed）。
- FillForm 保存/提交无二次确认弹框：与旧版一致。
- staff 页统计数字加载约 3–5s（人员库 1649 条）：接口正常，非缺陷。

## 6. 测试后环境恢复与清理（已逐项复核）

| 项 | 恢复/清理结果 |
|---|---|
| fill_windows | 2026-09 截止日恢复 23；测试窗口 2099-09 已删除（count=0）；未来窗口 2026-10~2027-08 为「一键生成」正常业务配置，保留 |
| sys_settings | price_threshold 恢复 30 |
| budget_plan | 2026-09 全量 17 行恢复 0（罗庄 project_id=11 = 0.00，全月无非零） |
| purchase_items | 测试行 id=8/9 已删，全表 0 |
| archived_purchases | 2026-07/08/09 测试存档已删，全表 0 |
| notifications / audit_records | 20 条测试通知、2 条 map 审核记录已删，全表 0 |
| op_logs | 当日 132 条测试操作日志已删；历史日志保留；商品库 products=1012、product_synonyms=1（历史既存）未动 |
| 临时设施 | vite.config.js 的 `/__sf` 临时代理已撤；本地 8931 文件服务已停；服务器 /tmp 6 个测试 xlsx 已删；本地 %TEMP% 7 个测试文件（含 fserve.js）已删 |
| 既存生产 bug 热修（Session A） | 遗留僵尸表外键 `purchase_items.fk_item_project` 已 DROP（挂 gy_procurement.projects 僵尸表，id 9006 段漂移，阻断全部填报写入）；保留 fk_syn_product、fk_item_product、fk_user_project |
