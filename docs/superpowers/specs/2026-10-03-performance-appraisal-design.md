# 绩效考核模块改造设计

日期：2026-10-03
状态：待用户审阅
适用系统：haoxuanyunshu 薪酬系统（laravel-app）

## 1. 背景与目标

现有绩效模块（`app/Http/Controllers/Api/PerformanceController.php` + `frontend/src/modules/perf/`）已具备完整的七态考核流程和算分能力，但与实际业务使用存在四处差距：

1. 上级审核指标时只能通过/驳回，不能直接修改目标值与权重；
2. 全部指标统一按"自评×占比 + 上级评分×占比"合成，客观数据算出的分只作参考，未锁定；
3. 自评无法上传评分依据附件；
4. 没有适合打印签字的考核表。

本次改造目标：让经理级与总部人员（**不超过 40 人**）在线完成「目标制定→审核→数据核查→自评→上级评分→汇总出分」全流程，最终**打印纸质考核表签字**，绩效工资由管理者手工录入薪资模块。**考核结果不与薪资系统自动联动。**

## 2. 改造后流程

```
本人发起（draft）
  填考核周期、指标（内容/类型/目标值/权重/数据核查人），手动勾选审批领导（可多级）
  每项指标手动勾选数据核查人（可选本人）；权重合计=100
    │ 提交
    ▼
上级审核指标（confirm）
  首位审批人可直接编辑指标并多次保存【改动留痕】；可通过或驳回（驳回必须填意见）
  审核通过 = 指标即刻生效，不另行纸质签字
    │ 通过
    ▼
考核周期中（ongoing）—— 指标冻结，任何人不可修改
    │ 到达周期结束日（列表访问时懒推进，沿用现状）/ 发起人或管理员手动结束
    ▼
数据核查填报（report）
  各指标核查人填报：客观项填数值（系统自动算分）/ 核查定分项直接给分 / 主观项填文字说明
    │ 全部填完，或发起人/管理员手动结束填报
    ▼
本人自评（self）
  仅主观项本人打分；可按项上传评分依据附件（截图，每项≤5个）
  客观项锁定展示，不接受本人评分
    │ 提交自评
    ▼
上级逐级评分（approve，currentStep 逐级推进）
  仅主观项上级打分；可查看自评附件；终审可对主观项最终分微调
  归档前校验：所有客观项均已有锁定分，否则拒绝并列出缺分项
    │ 终审通过
    ▼
归档（done）：最终总分 + 等级确定
    │
    ▼
打印考核表（网页 A4，浏览器打印/存 PDF）→ 纸质签字 → 手工录薪资
    │
    └─ 发现错误：管理员可"撤销归档"，退回上级评分环节（自评分、核查分保留，留日志）
```

驳回回路：

- confirm 驳回 → 退回 draft，由本人修改后重新提交；
- approve 驳回 → 退回 self，本人调整自评后重新提交。

## 3. 指标类型与算分模型（核心改动）

### 3.1 五种指标类型

前端展示名按下表调整（后端存储键不变，仅新增 `check`；`ratio/ladder/count` 为既有键，只改中文显示）。

| 显示名 | 存储键 calcType | 配置参数 | 得分方式 | 类别 |
|---|---|---|---|---|
| 比例计分 | `ratio` | 目标值 target | min(权重, 权重×实际/目标) | 客观 |
| 阶梯扣分 | `ladder` | 目标值/每档单位/每档扣分/清零线 | 现有公式不变 | 客观 |
| 达标扣分 | `count` | 达标线 required / 每单位扣分 deductEach | min(权重, 权重−(达标线−实际)×扣分)，现有公式不变 | 客观 |
| 核查定分 | `check`（**新增**） | 无公式参数 | 核查人直接在 0..权重 区间给分 | 客观 |
| 主观评分 | `manual` | 无 | 自评×自评占比 + 上级评分×上级占比 | 主观 |

业务示例：

- "回款率不低于 80%，每低 1% 扣 3 分" → 达标扣分：达标线 80、每单位扣 3。
- "每月 5 号前发起流程，每延期 1 天扣 2 分" → 核查定分：行政/人事（或本人）核查后直接填扣减后的得分，系统锁定。

### 3.2 合成规则（applyScores 改造）

对每个指标项：

- 客观项（ratio/ladder/count）：填报数值后 `autoScore()` 算分，该项最终分 = 自动分，**锁定**；
- 核查定分项（check）：最终分 = 核查人填报的 `checkScore`，**锁定**；
- 主观项（manual）：最终分 = 自评分×自评占比 + 上级评分×上级占比；终审微调过的主观项以微调值为准（沿用现有 `finalManual` 机制）。

