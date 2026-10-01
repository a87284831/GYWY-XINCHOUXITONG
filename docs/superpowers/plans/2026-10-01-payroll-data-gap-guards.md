# 薪资核算数据缺失护栏 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 核算时薪资基数缺失者跳过不出行并显式提示、实发 ≤0/绩效配置缺失汇总警告、归档时异常强制确认；其他模块空值显示层按确认清单统一。

**Architecture:** 后端在 PayrollCalculator 内新增静态判定方法（基数缺失/实际出勤/perf_detail 错误码），calculateGroup 循环改为「缺失即 continue + missing(danger)」，行后汇总 warnings(level=danger/info)；PayrollWriteController::archive 新增现场 blocker 检查，异常返回 409，force=true 放行。前端抽纯函数（isZeroPayRow/groupNotices/archiveBlockerText）供 Vitest 覆盖，Payroll.vue 接线行高亮、分级提示、归档二次确认；api client 抛错附带 status/payload。

**Tech Stack:** Laravel 11 / PHPUnit（:memory: sqlite）；Vue 3 + Vitest（无 @vue/test-utils，前端逻辑一律抽纯函数测试）

**Spec:** `docs/superpowers/specs/2026-10-01-payroll-data-gap-guards-design.md`（计划与 spec 配套，执行者两份都读）

## Global Constraints

- PHP ^8.2；本地 PHP 路径 `C:\Users\87284\.local\lib\php-8.3\php.exe`；后端测试命令（cwd=laravel-app）：`& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor\bin\phpunit`
- 前端（cwd=laravel-app/frontend，PowerShell 用 npm.cmd）：`npm.cmd run test`、`npm.cmd run build`
- 不新增表/列/迁移；不改动任何薪酬计算公式
- 0 判定一律松散比较 `== 0.0`（数据库 decimal 返回字符串，沿用现有写法）
- 提示文案中文；条目结构统一 `{name, project, reason, level}`，level 仅 `'danger'|'info'`
- 实发字段名为 `net`，出勤字段 `act_att`；归档路由 `POST /api/payroll/archive`（PayrollWriteController::archive），type 取值 `manager`/`case`/`hq`/缺省(员工)
- 每 Task 结束 commit；活文档 `docs/项目开发文档.md` 第八节在 Task 7 追加（commit 前完成）
- 不部署：部署由用户另行指令，走 `.trae/skills/laravel-prod-deploy/`

## Review Focus

1. **归档范围不串**：员工归档的 blockers 不得含管理/案场/总部行，反之亦然（按 is_manager_row/is_case_row/is_hq_row 过滤，与 archive 现有分层完全一致）。Task 3 测试。
2. **force 仅当次有效**：同一归档不带 force 永远 409；force=true 成功后不产生任何持久化豁免状态。Task 3 测试。
3. **net 边界**：net 恰好 0 与负数都进 info warnings；正数（含 0.01）不进。Task 2 测试。
4. **历史导入行不被误报**：archived=true 的历史行 row_data 无 perf_detail 键，归档 blocker 扫描不得将其判为 perf error。Task 3 测试。
5. **旧条目前向兼容**：缺失 level 键的 missing/warnings 条目前端按 info 兜底渲染，不抛错。Task 4 测试。

---

### Task 1: 基数缺失判定 + 核算跳过不出行

**Files:**
- Modify: `app/Services/PayrollCalculator.php`（calculateGroup L170-200；新增静态方法）
- Modify: `tests/Feature/PayrollResignGuardTest.php`（行为变更，改断言）
- Create: `tests/Feature/PayrollDataGapGuardTest.php`

**Interfaces:**
- Produces（后续 Task/Controller 依赖）:
  - `PayrollCalculator::hasMissingBase(object $person): bool` — `(float)fixed_monthly == 0.0 && (float)base_salary == 0.0`
  - `PayrollCalculator::actualAttendance(array $att, array $symbols): float` — `(float)($att['act_attend'] ?? 0)`，≤0 时回退 `attendanceStats($att['days'] ?? [], $symbols)['attend']`
  - missing 条目：`['name'=>..., 'project'=>..., 'reason'=>..., 'level'=>'danger'|'info']`

