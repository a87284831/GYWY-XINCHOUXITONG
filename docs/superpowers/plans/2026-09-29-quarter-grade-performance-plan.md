# 季度绩效法（quarter_grade）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增第三种绩效发放方式「季度绩效法」（cycle=`quarter_grade`）：管理/总部人员按薪酬档位决定发放节奏，经理级季度末全额累计发放（无比例、无半年度），主管/专员级每月全额发放。

**Architecture:** 复用现有季度累计链路（quarterPeriod/accumulatePeriodPerf/periodCoef/perf_detail），在 CalcRules::getPayRule 增加 quarter_grade 分支、PayrollCalculator 绩效覆盖段增加并列分支；不动 monthly/quarterly 一行。前端设置页增加第三个 cycle 选项及按档单选 UI；系数弹窗已由接口 half_period 字段驱动，后端返回 null 即自动隐藏半年度列，前端零改动。

**Tech Stack:** PHP 8.3 / Laravel 12（PHPUnit, RefreshDatabase）、Vue 3 + Vite（vitest）、SQLite 本地库

**Spec:** `docs/superpowers/specs/2026-09-29-quarter-grade-performance-design.md`

## Global Constraints

- 内部 cycle 枚举：`monthly` | `quarterly` | `quarter_grade`；展示名分别为 月度绩效法 / 分期兑现绩效法 / 季度绩效法。
- quarter_grade 档位 mode 枚举：`quarter` | `monthly`；默认 经理级=quarter，主管级/专员级=monthly。
- quarter_grade **无任何比例参数、无半年度**；季度型公式 perf_pay = round(季度系数 × Σ(月绩效基数 × perf_att/req_att), 2)。
- 月绩效基数 = fixed_monthly − base_salary（与现有 accumulatePeriodPerf 一致，不含月度 coef）。
- 缺档位 missing_pay_grade、档位不在 CalcRules::PAY_GRADES（专员级/主管级/经理级）→ invalid_pay_grade；季度型缺季度系数 → missing_coef；均计 0 显式标错，禁止静默兜底。
- 月度型人员不查季度系数、不进系数录入名单、不报 missing_coef；当月绩效走常规段 (fixed−base)×coef×出勤/应出勤。
- monthly / quarterly 旧逻辑与旧配置结构字节级保留；无数据库迁移。
- 金额断言精确到分；每个 Task 跑相关测试并 commit；提交排除 .env* 与根目录 xlsx。
- PHP 可执行文件：`C:\Users\87284\.local\lib\php-8.3\php.exe`；node：`C:\Users\87284\.local\lib\nodejs\node-v22.14.0-win-x64\node.exe`。

## Review Focus

1. **季度末月月度型人员**：4 月主管级当月绩效必须保留常规段结果（基数×出勤×coef），不能被季度分支置 0，也不能生成 perf_detail —— Task 1 用例 `test_monthly_grade_pays_current_month_even_at_quarter_end` 锁定。
2. **季度型季度末月"当月"绩效归属**：4 月发的是 Q1（1-3月），4 月当月绩效随 Q2 在 7 月发，4 月 perf_pay 不含 4 月考勤 —— 现有季度分支语义如此，Task 1 用例的 seedHistory（仅 1-3 月）天然锁定。
3. **档位类型切换后重算风险**：设置页保存 quarter_grade 只写 levels.<档>.mode；切回 quarterly 时旧 quarter_ratio/half_year_ratio 需重新填写（配置被覆盖，属预期，spec 第8节建议季度初切换）—— Task 3 保存逻辑加注释，UI 加切换确认提示。
4. **月度型库中残留季度系数**：即便 payroll_period_coefs 有该人旧系数，月度型核算也不得读取/报错 —— Task 1 用例 `test_monthly_grade_ignores_legacy_quarter_coef` 锁定。
5. **季中入/离职**：accumulatePeriodPerf 只累计存在的历史行，缺月份不补 0 也不报错 —— Task 1 用例 `test_quarter_grade_mid_quarter_hire_only_accumulates_active_months` 锁定。

---

### Task 1: 后端核算支持 quarter_grade（CalcRules + PayrollCalculator）

**Files:**
- Modify: `app/Services/CalcRules.php`（getPayRule，约 L149-L177）
- Modify: `app/Services/PayrollCalculator.php`（绩效覆盖段 L409-L475 旁新增并列分支）
- Test: `tests/Feature/PayrollQuarterGradeTest.php`（新建，setUp 模式复制 `tests/Feature/PayrollQuarterlyTest.php` 的 seedRules/seedManager/seedHistory/seedCoef/calcManager）