总分：

```
最终总分 = Σ 客观项锁定分 + Σ 主观项加权分
等级     = 按既有 gradeRules 匹配（performance.json，管理员可配）
自评总分 = Σ 主观项自评分（客观项不计入自评总分）
上级总分 = Σ 主观项上级评分（同上）
```

自评/上级占比沿用现有 `scoreWeights` 设置（默认各 50%，合计必须 100，管理员可配），**本次不新增三方比例**。

显式失败原则（与全系统既有原则一致）：

- 终审归档时，任何客观项（含 check）没有锁定分 → 拒绝归档（400），返回缺分指标清单；不静默按 0 计。
- `checkScore` 越界（<0 或 >该项权重）→ 拒绝。
- 对客观项提交 selfScore / approverScore → 拒绝（400）。

### 3.3 核查人可选择本人

每项指标的"数据核查人"（现有 `reporterId` 字段）下拉保持全量人员，**允许选择被考核人本人**。语义与风险：

- 本人作为核查人时，由本人在 report 阶段填报/定分，分数同样锁定；
- 打印表上每项显示"核查人：姓名"，自我核查的事实在签字时可见，由审批领导在评分环节知情把关；
- 不做额外限制（用户明确要求允许）。

## 4. 功能改动详述

### 4.1 发起（draft）

沿用现有 `save()`，改动点：

1. 指标类型选项按第 3.1 节更新中文显示；
2. `check` 型指标：无需公式参数，只需权重；后端保存时不校验 calcParams；
3. 核查人下拉允许选本人（前端现有数据源为全量人员，开发时验证本人在列，无需后端改动）；
4. 审批领导继续手动勾选、可多级、必须有启用账号（现有校验保留）。

### 4.2 上级审核可直接改指标（confirmSave，新增）

新接口 `POST /api/performance/plans/confirm-save`：

- 权限：仅当前单首位审批人（staff/user 匹配，同 confirm 现有判断）或 admin；
- 前置状态：必须为 `confirm`；
- 入参：整体替换后的 `categories`；
- 可改字段：指标类别/内容/定义/类型/公式参数/权重/核查人；
- **禁止改：被考核人、周期起止、审批人链**——这些字段以服务端现存数据为准，入参中即便携带也忽略（不采用整体覆盖 plan 的写法）；
- 校验：权重合计=100、calcType 合法、check 型无必填公式参数、核查人存在；
- 留痕：与库中现值逐项 diff，写入 plan 日志，形如「指标『回款率』权重 20→25；达标线 85→80（审核人：张三）」；
- 可多次保存，不改变状态；点"审核通过"仍走现有 confirm 接口。

前端：PerfDetail 在 confirm 状态且当前用户有审核权时，指标区从只读切换为可编辑（复用 PerfCreate 的指标编辑区块），按钮为「保存修改 / 审核通过 / 驳回」。

### 4.3 指标冻结

- `save()` 增加状态守卫：仅 `draft` 可保存指标；其余状态返回 400（confirm 状态的修改只能走 4.2 的 confirmSave，且仅审批人可改指标项）；
- 进入 ongoing 后指标全部冻结，直至归档；
- 删除守卫：`done` 状态禁止删除考核单（只能撤销归档）；其余状态 admin/发起人可删（现状）。

### 4.4 数据核查填报（report 扩展）

现有 `report()` 按类型扩展：

- ratio/ladder/count：必须填数值 actualValue（现状不变），算 autoScore；
- check：不接受 actualValue，接受 `checkScore`（数值，0..权重），记录 reportBy/reportTime；
- manual：接受 actualText 文字说明（现状不变）。

未指派核查人的指标在填报阶段自动跳过（现状保留）；但因此产生的客观项缺分由 3.2 的归档校验拦截。

### 4.5 自评 + 附件（self 扩展）

- `selfSubmit()` 仅接受 manual 项的 selfScore；客观项提交分数 → 400；
- 主观项支持附件，见第 5 节；
- 自评提交后进入 approve（现状）。

### 4.6 上级逐级评分（approve 收紧）

- 仅接受 manual 项的 approverScore / 终审 finalScore 微调；对客观项提交分数 → 400；
- 评分页可预览自评附件；
- 终审通过（最后一级 approve）时执行归档校验（3.2），通过后置 done、确定最终总分与等级；
- 逐级权限、驳回机制不变。

### 4.7 撤销归档（reopen，新增）