- [ ] **Step 1: 改写离职护栏测试为新行为（先让它失败）**

`tests/Feature/PayrollResignGuardTest.php`：
- `test_resigned_with_attendance_but_zero_base_produces_warning` 改名为 `test_resigned_with_attendance_but_zero_base_is_skipped_to_missing`：断言 `count === 0`；`missing` 非空且第一条 `name==='张传彩'`、`level==='danger'`、reason 含「钉钉花名册」；`assertArrayNotHasKey(1, $r['rows'])`；`warnings === []`。
- `test_resigned_zero_base_but_no_actual_attendance_has_no_warning`：补充断言该人在 missing 中且 `level==='info'`（考勤缺人路径）。
- `test_active_staff_zero_base_is_out_of_guard_scope` 改名为 `test_active_staff_zero_base_with_attendance_is_skipped_to_missing`：断言 count=0、missing 含赵六、level=danger、reason 含「钉钉」（在职文案引导检查同步/花名册，不出现「离职」字样）。
- `test_resigned_with_normal_base_has_no_warning` 不变。

- [ ] **Step 2: 新增缺失判定专项测试**

`tests/Feature/PayrollDataGapGuardTest.php`（复用 ResignGuard 的 seedRules/symbols/seedStaff/makeAtt/calc 模式，可继承该类或复制夹具；YM 用 2026-07）：
- 在职 fixed=5000/base=0（单项为 0）+ 有出勤 → count=1、missing=[]、warnings=[]、rows 有此人。
- 0/0 人员与正常人员混合同一项目 → count=1（仅正常人出行），missing 1 条 danger。
- 0/0 人员考勤行不存在（calc 的 rows 参数不含此人）→ missing 1 条 info，reason 为现有「无考勤记录」文案，不含「薪资数据缺失」。

