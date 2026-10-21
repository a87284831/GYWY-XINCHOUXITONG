# 薪资核算数据缺失护栏与全系统空值显示规范设计

- 日期：2026-10-01
- 状态：待审核
- 分支：feature/frontend-unification
- 影响范围：薪酬核算引擎、工资表页、归档接口、Excel 导出、工资条、其他模块前端显示层

## 1. 背景与目标

用户提出两点要求（原话）：

1. 「在薪资核算的时候，如果有人的工资获取失败要进行提示，最终工资为 0 时也要提示」
2. 「整个系统凡是涉及数据获取的，数据获取失败或者为空的，都要直接显示为空，不能静默降级，会导致数据计算错误」

现状问题：

- 仅「离职人员有出勤但固定月薪/基本工资均为 0」会产生警告；**在职人员薪资基数缺失时静默计 0 并出工资行**。
- 绩效系数/档位缺失只写入行内 `perf_detail.error` 标记，藏在表格明细列，核算完成提示不汇总，容易漏看。
- 最终实发为 0 或负数（如产假员工社保照扣）无任何提示。
- 已上线的离职方案行为是「出 0 工资单 + 警告」，0 底值会落库、进个税累计链路、进汇总统计，本质是把「数据缺失」当「真实 0」用。
- 前端 46 个文件存在 165 处 `?? 0` / `|| 0`，其中部分属于「后端返回空值 → 界面渲染成 0」的静默降级，掩盖数据缺失。

### 目标

- 核算时任何薪资数据获取失败都显式提示，且数据缺失者**不产生工资行**（无法被误归档/误发放/污染统计）。
- 实发 ≤ 0 的合法情形（产假等）照常出行，但高亮 + 列清单，让核算人知悉。
- 归档时存在未处理异常必须二次确认（后端权威拦截，非仅前端弹窗）。
- 薪资链路之外的模块，只在显示层统一「空值显示为空（—）」，不改后端逻辑；改动点以清单形式交用户确认后执行。

### 非目标（本次不做）

- 不改动任何薪酬计算公式（基本工资、绩效、社保、个税口径均不变）。
- 不改动采购/财务/维保等模块的后端计算逻辑。
- 不做新的通知渠道（不接钉钉消息/邮件），提示仅在系统页面内。
- 不做历史已归档月份的追溯重算。
- 不修改数据库列定义（`fixed_monthly`/`base_salary` 维持 decimal NOT NULL default 0，见 §6 限制）。

## 2. 业务口径（已与用户逐条确认）

| # | 决策点 | 结论 |
|---|--------|------|
| 1 | 范围 | 薪资核算链路（引擎/工资表/导出/工资条/归档）完整做；其他模块仅统一前端显示层 |
| 2 | 0 工资提示形态 | 扩展现有核算完成 missing/warnings 文本清单 + 工资表行高亮（不做独立弹窗报告） |
| 3 | 薪资基数缺失者 | **跳过不出工资行**，进 missing 清单；补录数据后重算才出行 |
| 4 | 已上线离职方案 | 同步从「出 0 工资单 + 警告」改为「跳过 + 警告」，全系统失败处理统一为一种模式 |
| 5 | 提示触发条件 | 实发 **≤ 0**（含产假导致的负数）均提示，清单内区分「异常」与「合法但需知悉」 |
| 6 | 归档防线 | 存在异常警告时归档强制确认；后端现场重查，绕过前端直接调 API 同样被拦截 |
| 7 | 产假等合法 0/负数 | 正常出行、如实显示 0 或负数、正常归档（仅提示，不拦截） |
| 8 | 其他模块 | 先产出逐点清单（文件/字段/改法）交用户过目，确认后只改显示层 |

### 2.1 三类核算结果人员的处置矩阵