新接口 `POST /api/performance/plans/reopen`：

- 权限：仅 admin；
- 前置状态：done；
- 状态回退到 `approve`，currentStep=0，清空各审批节点 state 与审批时间；
- 清除每项的 approverScore、finalScore、finalManual；**保留** selfScore、actualValue/actualText/checkScore/autoScore、附件；
- 重新执行 applyScores 后落库，写日志「管理员撤销归档，退回上级评分」；
- 上级需重新逐级评分、终审重新归档。

### 4.8 打印考核表（新增页面）

前端新增路由 `perfPrint/:id`（不进菜单，从 PerfDetail「打印考核表」按钮打开新窗口），组件 `PerfPrint.vue`，纯展示 + `@media print` 的 A4 排版，浏览器直接 Ctrl+P 打印或另存 PDF。

内容：

1. 抬头：标题"绩效考核表"、被考核人、岗位/条线、所属项目、考核周期起止；
2. 明细表列：类别 / 指标内容 / 目标与评分标准 / 权重 / 实际完成（数值或文字）/ 核查人 / 自评分 / 上级评分 / 最终得分；客观项自评、上级分列显示"—"（锁定）；
3. 主观项实际完成列下方显示自评附件缩略图（图片缩略，PDF 显示文件名图标）；
4. 汇总区：自评总分、上级评分总分、最终总分、等级；
5. 签字区：被考核人签字＿＿＿、各级审批人（按审批链逐行列出姓名）签字＿＿＿、日期＿＿＿。

页面数据复用 detail 接口（权限见第 6 节）。

## 5. 附件机制（新增）

- 存储：`storage/app/perf-attachments/{planId}/{itemId}_{YmdHis}_{rand}.{ext}`，**不在 public 目录、不进 git**；
- 类型：jpg/jpeg/png/pdf；单文件 ≤10MB；每项指标 ≤5 个；
- 上传：`POST /api/performance/attachment`（multipart：id, itemId, file）。仅本人（被考核人/发起人）在 self 阶段可传；文件名校验、服务端重命名；附件元数据（原名、存储名、大小、上传人、时间）写入 item.attachments JSON 数组；
- 下载：`GET /api/performance/attachment?id=&file=`，登录后按第 6 节权限校验，且 file 仅允许 `{planId}/` 下的简单文件名（防目录穿越），流式返回并带 Content-Type；
- 删除：`DELETE /api/performance/attachment?id=&itemId=&file=`，仅上传者本人在 done 前可删，同时删磁盘文件与元数据；
- 归档后附件只读；
- 前端图片预览通过 fetch + Blob URL 实现（img 标签不直接暴露带凭据 URL）。

## 6. 权限矩阵

| 动作 | 本人(被考核人) | 发起人 | 审批人 | 指标核查人 | admin |
|---|---|---|---|---|---|
| 查看考核单详情/打印 | ✓ | ✓ | ✓（该链上） | ✓（指派项所在单） | ✓ |
| 编辑/提交草稿 | 本人单 | ✓ | – | – | ✓ |
| 审核期改指标、通过、驳回 | – | – | 首位审批人 | – | ✓ |
| 核查填报 | 本人作为核查人时 | – | – | ✓ | ✓（代填） |
| 自评、传附件 | ✓ | ✓（代自评现状保留） | – | – | ✓ |
| 上级评分/终审/驳回 | – | – | 当前节点人 | – | ✓ |
| 撤销归档 | – | – | – | – | ✓ |
| 批量导出 Excel | – | – | – | – | ✓ |

现有 detail 接口若无数据级权限校验，本次补齐为上表"查看"集合；Excel 单导/批量导维持 admin/发起人现状。

## 7. 数据模型

不新建表、不做迁移。全部扩展落在 `performance_plans.data` JSON：

- item 新增可选字段：`checkScore`（number）、`attachments`（array）；
- 日志沿用现有 logs 数组；
- 其余字段（calcType/calcParams/weight/reporterId/selfScore/approverScore/finalScore/finalManual）沿用。

生产当前无考核单数据，无历史数据迁移负担；既有键 ratio/ladder/count/manual 语义不变。

## 8. 接口清单