**Interfaces:**
- Produces:
  - `CalcRules::getPayRule(string $personType, string $payGrade = ''): array` 新增返回形态：
    `['cycle'=>'quarter_grade','mode'=>'quarter'|'monthly','configured'=>bool]`（configured=false 时 mode 给 'monthly'，由调用方按档位错误标记）。
  - 季度型 perf_detail 结构：`['period'=>'2026-Q1','type'=>'quarterly','pay_grade'=>'经理级','coef'=>float,'months'=>[...]]`，**无 ratio、无 half_year 键**。

- [ ] **Step 1: 写失败测试 `tests/Feature/PayrollQuarterGradeTest.php`**

seedRules 中 pay_rules：
```php
'manager' => ['cycle' => 'quarter_grade', 'levels' => [
    '经理级' => ['mode' => 'quarter'],
    '主管级' => ['mode' => 'monthly'],
    '专员级' => ['mode' => 'monthly'],
]],
```
seedManager 默认 fixed=6000/base=5000（月绩效基数 1000），与 Quarterly 测试同构。用例：

```php
public function test_quarter_grade_quarter_type_mid_months_zero(): void
// 经理级，3月核算：perf_pay=0.0，无 perf_detail

public function test_quarter_grade_quarter_type_pays_q1_full_without_ratio(): void
// seedHistory 1-3月（各 1000）+ coef 2026-Q1=0.9；4月核算
// perf_pay=2700.0（3000×0.9，无比例）；perf_detail.period='2026-Q1'，
// type='quarterly'，无 ratio 键、无 half_year 键，months 为 1/2/3 月

public function test_quarter_grade_quarter_type_july_no_half_year(): void
// seedHistory 1-6月 + coef 2026-Q2=1.0（故意不录 half_year）；7月核算
// perf_pay=3000.0（仅 Q2），perf_detail 无 half_year 键

public function test_quarter_grade_quarter_missing_coef_marks_error(): void
// 经理级 1-3月历史，不录系数；4月 perf_pay=0，perf_detail.error='missing_coef'

public function test_quarter_grade_missing_and_invalid_grade_mark_error(): void
// 两个人员：pay_grade='' → missing_pay_grade；pay_grade='总经理级' → invalid_pay_grade；4月均 0

public function test_quarter_grade_monthly_type_pays_every_month_with_coef(): void
// 主管级：calcManager('2026-03')，考勤 days 全 √ 22天、coef 传 0.9（seedAttendance rows.coef=0.9）
// 断言 perf_pay = 1000×(22/22)×0.9 = 900.0；无 perf_detail

public function test_monthly_grade_pays_current_month_even_at_quarter_end(): void
// 主管级，4月核算，不种 1-3月历史、不录季度系数
// perf_pay=当月常规绩效（满勤 coef1.0 → 1000.0），无 perf_detail，不报错

public function test_monthly_grade_ignores_legacy_quarter_coef(): void
// 主管级，4月核算，库中故意存在其 2026-Q1 系数行：结果仍为当月月度绩效，perf_detail 不存在

public function test_quarter_grade_mid_quarter_hire_only_accumulates_active_months(): void
// 经理级 hire_date=2026-02-10；seedHistory 仅 2/3月（各1000）+ coef Q1=1.0
// 4月 perf_pay=2000.0，months 仅 2026-02/2026-03
```
注：月度型用例需在 calcManager 注入考勤时把 `coef` 从 null 改为 0.9（可给测试辅助方法加可选参数 `$coef=null`）。