| 人员情形 | 工资行 | 清单 | 级别 | 归档影响 |
|----------|--------|------|------|----------|
| 正常核算，实发 > 0 | 出 | 无 | — | 正常 |
| 薪资基数缺失（fixed=0 且 base=0），在职 | **不出** | missing：薪资数据缺失，引导检查钉钉同步/花名册 | danger | 拦截归档，需处理或 force |
| 薪资基数缺失，离职且当月有出勤 | **不出** | missing：离职人员缺薪资基数，引导补录花名册后重算 | danger | 拦截归档，需处理或 force |
| 考勤表中无此人（现有逻辑） | 不出 | missing（维持现有文案） | info | 不拦截 |
| 基数缺失且无考勤 | 不出 | missing 合并双原因 | info | 不拦截 |
| 有出行，实发 = 0（如全月出勤 0） | 出 | warnings：列名 + 出勤天数 | info | 不拦截 |
| 有出行，实发 < 0（如产假社保照扣） | 出 | warnings：列名 + 实发金额 + 出勤天数 | info | 不拦截 |
| 绩效系数/档位缺失（perf_detail.error） | 出（基本工资照发，绩效计 0） | warnings：列名 + 具体缺失项（missing_coef/missing_pay_grade/invalid_pay_grade/missing_pay_rule） | danger | 拦截归档，需处理或 force |

「拦截归档」的异常清单 = danger 级条目；info 级条目仅展示不拦截。

## 3. 技术设计

### 3.1 核算引擎：PayrollCalculator::calculateGroup

文件：`app/Services/PayrollCalculator.php`

在现有 foreach（约 L171-200）内调整：

1. **基数缺失检查前置于 computeRow**，适用范围从「仅离职」扩展为「所有人员」：
   - 条件：`(float)$person->fixed_monthly == 0.0 && (float)$person->base_salary == 0.0`
   - 取当月实际出勤（复用现有 `attendanceStats` + `act_attend` 回退逻辑）
   - 有出勤（actual > 0）：**continue 不出行**，写 missing 条目：
     - 在职：`{name, project, level:'danger', reason:'薪资数据缺失（固定月薪/基本工资均为0），未生成工资行；请检查钉钉花名册同步后重算'}`
     - 离职：`{name, project, level:'danger', reason:'离职人员本月有出勤但固定月薪/基本工资均为0，未生成工资行；请补录花名册薪资并同步后重算'}`
   - 无出勤（actual = 0）：维持「考勤缺人」missing 路径（level info），不重复报薪资缺失。
   - 删除现有 L185-198「离职出行但计 0 + warnings」分支。
2. **行后检查实发 ≤ 0**：computeRow 返回后，取行内实发字段 `net`（row_data 现有字段，见 PayrollCalculator L711；出勤天数字段为 `act_att`）：
   - `net <= 0` → warnings 追加 `{name, project, level:'info', reason: 实发 X 元（出勤 Y 天），请确认是否为产假/停薪等合法情形}`。
3. **perf_detail.error 汇总**：行的 `perf_detail` 中存在 `error` 键（含嵌套 half_year）→ warnings 追加 danger 条目，reason 按错误码映射中文：
   - missing_coef → 「季度/半年度绩效系数未录入」
   - missing_pay_grade → 「薪酬档位缺失」
   - invalid_pay_grade → 「薪酬档位不在专员级/主管级/经理级范围内」
   - missing_pay_rule → 「该档位未配置绩效发放规则」
4. 返回结构增量：missing/warnings 的每个条目增加 `level`（'danger'|'info'）；旧条目补 level。返回数组键名（count/skipped/preserved_archived/missing/warnings）不变，四个入口（员工/管理/案场/总部）的 Controller 透传不变。

### 3.2 归档防线（后端权威）

文件：`app/Http/Controllers/Api/PayrollWriteController.php`（archive 方法；路由 `POST /api/payroll/archive`，routes/api.php L47）

`POST /api/payroll/archive`（locked=true）流程改为：