| 方法/路径 | 状态 | 说明 |
|---|---|---|
| POST /api/performance/plans/save | 改 | 状态守卫（仅 draft）；接受 check 型 |
| POST /api/performance/plans/confirm-save | **新** | 审核期审批人改指标，diff 留痕 |
| POST /api/performance/plans/confirm | 不变 | 审核通过 |
| POST /api/performance/plans/reject/{node} | 不变 | 驳回 |
| POST /api/performance/plans/report | 改 | check 型接受 checkScore |
| POST /api/performance/plans/self-submit | 改 | 仅主观项收分；附件字段由独立接口维护 |
| POST /api/performance/plans/approve | 改 | 仅主观项收分；终审归档校验客观项齐全 |
| POST /api/performance/plans/reopen | **新** | admin 撤销归档 |
| POST /api/performance/attachment | **新** | 附件上传 |
| GET /api/performance/attachment | **新** | 鉴权下载 |
| DELETE /api/performance/attachment | **新** | 附件删除（归档前） |
| GET 考核单详情 | 改 | 补齐数据级查看权限 |
| export / exportAll / ranking / gradeRules | 不变 | – |

后端 `PerformanceController::CALC_TYPES` 常量同步增加 `check => '核查定分'`（该常量用于填报错误提示，[L14](../../../app/Http/Controllers/Api/PerformanceController.php)）。

## 9. 前端改动清单

- `perfLogic.js`（或组件内常量）：CALC_TYPES 中文显示更新，新增 check；
- `PerfCreate.vue`：类型选项、check 型无参数 UI、核查人含本人验证；
- `PerfDetail.vue`：
  - confirm 态审核人指标编辑模式（保存修改/通过/驳回）；
  - report 态 check 定分输入；
  - self 态主观项打分 + 附件上传/列表/删除；
  - approve 态附件预览、客观项锁定、终审归档缺分提示透传；
  - 「打印考核表」按钮（done 后对可见集合开放）；
  - 指标修改留痕在日志区展示；
- `PerfPrint.vue`：**新增**打印页；
- `router/index.js`：新增 `perfPrint/:id` 路由；
- `PerfRecords.vue`：admin 对 done 单加「撤销归档」按钮与确认弹窗。

## 10. 测试计划（TDD，后端先行）

PHPUnit（sqlite :memory:，沿用现有测试基建）：

1. check 型：核查人填报 checkScore 后锁定，本人/上级对该项提交评分均 400；
2. checkScore 越界（负数、超权重）→ 400；
3. 客观项有自动分、主观项加权分，最终总分/等级计算正确；
4. 终审时客观项缺分 → 400 且返回缺分项清单；
5. confirm-save：首位审批人改权重/目标值成功且日志含前后值对照；非审批人 403；非 confirm 态 400；
6. confirm-save 试图改被考核人/周期/审批链 → 被忽略且数据未变；
7. confirm-save 权重合计≠100 → 400；
8. save 在 ongoing/report/self/approve/done 状态改指标 → 400；done 状态删除 → 400；
9. reopen：仅 admin；done→approve，审批链重置，自评/核查分/附件保留，上级分与微调清除，重算正确，有日志；
10. 附件：上传成功落库落盘；非本人 403；类型/大小/数量超限 400；下载鉴权（无权 403、目录穿越 400）；归档后删除 400；
11. detail 查看权限：五种角色正反例；
12. 既有自评/审批/等级/导出回归：现有测试全绿。

前端：40 人内部系统，以手工走查为主（发起→审核改指标→核查→自评传图→评分→终审→打印→撤销重审全链路两种角色各走一遍）。

## 11. 明确不做

- 不与薪资模块联动、不写绩效系数表；
- 不新建数据库表、不做数据迁移；
- 不做第三方（核查人）参与加权评分；核查数据/定分只作为锁定客观分；
- 不做期初目标纸质确认表（审核通过即生效是确认过的业务规则）；
- 不处理已知技术债：列表全表 PHP 过滤、autoAdvance 懒推进（≤40 人规模无实际影响）；
- 不做移动端专门适配。

## 12. 风险与注意事项

1. **自我核查风险**：核查人选本人时，本人填报的客观分同样锁定，可能虚高；缓解手段是打印表显示核查人、上级签字知情。如后续发现滥用，可再限制"核查定分项核查人不得为本人"。
2. **附件运维**：文件在 storage/app 下，服务器快照/备份需覆盖该目录；部署前端构建产物时不涉及。
3. **磁盘占用**：40 人 × 每季数张截图，年量级 <1GB，无压力。
4. **生产无存量考核单**，算分语义修改不影响历史数据。
5. **终审人账号要求**：审批领导仍须有启用账号（现有硬校验保留）；不用系统的领导无法在线审批，需 admin 代操作（admin 代评分能力现状已有）。
6. 前端改动需 build 后随部署流程发布；后端 PHP 改动无迁移。