- [ ] **Step 2: 运行测试确认失败**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor/bin/phpunit tests/Feature/PayrollQuarterGradeTest.php`
Expected: FAIL（quarter_grade 被当成缺省 monthly 或断言不通过）

- [ ] **Step 3: CalcRules::getPayRule 增加 quarter_grade 分支**

在 monthly/quarterly 分支之间增加：cycle==='quarter_grade' 时读 `$rule['levels'][$payGrade]['mode']`；payGrade==='' → `['cycle'=>'quarter_grade','mode'=>'monthly','configured'=>false]`；档位不在 PAY_GRADES → configured=false；命中 `in_array($mode,['quarter','monthly'],true)` → 带 mode 与 configured=true 返回，mode 非法按 'monthly'。

- [ ] **Step 4: PayrollCalculator 增加 quarter_grade 覆盖分支**

在 L415 `if (...=== 'quarterly')` 之后增加 `elseif (($payRule['cycle'] ?? '') === 'quarter_grade')`：
- 档位为空/不在三档：季度末月 perf_detail=['error'=>missing|invalid,...]，非末月 perf_pay=0（与 quarterly 同位置维护 $gradeError）。
- mode==='quarter'：复刻 quarterly 季度末逻辑（quarterPeriod + accumulatePeriodPerf + periodCoef quarterly），perf_pay=round($qBase*$qCoef,2)，perf_detail 不含 ratio/half_year；**删除半年度块**；非季度末月 perf_pay=0。
- mode==='monthly'：什么都不做（保留 L380 常规段 perfPay，无 perf_detail）。

- [ ] **Step 5: 跑新测试 + 旧季度/个税回归**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor/bin/phpunit tests/Feature/PayrollQuarterGradeTest.php tests/Feature/PayrollQuarterlyTest.php tests/Feature/PayrollTaxTest.php`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/CalcRules.php app/Services/PayrollCalculator.php tests/Feature/PayrollQuarterGradeTest.php
git commit -m "feat(payroll): 新增季度绩效法 quarter_grade（按档位季度/月度发放）"
```

---

### Task 2: 季度系数录入名单接口适配 quarter_grade

**Files:**
- Modify: `app/Http/Controllers/Api/PayrollController.php`（pendingPeriodCoef L240-L295）
- Test: `tests/Feature/PayrollPeriodCoefApiTest.php`（扩展）

**Interfaces:**
- Consumes: `CalcRules::getPayRule($personType, $payGrade)`（Task 1）。
- Produces: quarter_grade 模式响应 `half_period=null`、`items` 仅含 mode=quarter 的档位人员；manager/hq 各自按自身 pay_rules.cycle 判定（两类人混在一次名单里时分别过滤）。

- [ ] **Step 1: 写失败测试（追加到 PayrollPeriodCoefApiTest）**

```php
public function test_pending_coef_quarter_grade_lists_only_quarter_modes_and_no_half_period(): void
// pay_rules.manager.cycle=quarter_grade（经理级 quarter/主管级 monthly）；
// 两名 manager：经理级张三、主管级李四；GET /api/payroll/period-coef/pending?ym=2026-04
// 断言 ok、half_period===null、items 仅张三（staff_legacy_id 断言），李四不在名单
```
HEADERS 与登录 token 沿用该测试文件既有 setUp。

- [ ] **Step 2: 运行确认失败**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor/bin/phpunit tests/Feature/PayrollPeriodCoefApiTest.php`
Expected: 新用例 FAIL（当前名单含李四、7月/1月 half_period 非空逻辑也需按 cycle 区分）

- [ ] **Step 3: 改造 pendingPeriodCoef**

- 注入 `app(CalcRules::class)`；对每个人员按其 person_type（manager/hq）取 getPayRule。
- cycle==='quarter_grade'：仅保留 configured 且 mode==='quarter' 者；该 personType 的半年度标识置 null。
- cycle==='quarterly'：保持现状（全员、含 half_period）。
- $hKey 计算按 personType 区分：仅当存在 quarterly 类人员时才返回 half_period；纯 quarter_grade 时 half_period=null。
- items 中 half_coef 字段：quarter_grade 下统一不返回（或 null）。