1. 正常权限/状态检查（维持现有）。
2. 现场重查目标范围当月 danger 异常（范围与 archive 现有分层一致：员工=全部非 manager/case/hq 行，覆盖该月所有已锁定考勤块；manager/case/hq 各自按行类型）：
   - a. 薪资基数缺失：`payroll_staff` 中该范围在职/离职人员，fixed=0 且 base=0，且当月考勤块中有出勤记录（复用 PayrollCalculator 的出勤判定，抽到可共用的私有方法或独立 Guard 服务）。
   - b. 当月结果行 `row_data` 中 `perf_detail.error` 存在。
   - 实发 ≤ 0 属 info，不查不拦截。
3. 异常非空且请求未带 `force=true`：返回 **HTTP 409**：
   ```json
   { "ok": false, "need_confirm": true,
     "blockers": [ {"name":"...", "project":"...", "reason":"..."} ] }
   ```
4. 异常非空且 `force=true`：执行归档（记录操作日志，含 blockers 快照，复用现有归档日志机制；若无则写 Laravel 日志）。
5. 无异常：执行归档，行为与现在完全一致。
6. locked=false（解锁）不检查。

前端 `Payroll.vue` 归档处理（L563/L571 附近）：

- 调 archive 收到 409 need_confirm → 弹 confirm，文案列出 blockers 逐人姓名（项目）+ 原因，末尾「确认仍要归档吗？忽略异常可能导致错误工资发放。」
- 用户确认 → 同请求加 `force: true` 重发；取消 → 不归档。
- 现有「确认归档锁定」普通确认框维持（无异常时照常出现）。

### 3.3 工资表显示层

文件：`frontend/src/modules/hr/Payroll.vue`、`payrollLogic.js`

- 实发 ≤ 0 的行：`<tr>` 加 class（如 `row-zero-pay`），橙色系背景；实发单元格 `<td>` 加 title 属性悬停提示（出勤天数，取 row_data）。
- 核算完成提示（L502、L551 附近）：按 level 分组渲染——🚨 danger（缺基数/缺系数档位）、ℹ️ info（0/负数、考勤缺人），逐人 `姓名(项目)` 列名；danger 存在时提示框用 error 样式。
- 「数据缺失者无行」属正常表现，不额外在表格中插占位行（占位行会让导出/统计复杂化，违背 §1 目标）。

### 3.4 Excel 导出与工资条

- 导出：0 与负数如实导出（现有行为不变）；缺失者无行故不出现。不新增列、不改版式。
- 工资条 H5（payslip）：无行人员查不到工资条（现有行为）；0/负数工资条如实显示。不改动。

### 3.5 其他模块显示层（清单先行，确认后实施）

甄别规则（仅改满足全部条件的点）：

1. 后端返回的业务数据字段值为 null/undefined/空串；
2. 当前被 `?? 0`、`|| 0`、`Number(x)||0` 等渲染成数字 0；
3. 该位置是**数据展示**（表格单元格、详情、只读文本）。

明确不改：

- 分页页码/条数、计数器、合计汇总的逻辑默认 0；
- 表单输入框的默认值（v-model 初始值）；
- echarts 等图表 series 初始化；
- 任何参与前端计算的默认值（计算口径属后端，前端默认值改动需逐例评估，不在本次）。

改法：抽公共格式化函数（如 `fmtEmpty(v)`：null/undefined/'' → '—'，其余原样返回；金额字段走现有金额格式化后包一层空值判断），替换甄别命中的显示点。实施时产出清单表（模块/文件/字段/现状/改法）交用户确认，未确认不改。

### 3.6 错误与边界

- 考勤块整体缺失：维持现有 `skipped:['no_attendance']` 安全护栏（不动已有结果），与本次无关。
- 历史导入行（archived=true）：归档保全逻辑不变；本次缺失判定只作用于本次新核算人员。
- fixed/base 一者为 0 一者非 0：视为**有基数**正常核算（基本工资或固定月薪单项为 0 可能是真实薪酬结构），不拦不提示。
- 判定 0 用松散 `== 0.0`（数据库 decimal 返回字符串，沿用现有 L188 写法）。

## 4. 数据结构