- [ ] **Step 3: 运行新测试确认失败**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor\bin\phpunit --filter 'PayrollResignGuardTest|PayrollDataGapGuardTest'`
Expected: FAIL（在职 0/0 仍出行；离职仍 count=1）

- [ ] **Step 4: 实现静态方法与 calculateGroup 改造**

`PayrollCalculator.php`：
- 新增 `public static function hasMissingBase(object $person): bool` 与 `public static function actualAttendance(array $att, array $symbols): float`（逻辑见 Interfaces，attendanceStats 已是 public static）。
- L171 foreach 内：`$att` 解析后，先算 `$actual = self::actualAttendance($att, $symbols)`：
  - `self::hasMissingBase($person)` 且 `$actual > 0` → missing 追加 danger 条目后 **continue**（不 computeRow）。在职 reason：`薪资数据缺失（固定月薪/基本工资均为0），未生成工资行；请检查钉钉花名册同步后重算`；离职 reason：`离职人员本月有出勤{N}天但固定月薪/基本工资均为0，未生成工资行；请补录钉钉花名册薪资并同步后重算`（{N} 用 $actual）。
  - 删除现有 L185-198 离职专属 warnings 分支。
- 现有「考勤行不存在」missing 条目（L177）补 `'level' => 'info'`。
- 注意：0/0 且无考勤的人先命中「无考勤行」分支（att 为 null → continue info），不会重复报缺失，无需额外交互。

- [ ] **Step 5: 运行目标测试确认通过，再全量回归**

Run: `& 'C:\Users\87284\.local\lib\php-8.3\php.exe' vendor\bin\phpunit`
Expected: 全绿（现有离职测试断言已同步修改；若 quarter grade 等其他测试断言 warnings/missing 结构，按新 level 字段修正）

- [ ] **Step 6: Commit**

`feat(payroll): 薪资基数缺失人员跳过核算并进 danger 清单`

---

### Task 2: 行级 warnings（实发 ≤0 info、perf error danger）

**Files:**
- Modify: `app/Services/PayrollCalculator.php`（calculateGroup 循环行后检查；新增错误码映射）
- Test: `tests/Feature/PayrollDataGapGuardTest.php`

**Interfaces:**
- Produces:
  - `PayrollCalculator::PERF_ERROR_CN: array<string,string>`（missing_coef→季度/半年度绩效系数未录入；missing_pay_grade→薪酬档位缺失；invalid_pay_grade→薪酬档位不在专员级/主管级/经理级范围内；missing_pay_rule→该档位未配置绩效发放规则）
  - `PayrollCalculator::perfErrorCode(array $rowData): ?string` — 检查 `$rowData['perf_detail']`，支持顶层 error 与嵌套 `half_year.error`；无 error 返回 null；perf_detail 不存在返回 null
  - warnings 条目：`{name, project, reason, level}`

- [ ] **Step 1: 写失败测试**（加到 PayrollDataGapGuardTest）
- 正常基数人员全月公休（act_attend=0、无社保）→ 出行，warnings 含 1 条 level=info，reason 含「实发」与「出勤」。
- 构造 net<0：makeAtt 的 days 全公休，并在 att 中设 `pen/med/une/house/big` 之和 > gross（如各项 200）→ 出行 net<0，warnings info，reason 含实发负数金额。
- perf error：参照 `tests/Feature/PayrollQuarterGradeTest.php` 中产生 missing_coef 的最小夹具（经理级+季度末月+不录系数），断言出行且 warnings 含 danger、reason 含「系数」。
- perf_detail 无 error 的正常人 → 无 danger 警告。

- [ ] **Step 2: 运行确认失败**：phpunit --filter PayrollDataGapGuardTest → FAIL（warnings 为空）

- [ ] **Step 3: 实现**
- 新增 PERF_ERROR_CN 常量与 `public static function perfErrorCode(array $rowData): ?string`（先取顶层 `$rowData['perf_detail']['error']`，再取 `$rowData['perf_detail']['half_year']['error']`；顶层优先）。
- calculateGroup 内 `$row = $this->computeRow(...)` 之后、`$rows[]` 之前：
  - `(float)($row['net'] ?? 0) <= 0` → warnings info：`实发{net}元（出勤{act_att}天），请确认是否为产假/停薪等合法情形`。
  - `$code = self::perfErrorCode($row); $code !== null` → warnings danger：`绩效工资未计入：{PERF_ERROR_CN[$code] ?? $code}，请补录后重算`。
  - 同一条行可能两条 warning 都产生（允许）。

- [ ] **Step 4: 全量回归**：phpunit 全绿

- [ ] **Step 5: Commit**：`feat(payroll): 实发≤0与绩效配置缺失汇总分级警告`

---

### Task 3: 归档 409/force 防线

**Files:**
- Modify: `app/Services/PayrollCalculator.php`（新增 archiveBlockers）
- Modify: `app/Http/Controllers/Api/PayrollWriteController.php`（archive L128-149）
- Create: `tests/Feature/PayrollArchiveGuardTest.php`

**Interfaces:**
- Produces:
  - `PayrollCalculator::archiveBlockers(string $ym, string $scope): array` — scope ∈ `staff|manager|case|hq`；返回 `[['name','project','reason','kind'], ...]`，kind ∈ `missing_base|perf_error`；kind 供测试断言，reason 供前端展示
  - archive 请求体新增可选 `force: bool`
  - 409 响应：`{ok:false, need_confirm:true, blockers:[...]}`

- [ ] **Step 1: 写失败测试**（`PayrollArchiveGuardTest.php`，X-Token 认证夹具照抄 `tests/Feature/PayrollPeriodCoefApiTest.php` 的 setUp/token 模式）
- 员工归档：当月 1 名 0/0 有出勤人员（先调 calculate 落库前的数据状态：此人无 results 行，但 staff+attendance 在位）→ `postJson('/api/payroll/archive', ['ym','locked'=>true], ['X-Token'])` 断言 409、`need_confirm===true`、blockers 含此人 kind=missing_base。
- 同请求加 `force=>true` → 200 ok；payroll_results 中既有行 archived=1（另放一名正常人员有出行）。
- 无任何异常（仅正常人员）→ 直接 200。
- 范围隔离：manager scope 归档（type=manager）的 blockers 不含员工表 0/0 人员；反之员工归档不含 manager 的 perf error 行。
- perf blocker：构造一条 results 行 row_data.perf_detail.error=missing_coef（is_manager_row=1）→ type=manager 归档 409，kind=perf_error；同库一条 `archived=true` 且无 perf_detail 键的历史行 → blockers 不含它。
- locked=false（解锁）→ 即使有异常也 200，不检查。

- [ ] **Step 2: 运行确认失败**（archive 当前恒 200）

- [ ] **Step 3: 实现 `archiveBlockers`**（PayrollCalculator）
- 入参 scope→flags 映射同 calculateGroup；attBlocks 取 `where('year_month',$ym)->where('locked',true)`（员工不限项目，覆盖全部已锁定块）；catMap 用 self::categoryMap($ym)；staff 过滤同 calculateGroup。
- missing_base：逐人 `$block = $attBlocks[$isHq?'物业总部':$person->project_name] ?? null` → findAttRow 匹配（该方法当前是 private 实例方法、无状态：改为 `public function findAttRow(...)` 供本类内部调用处不变）→ `hasMissingBase && actualAttendance(...) > 0` → blocker，reason 复用 Task 1 文案（在职/离职两版）。
- perf_error：查 `payroll_results` where year_month 且按 scope 行类型过滤（与 archive 的 update 条件同一组 where），逐条解 row_data，perfErrorCode 非 null → blocker（name/project 取 row_data）。

- [ ] **Step 4: Controller 接线**（PayrollWriteController::archive）
- 在 `$query->update(...)` 之前：仅当 `$locked === true`：
  - `$scope = match($request->input('type')) { 'manager'=>'manager', 'case'=>'case', 'hq'=>'hq', default=>'staff' };`
  - `$blockers = $calc->archiveBlockers($ym, $scope)`（方法注入 PayrollCalculator，参照同文件 calculate 方法签名）
  - blockers 非空且非 `(bool)$request->input('force')` → `response()->json(['ok'=>false,'need_confirm'=>true,'blockers'=>$blockers], 409)`
  - force 放行：`Log::warning('payroll.archive.forced', ['ym'=>$ym,'scope'=>$scope,'blockers'=>count($blockers),'account'=>$account->account ?? $account->id ?? ''])`（account 字段名实施时按现有对象取，无可用标识则省略）
- 响应头/其他路径行为不变。

- [ ] **Step 5: 全量回归**：phpunit 全绿

- [ ] **Step 6: Commit**：`feat(payroll): 归档存在缺失异常时409拦截，force确认放行并留日志`

---

### Task 4: 前端纯函数（分级/高亮/确认文案/空值格式化）

**Files:**
- Modify: `frontend/src/modules/hr/payrollLogic.js`
- Modify: `frontend/src/utils/format.js`（新增 fmtEmpty）
- Test: `frontend/src/modules/hr/payrollLogic.test.js`（或同目录新建 `payrollGuard.test.js`）

**Interfaces:**
- `isZeroPayRow(row): boolean` — `Number(row.net) <= 0`
- `groupNotices(missing, warnings): { danger: Entry[], info: Entry[] }` — 两数组扁平合并；条目有 level 按 level，无 level 一律 info；保持入参顺序
- `archiveBlockerText(blockers): string` — 首行「以下 N 条薪资异常未处理：」，逐行 `姓名（项目）：reason`，末尾「确认仍要归档吗？忽略异常可能导致错误工资发放。」
- `fmtEmpty(v): string|v` — `v===null||v===undefined||v==='' → '—'`；其余原样返回（0 返回 0）

- [ ] **Step 1: 写失败测试**
- isZeroPayRow：net=0→true；net=-120.5→true；net=0.01→false。
- groupNotices：danger/info 正确分组；旧条目无 level → info；空入参 → 两组空数组。
- archiveBlockerText：含条数、含每个 blocker 的 name 与 reason、末尾确认句。
- fmtEmpty：null/undefined/'' → '—'；0 → 0；'张三' → '张三'。

- [ ] **Step 2: 运行确认失败**：`npm.cmd run test -- payrollGuard`（vitest run 过滤）

- [ ] **Step 3: 实现四个函数**（签名见上），payrollLogic.js 导出前三个，format.js 导出 fmtEmpty。

- [ ] **Step 4: 前端全量测试**：`npm.cmd run test` 全绿

- [ ] **Step 5: Commit**：`feat(frontend): 核算提示分级/0工资高亮/归档确认/空值格式化纯函数`

---

### Task 5: 前端接线（Payroll.vue + api client）

**Files:**
- Modify: `frontend/src/api/client.js`（错误对象附带 status/payload）
- Modify: `frontend/src/modules/hr/Payroll.vue`（doCalc L494-506、doCalcType L542-559、setArchive/setArchiveType L561-578、两处 `<tr>` L59/L165、net 单元格 L80/L187、样式）

**Interfaces:**
- 消费 Task 4：isZeroPayRow / groupNotices / archiveBlockerText
- client 抛错契约：`err.status`（HTTP 状态码）、`err.payload`（响应 JSON 体）

- [ ] **Step 1: client.js 改造**
- L29 `throw new Error(...)` 前：`const err = new Error(data.error || data.msg || '操作失败'); err.status = res.status; err.payload = data; throw err`。
- 现有全部 catch 仅读 message 的行为不受影响。

- [ ] **Step 2: 行高亮与悬停**
- import 增加 isZeroPayRow/groupNotices/archiveBlockerText。
- 两处 `<tr>` :class 追加 `'row-zero-pay': isZeroPayRow(r)`；两处 net 单元格去掉固定绿色内联样式，改 `:class="['num', { 'zero-pay': isZeroPayRow(r) }]"` 并加 `:title="isZeroPayRow(r) ? `实发${r.net}元（出勤${r.act_att ?? 0}天）` : ''"`；样式：`.row-zero-pay td { background:#fff7ed; } .zero-pay { color:#c2410c !important; font-weight:bold; }`（非 0 行保持现有绿色，用 class 替代内联）。

- [ ] **Step 3: 核算完成提示分级渲染**
- doCalc 与 doCalcType：用 `const notices = groupNotices(r.missing || [], r.warnings || [])` 生成文本：
  - danger 行前缀 🚨、info 行前缀 ℹ️（现有「无考勤」⚠️ 统一归 ℹ️）；逐人 `姓名(项目)`，reason 直接附后（后端文案已含指引，前端不再拼固定补录长句）。
  - 有 danger 时 msg 容器 class 用 `err`（现有样式类），仅 info 用 `info`，都无用 `ok`。
- 删除两处写死的「N 名离职人员…」固定文案。

- [ ] **Step 4: 归档 409 确认流**
- 新增统一函数：
  ```js
  async function postArchive(body) {
    try {
      await api('/api/payroll/archive', { body })
      toast(body.locked ? '已归档锁定' : '已解锁')
    } catch (e) {
      if (e.status === 409 && e.payload && e.payload.need_confirm) {
        if (confirm(archiveBlockerText(e.payload.blockers || []))) {
          await api('/api/payroll/archive', { body: { ...body, force: true } })
          toast('已归档锁定（已确认忽略异常）')
        } else return
      } else { alert(e.message); return }
    }
    reloadActive()
  }
  ```
- setArchive：员工归档保持无前置 confirm，改为 `postArchive({ ym: ui.month, locked })`。
- setArchiveType：保留现有前置 confirm（锁定/解锁文案不变），确认后调 `postArchive({ ym: ui.month, locked, type: tp === 'mgr' ? 'manager' : tp })`；reloadActive 已统一处理，删除函数内原有 loadType/loadPayroll。
- force 重发失败（非 409）走 alert。

- [ ] **Step 5: 验证**
- `npm.cmd run test` 全绿；`npm.cmd run build` 成功；本地起服务（PHP 内置服务器命令见 Global Constraints）人工冒烟：测试月份核算 → 看分级提示；0 工资行高亮；归档点按钮 → 409 确认框 → 取消不归档 / 确认归档成功。

- [ ] **Step 6: Commit**：`feat(frontend): 工资表0工资高亮、核算提示分级、归档异常强制确认`

---

### Task 6: 其他模块空值显示扫描 → 用户确认 → fmtEmpty 落地

**Files:**
- 产出：对话内清单（不新建 md 文件）
- Modify: 仅清单确认命中的显示点文件（frontend/src/modules 下）

- [ ] **Step 1: 扫描并产出清单（只读）**
- 全量扫描 frontend/src/modules 下 165 处 `?? 0`/`|| 0`（46 文件）及 `null/undefined/''` 渲染点，逐点分类：命中（业务数据展示空值被渲染成 0）/ 排除（分页计数、表单默认值、图表初始化、参与前端计算的默认值）。
- 在对话中给出清单表：模块 / 文件:行 / 字段 / 现状 / 拟改法（fmtEmpty 或金额格式化外层包空值判断）。

- [ ] **Step 2: 用户确认 gate（暂停，等用户逐条或整体确认）**

- [ ] **Step 3: 按确认清单替换**
- 每个命中点改为 fmtEmpty（纯展示文本直接替换；金额展示经 money() 的点，先判空再 money，或给 format.js 加 `moneyOrDash(v) = v===null||v===undefined||v==='' ? '—' : money(v)` 并使用之）。
- 真实 0 必须继续显示 0（fmtEmpty 不转 0）。

- [ ] **Step 4: 测试与回归**
- moneyOrDash 单测（null/''/0/123.4 四例）加入现有 format 测试文件（无则在最近的 logic test 中新建，不新建多余文件——若无 utils 测试目录，放 payrollGuard 同批测试文件）。
- `npm.cmd run test` 全绿；`npm.cmd run build` 成功。

- [ ] **Step 5: Commit**：`fix(frontend): 业务数据空值统一显示为—而非0（清单经确认）`

---

### Task 7: 全量回归、活文档、提交推送

**Files:**
- Modify: `docs/项目开发文档.md`（第八节追加一条；第五节接口清单登记 archive 变更）

- [ ] **Step 1: 后端全量**：phpunit 全绿，记录测试数（基线 139，新增后应 >139）
- [ ] **Step 2: 前端全量**：npm run test 全绿（基线 223）、npm run build 成功
- [ ] **Step 3: 活文档**：第八节追加（功能点/文件#行/思路/关键技术/原因/测试结果/git 短 hash——hash 先留占位，commit 后回填 amend 不允许，故先取前序 hash 之外的信息写好，提交后不再改文档）；第五节登记 `POST /api/payroll/archive` 新增 force 参数与 409 响应。
- [ ] **Step 4: Commit 文档并 push**：`docs: 开发记录-数据缺失护栏与空值显示规范`；`git push`
- [ ] **Step 5: 报告**：向用户汇总改动、测试结果、版本 hash、待部署（含与 e5eebcf 一并部署的提醒，不自行部署）

---

## 自审记录（spec 覆盖核对）

- spec §3.1 引擎改造 → Task 1+2；§3.2 归档 → Task 3；§3.3 工资表显示 → Task 5；§3.4 导出/工资条「不改」→ 无任务（符合 spec）；§3.5 其他模块 → Task 6；§4 数据结构（level/force/409）→ Task 1/3/5；§5 测试计划全部条目映射到 Task 1-4、6；§7 部署 → Task 7 仅提醒不执行。