- [ ] **Step 4: 运行该测试文件全部用例 PASS**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor/bin/phpunit tests/Feature/PayrollPeriodCoefApiTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PayrollController.php tests/Feature/PayrollPeriodCoefApiTest.php
git commit -m "feat(payroll): 季度系数录入名单按 quarter_grade 档位过滤，无半年度"
```

---

### Task 3: 前端薪酬设置页支持 quarter_grade

**Files:**
- Modify: `frontend/src/modules/settings/SalarySettings.vue`（模板 L55-L72、脚本 L220-L225/L262-L273/L402-L417）

**Interfaces:**
- 前端 reactive 结构扩展为：
  `payRules.mgr = { cycle: 'monthly'|'quarterly'|'quarter_grade', ratios: {<档>:{quarter_ratio,half_year_ratio}}, modes: {经理级:'quarter', 主管级:'monthly', 专员级:'monthly'} }`
- 保存 payload（quarter_grade）：`{cycle:'quarter_grade', levels: {经理级:{mode:'quarter'}, 主管级:{mode:'monthly'}, 专员级:{mode:'monthly'}}`。

- [ ] **Step 1: 写前端失败测试（新建 `frontend/src/modules/settings/salaryPayRules.test.js`，若 vitest 配置无法载入 .vue 则测纯函数：把 init/save 的 pay_rules 映射抽到 settingsLogic.js）**

首选：把 pay_rules 的"读映射 normalizePayRules(serverR) → {cycle,ratios,modes}"与"写映射 buildPayRulesSection(payRules[t]) → 配置片段"抽为 `settingsLogic.js` 纯函数并单测：
```js
describe('quarter_grade pay_rules 映射', () => {
  it('读：cycle=quarter_grade 还原各档 mode，缺省 monthly', ...)
  it('写：quarter_grade 只输出 levels.<档>.mode，不带任何 ratio', ...)
  it('写：quarterly 仍输出 quarter_ratio/half_year_ratio（回归）', ...)
  it('写：monthly 输出 {cycle,ratio:1}（回归）', ...)
})
```

- [ ] **Step 2: 运行确认失败**

Run: `& '<node>' frontend/node_modules/vitest/vitest.mjs run src/modules/settings/salaryPayRules.test.js`
Expected: FAIL（模块/函数不存在）

- [ ] **Step 3: settingsLogic.js 增加两个纯函数，SalarySettings.vue 接入**

- emptyGradeModes()：`{经理级:'quarter',主管级:'monthly',专员级:'monthly'}`；payRules 初值加 modes。
- 下拉增加 `<option value="quarter_grade">季度绩效法</option>`。
- 新增 `v-else-if="payRules[t].cycle === 'quarter_grade'"` 模板块：三档表格列为「薪酬档位 / 发放类型（单选：季度发放 value=quarter、月度发放 value=monthly）」，无数字输入框；顶部 hint 文案：「按档位决定发放节奏：季度发放档位季度中月份不发绩效、季度末月按季度系数全额累计发放；月度发放档位每月按当月绩效系数全额发放。切换类型对已核算月份不追溯，建议在季度初（1/4/7/10月）切换。」
- initFm 用 normalizePayRules 读取（quarter_grade 还原 modes，其他 cycle 维持 ratios）。
- 保存用 buildPayRulesSection；保存全部薪酬设置已有的确认弹窗逻辑保持不变，在用户改了任一 mode 与当前服务端值不同时点保存时，confirm 文案追加：「你修改了档位发放类型，变更只影响之后的核算、不追溯已核算月份，确定保存？」

- [ ] **Step 4: 跑前端全量测试**

Run: `& '<node>' frontend/node_modules/vitest/vitest.mjs run`
Expected: 全部 PASS（当前 214 + 新增用例）

- [ ] **Step 5: 构建前端**

Run: `& '<node>' frontend/node_modules/vite/bin/vite.js build`
Expected: ✓ built

- [ ] **Step 6: Commit（含 public/app 构建产物）**

```bash
git add frontend/src public/app
git commit -m "feat(settings): 薪酬设置新增季度绩效法选项与按档发放类型单选"
```

---

### Task 4: 本地配置骨架与端到端验证

**Files:**
- Modify: `database/seed_local_configs.php`（如其中维护 pay_rules 骨架）与本地 `data/calc_rules.json`、库 `legacy_json_snapshots` 对应行（三处内容以库为准，文件同步）

- [ ] **Step 1: 本地 pay_rules 写入 quarter_grade 骨架**

manager/hq：`{cycle:'quarter_grade', levels:{经理级:{mode:'quarter'},主管级:{mode:'monthly'},专员级:{mode:'monthly'}}}`（仅当当前骨架非用户已配置的 quarterly 比例时；若已存在真实比例配置则保留并备份原片段到 storage 后再改）。同步更新 data/calc_rules.json 与库行。

- [ ] **Step 2: 后端全量测试**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor/bin/phpunit`
Expected: 全绿（原 79 个 + 本计划新增）

- [ ] **Step 3: 端到端手工验证（8899）**

启动 PHP 8910 + Node 8899；浏览器 Ctrl+F5：
1. 系统设置→薪酬设置：选「季度绩效法」，三档单选出现、无比例框；切到分期兑现绩效法时旧比例表格出现；保存后刷新配置持久。
2. 薪酬计算→管理人员工资表→2026-04：系数录入弹窗只有季度型档位人员、无半年度系数列。
3. 造一名经理级（pay_grade=经理级）1-3 月考勤并核算（或经导入历史行），4 月录系数 0.9 后核算，绩效=Σ(基数×出勤)×0.9，横向明细列为 Q1·1/2/3月；主管级当月正常发月度绩效、无空报错。
4. 导出 4 月 Excel：季度型有逐月列、无半年度列。

- [ ] **Step 4: Commit**

```bash
git add database/seed_local_configs.php data/calc_rules.json
git commit -m "chore: 本地薪酬配置骨架切换为 quarter_grade"
```

## Self-Review 记录

- Spec 覆盖：§2.1/§2.2 公式→Task1；§2.3 数字走查→Task1 用例（2700/900 等断言同构）；§3 配置→Task1 读取+Task3 写入；§4.1→Task1 Step3；§4.2→Task1 Step4；§4.3→Task2；§4.4→Task3（弹窗零改动已核实 half_period 驱动）；§4.5 导出→Task4 Step3 回归；§5 错误矩阵→Task1/2；§6 测试 1-8→Task1（1-6）、Task2（7）、旧回归（8，每 Task 跑）；§7→Task4。
- 类型一致性：mode/quarter_grade/configured/half_period 在各 Task 间命名一致。
- 比例：无大段代码转录，步骤以签名+断言为主。