不新增表、不加列、不加迁移。

- missing/warnings 条目：`{name, project, reason, level}`（level 新增，均为字符串）。
- archive 请求体新增可选 `force: bool`。
- 409 响应体：`{ok:false, need_confirm:true, blockers:[...]}`。

## 5. 测试计划（TDD）

### 后端 PHPUnit（新增/扩展 Feature 测试）

1. 在职人员 fixed=0/base=0 且有出勤 → 不生成 payroll_results 行，missing 含此人 level=danger。
2. 离职人员同上 → 不生成行，missing 文案为离职版（替换现有离职 0 工资单测试的断言）。
3. 基数缺失但无考勤 → missing level=info，不出行。
4. fixed/base 仅一项为 0 → 正常出行。
5. 产假型：有基数、出勤 0、社保照扣导致 net_pay=0 / <0 → 出行，warnings 含 info 条目，无 danger。
6. perf_detail 各类 error（missing_coef/missing_pay_grade/invalid_pay_grade/missing_pay_rule）→ 出行，warnings 含对应中文 danger 条目。
7. 归档：存在 danger → 首次 409 need_confirm；带 force=true → 归档成功；无 danger → 直接成功。
8. 归档范围：员工按项目、管理/案场/总部各自的 blockers 不串范围。
9. 回归：现有 quarter_grade / 归档保全 / 考勤缺人测试全绿。

### 前端 Vitest

1. 实发 ≤ 0 行带高亮 class，>0 行不带。
2. 核算提示按 level 分组渲染（🚨/ℹ️）。
3. 归档 409 → 弹确认 → 确认重发带 force；取消不重发。
4. `fmtEmpty`：null/undefined/'' → '—'；0 → 0（注意：真实 0 不转 —）；'abc' → 'abc'。

全量回归：PHPUnit 全绿（当前 139 测试基线）、Vitest 全绿（223 测试基线）、前端 build 通过。

## 6. 限制与风险

- **无法区分「真实 0」与「缺失 0」**：`fixed_monthly`/`base_salary` 为 NOT NULL default 0，空值在数据库层就是 0。依据：物业公司不存在月薪为 0 的在职/离职有出勤人员，且与已上线离职方案同一判定口径。若未来出现合法 0 薪资人员（如纯劳务零基薪），需改为 nullable 列 + 迁移，本次不做，在此显式记录。
- **行为变化**：已上线离职 0 基数方案从「0 工资单」变「无行」；10 月核算前若生产已存在该类结果行，重算后行会消失（数据本身错误，消失是预期）。
- **force 归档**是有意保留的逃生口（如确认线下已处理），操作留日志。
- 归档时重查出勤判定须与核算引擎同源，避免「核算说缺失、归档说正常」或反之；实现时共用同一判定方法。
- 前端 165 处 `?? 0` 不全改，仅改清单确认后的显示点，防止误伤逻辑默认值。

## 7. 上线与部署

- 与已推送未部署的钉钉防御修复（e5eebcf）一并部署，走项目部署 skill（`.trae/skills/laravel-prod-deploy/`）。
- 无数据库迁移。
- 部署后冒烟：核算一次测试月份验证 missing/warnings/level；验证归档 409 与 force；验证工资表高亮；检查不影响历史归档月查看。
- 活文档：完成后在 `laravel-app/docs/项目开发文档.md` 第八节追加开发记录，登记 archive 接口变更（force 参数、409）到第五节接口清单。

## 8. 实施顺序概览（详细计划由 writing-plans 产出）

1. 后端：缺失判定抽公共方法 + calculateGroup 改造 + 测试
2. 后端：实发 ≤0 / perf error 汇总 + 测试
3. 后端：归档 409/force + 测试
4. 前端：工资表高亮 + 提示分组 + 归档确认流 + Vitest
5. 前端：其他模块空值点扫描清单 → 用户确认 → fmtEmpty 替换 + Vitest
6. 全量回归、活文档、commit/push、部署
