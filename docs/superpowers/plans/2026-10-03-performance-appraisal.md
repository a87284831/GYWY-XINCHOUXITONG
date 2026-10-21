# 绩效考核模块改造 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让经理级/总部（≤40 人）在线完成「目标制定→上级审核改指标→数据核查（含核查定分）→本人自评附依据→上级评分→汇总出分→打印签字」全流程，不与薪资联动。

**Architecture:** 全部状态/数据继续存 `performance_plans.data` JSON，不建表不迁移。算分口径改为「客观项锁定 + 主观项自评/上级加权」单一公式（见 spec §3.2），后端 PerformanceController 为唯一算分权威；新增 4 个后端接口（confirm_save / reopen / 附件 3 个）与 1 个前端打印路由；附件存 storage/app 非公开目录、鉴权下载。

**Tech Stack:** Laravel（PHP 8.2/8.3）、PHPUnit + RefreshDatabase(sqlite :memory:)、Cache token 鉴权（请求头 `X-Token`）、Vue3 + Element Plus + vitest、PhpSpreadsheet（不动）

**Spec:** `docs/superpowers/specs/2026-10-03-performance-appraisal-design.md`（实施时 spec 与本计划同时参照）

## Global Constraints

- 不新建数据库表、不做 migration；新字段仅 `item.checkScore`(number|null)、`item.attachments`(array)
- 客观项类型键：ratio/ladder/count/check；主观项：manual。calcType 缺省按 ratio（兼容历史数据）
- 客观项最终分锁定；只有 manual 项接受 selfScore/approverScore/finalScore
- 权重合计必须=100（容差 0.01）；checkScore 合法区间 [0, weight]，0 是合法明确分值
- 占比沿用 performance.json 的 scoreWeights（默认 self=50/approver=50）
- 所有错误显式返回 `{ok:false,error}` + 正确 HTTP 码，不静默按 0
- 附件：jpg/jpeg/png/pdf，单文件 ≤10MB，每项 ≤5 个；存 `storage/app/perf-attachments/{planId}/`
- 提交粒度：每个 Task 结束一次 commit；commit 信息用中文 conventional 风格
- 本地 PHP：`C:\Users\87284\.local\lib\php-8.3\php.exe`；PowerShell 5 用 `;` 连接命令

## Review Focus

1. **checkScore=0 与未填的区分**：0 必须被当作"明确给 0 分的锁定分"，不能被判成缺分；缺分是字段不存在/null。Task 1 有测试钉住。
2. **多审批人节点**：归档缺分校验只能在最后一级 approve 触发；中间级通过时（status 仍为 approve）不得报"缺分不能归档"。Task 1 有两级审批测试。
3. **历史/脏指标缺 calcType**：默认 ratio，若其 actualValue 为空则在终审被判客观项缺分（而非崩溃或当主观项）。Task 1 有测试。
4. **附件文件参数穿越**：file 含 `..`、`/`、绝对路径或不属于该 planId 目录 → 400/404，不允许读到任意文件。Task 4 有测试。
5. **自我核查不被权限拦**：reporterId == 被考核人本人 staff_id 时，本人 report 必须成功（现有 reporter 比对逻辑保留），打印表显示核查人。Task 1 有测试。

---

### Task 1: 后端算分闭环——check 型 + 客观锁定/主观加权 + 归档校验

**Files:**
- Modify: `app/Http/Controllers/Api/PerformanceController.php`（CALC_TYPES L15；`report()` L268-312；`selfSubmit()` L335-357；`approve()` L359-409；`autoScore()` L561-578；`applyScores()` L586-623；`calc()` L421-430）
- Modify: `routes/api.php`（无新路由；如需要给 calc 支持 check 无需改路由）
- Create: `tests/Feature/Concerns/SeedsPerformancePlan.php`
- Test: `tests/Feature/PerformanceScoringFlowTest.php`

**Interfaces:**
- Consumes: 现有 `payroll_accounts`（legacy_id, staff_id, role, enabled）、`payroll_staff`（legacy_id, name, leader_id）、`performance_plans`（legacy_id,status,data JSON）；鉴权头 `X-Token`
- Produces（本任务定义、后续任务依赖）：
  - `private function validateCategories(array $categories): ?string` —— 返回 null=通过；否则返回中文错误。校验：每项 weight≥0；calcType∈{ratio,ladder,count,check,manual}（缺省 ratio）；权重合计=100（容差 0.01）；ratio/ladder/count 允许 calcParams 数值（不合法不报错，按 0 参与既有公式）
  - `private function objectiveLockedScore(array $it): ?float` —— ratio/ladder/count 有 actualValue 时返回 autoScore；check 有数值型 checkScore 时返回 round(checkScore,2)；manual 返回 null；客观项无值返回 null
  - `private function missingObjectiveScores(array $plan): array` —— 返回缺锁定分的客观项"类别/内容"字符串数组
  - item JSON 新字段：`checkScore`；常量 `CALC_TYPES` 增加 `'check' => '核查定分'`
- HTTP 契约变化：
  - `POST /api/performance/report`：check 项入参 `checkScore`（数值），不接受 actualValue；写 item.checkScore/reportBy/reportTime
  - `POST /api/performance/self_submit`：items 中对非 manual 项带 selfScore → 400
  - `POST /api/performance/approve`：items 中对非 manual 项带 approverScore/finalScore → 400；最后一级归档前 `missingObjectiveScores()` 非空 → 400，返回 `error` 含缺分项、`missing` 数组
  - `POST /api/performance/calc`：calcType=check → 透传 actual 作为给定分（score=入参 actualValue，边界 0..weight 内 clamp），供前端预览

- [ ] **Step 1: 建测试基建 trait**

创建 `tests/Feature/Concerns/SeedsPerformancePlan.php`，trait `Tests\Feature\Concerns\SeedsPerformancePlan`，提供：
- `seedAccount(int $legacyId, int $staffId, string $role='staff', bool $enabled=true): string` —— 插 payroll_accounts + Cache token，返回 token（payroll_staff 由调用方先插）
- `seedStaff(int $legacyId, string $name, array $extra=[]): void` —— 插 payroll_staff 最小行
- `putPlan(array $plan): int` —— 补默认值（id 自增=max+1、status、createdAt、logs=[]）后插入 performance_plans，返回 id；同时同步 employee_id/employee_name/project_name/year/status 列
- `auth(string $token)` 用法：`$this->withHeaders(['X-Token'=>$token])`

- [ ] **Step 2: 写失败测试 `PerformanceScoringFlowTest`**

用例（方法名即断言意图）：
1. `test_objective_ratio_score_is_locked_and_counts_in_final_total`：1 个 ratio(weight20,target100) actualValue=75；1 个 manual(weight80)。plan 置 self；self_submit 只给 manual 项 selfScore=70；GET detail 断言该 ratio 项 finalScore=15、manual 项 finalScore=70（无上级分回退用自评）、finalTotal=85
2. `test_check_item_reporter_enters_score_and_locks`：plan 置 report，1 个 check(weight20)，reporterId=核查人 staff；用核查人 token report checkScore=16 → 200；detail 该项 checkScore=16、finalScore=16
3. `test_check_score_zero_is_valid_not_missing`：checkScore=0 report → 200；补 manual 项走完 self+终审（admin）→ done，finalTotal 含 0 锁定分，不报缺分
4. `test_check_score_out_of_range_rejected`：checkScore=-1 与 21（weight20）分别 → 400
5. `test_self_submit_rejects_score_on_objective_item`：self_submit items 给 ratio 项 selfScore → 400
6. `test_approve_rejects_score_on_objective_item`：approve 给 ratio 项 approverScore → 400
7. `test_final_approve_blocked_when_objective_score_missing`：manual 项齐分，ratio 项无 actualValue；单级审批终审 → 400 且 JSON `missing` 非空
8. `test_mid_level_approve_not_blocked_by_missing_objectives`：两级审批人，第 1 级 approve（客观项缺分）→ 200 status=approve step=1；第 2 级 → 400
9. `test_reporter_can_be_subject_employee`：check 项 reporterId=本人 staff_id，本人 token report checkScore=20 → 200
10. `test_legacy_item_without_calctype_defaults_ratio_and_guards_archive`：无 calcType 客观项无 actualValue，终审 → 400 missing 含该项

- [ ] **Step 3: 运行测试确认失败**

`& C:\Users\87284\.local\lib\php-8.3\php.exe vendor/bin/phpunit --filter PerformanceScoringFlowTest`
Expected: FAIL（check 未支持/锁定口径不对）

- [ ] **Step 4: 实现**

- CALC_TYPES 加 `'check' => '核查定分'`
- 新增 validateCategories / objectiveLockedScore / missingObjectiveScores（签名见上）
- `autoScore()` 保持现状（仅 ratio/ladder/count）；check 不参与 autoScore
- `report()`：找到 item 后按 calcType 分支——check：校验 checkScore 数值且 0≤v≤weight，写 checkScore、actualValue 置 ''；manual：现状；其余：现状
- `applyScores()` 重写每项口径：
  - 客观项（含缺省 ratio）：`$locked = objectiveLockedScore($it)`；非 null 时写 finalScore=locked 并计入 finalTotal；autoScore 字段照算展示；selfScore/approverScore 即使存在也不计入该项最终分
  - check：autoScore=null，finalScore=checkScore（锁定），计入 finalTotal
  - manual：维持现有加权/微调公式（selfWeighted/approverWeighted 字段保留）
  - selfTotal/approverTotal 只累加 manual 项分数；autoTotal 维持（展示）
- `selfSubmit()`：遍历入参 items，非 manual 项出现数值 selfScore → 400「客观指标分数由核查数据锁定，不可自评」
- `approve()`：同样拦截非 manual 的 approverScore/finalScore；终审分支（$isLast）在 applyScores 前调 missingObjectiveScores，非空 → 400 + missing
- `calc()`：check 分支 score = max(0,min(weight,(float)actualValue))

- [ ] **Step 5: 跑本任务测试至全绿**

`& C:\Users\87284\.local\lib\php-8.3\php.exe vendor/bin/phpunit --filter PerformanceScoringFlowTest`
Expected: 10 PASS

- [ ] **Step 6: 跑全量回归**

`& C:\Users\87284\.local\lib\php-8.3\php.exe vendor/bin/phpunit`
Expected: 全绿（现有 ExportPerfDetailTest 等不受影响；applyScores 口径变化只影响 performance_plans 数据）

- [ ] **Step 7: 提交**

`git commit -m "feat(perf): 客观指标锁定算分+核查定分(check)型，主观项走自评/上级加权"`

---

### Task 2: 指标冻结 + 上级审核改指标（confirm_save，diff 留痕）

**Files:**
- Modify: `app/Http/Controllers/Api/PerformanceController.php`（`save()` L80-182 加状态守卫并改用 validateCategories；`delete()` L207-215；新增 `confirmSave()`；新增 `private function logCategoriesDiff()`）
- Modify: `routes/api.php`（L82 附近加 `Route::post('/performance/confirm_save', [PerformanceController::class, 'confirmSave']);`）
- Test: `tests/Feature/PerformanceConfirmEditTest.php`

**Interfaces:**
- Produces:
  - `POST /api/performance/confirm_save`，入参 `{id, categories:[...]}`（整体替换指标类别树）
  - `private function logCategoriesDiff(array &$plan, array $oldCategories, object $account): void` —— 按项匹配（优先 item id，缺失时按"类别名+内容"），对 content/definition/weight/calcType/calcParams/reporterId 的变化逐条写 log（comment 形如「指标『回款率』权重 20→25；达标线 85→80」）；新增/删除项也记录
- 规则：仅 confirm 态；权限=首位审批人（与 confirm() L222-230 同判断，抽 `private function firstApproverAuthorized(array $plan, object $account): bool` 复用）或 admin；employeeId/periodStart/periodEnd/approvers/founderId 一律以服务端现存 plan 为准（入参仅取 categories）；保存前 validateCategories

- [ ] **Step 1: 写失败测试 `PerformanceConfirmEditTest`**

1. `test_first_approver_can_save_edited_categories_in_confirm`：审批人 token 调 confirm_save 改 weight 20→25（同步调整另一项使合计仍 100）→ 200；detail 反映新权重；logs 最后几条 comment 含「权重」与「20→25」
2. `test_non_approver_confirm_save_forbidden`：本人/无关账号 → 403
3. `test_confirm_save_wrong_status_rejected`：ongoing 态 → 400
4. `test_confirm_save_cannot_change_employee_period_approvers`：入参 categories 外再带 employeeId/periodStart/approvers → 200 但 detail 中这三者与原值完全一致
5. `test_confirm_save_weight_sum_rejected`：合计 90 → 400
6. `test_save_rejects_editing_after_confirm`（冻结）：同一 plan，save 接口在 ongoing/report/self/approve/done 各态（数据提供五态）→ 400；draft 态 → 200
7. `test_delete_done_plan_rejected`：done 态 delete → 400；draft 态发起人 delete → 200
8. `test_confirm_save_logs_added_and_removed_items`：新增一项/删除一项，日志含新增、删除字样

- [ ] **Step 2: 运行确认失败**

`& C:\Users\87284\.local\lib\php-8.3\php.exe vendor/bin/phpunit --filter PerformanceConfirmEditTest`
Expected: FAIL（路由 404/方法不存在）

- [ ] **Step 3: 实现**

- save()：开头取现存 plan（若有 id），状态非 draft → 400「考核指标已提交/生效，不可再编辑」；权重校验改用 validateCategories（新建场景与编辑场景一致）；id=0 新建不受限
- delete()：plan.status==='done' → 400
- 抽 firstApproverAuthorized()（搬 confirm L222-230 判断）
- confirmSave()：requireAccount → plan 存在 → status==='confirm' → 权限 → 取入参 categories 调 validateCategories → 保留旧 categories 调 logCategoriesDiff → 只替换 plan['categories']（其他字段不碰）→ log 主记录「审核人修改考核指标」→ storePlan
- 注册路由

- [ ] **Step 4: 测试全绿 + 全量回归**

`... phpunit --filter PerformanceConfirmEditTest` 8 PASS；`... phpunit` 全绿

- [ ] **Step 5: 提交**

`git commit -m "feat(perf): 上级审核可直接修改指标并留痕，指标生效后冻结"`

---

### Task 3: 撤销归档（reopen）+ 详情数据级查看权限

**Files:**
- Modify: `app/Http/Controllers/Api/PerformanceController.php`（新增 `reopen()`、`private function canViewPlan()`；`detail()` L63-78 加权限闸）
- Modify: `routes/api.php`（加 `Route::post('/performance/reopen', [PerformanceController::class, 'reopen']);`）
- Test: `tests/Feature/PerformanceReopenAndViewTest.php`

**Interfaces:**
- Produces:
  - `POST /api/performance/reopen`，入参 `{id}`：仅 admin；done→approve
  - `private function canViewPlan(object $account, array $plan): bool`：admin｜founderId==account.legacy_id｜employeeId==account.staff_id｜approvers 中任一 staffId==staff_id 或 userId==legacy_id｜任一 item.reporterId==staff_id
- reopen 数据处理：status=approve、currentStep=0；清 approvers[*].state（由 decoratePlan 重算，无需手清，但若 state 持久在 data 中则逐项 unset）、approverNames/approverTimes/approverOpinions 清空；逐项移除 approverScore/finalScore/finalManual；保留 selfScore/actualValue/actualText/checkScore/autoScore 来源值/attachments；applyScores 重算；log「管理员撤销归档，退回上级评分」

- [ ] **Step 1: 写失败测试**

1. `test_admin_reopen_resets_approval_keeps_scores_and_attachments`：造 done 单（manual 项有 selfScore=70/approverScore=80/finalScore=75/finalManual=true；check 项 checkScore=16；item.attachments=[1条]）→ admin reopen 200；detail：status=approve、currentStep=0；manual 项 selfScore=70 仍在、approverScore/finalScore/finalManual 不存在；check 项 checkScore=16；attachments 仍在；finalTotal 重算（=check 锁定 + 自评回退分）；logs 含「撤销归档」
2. `test_non_admin_reopen_forbidden` → 403
3. `test_reopen_wrong_status`：approve 态 → 400
4. `test_detail_view_permission_matrix`：admin/本人(staff 匹配)/发起人/链上审批人/被指派核查人 → 200；无关账号 → 403；未登录(无 token) → 401

- [ ] **Step 2: 运行确认失败** → FAIL（reopen 404；detail 目前 200）

- [ ] **Step 3: 实现**

- canViewPlan 按签名实现；detail() 在取到 plan 后 `if (!$this->canViewPlan($account,$plan)) return 403`
- reopen()：admin 校验 → status==='done' → 按签名清理 → applyScores → storePlan
- 注册路由

- [ ] **Step 4: 全绿 + 全量回归**，重点确认 ExportPerfDetailTest 与列表类测试（admin token 不受影响）

- [ ] **Step 5: 提交**

`git commit -m "feat(perf): 管理员撤销归档重审 + 考核单详情数据级查看权限"`

---

### Task 4: 自评附件（上传/鉴权下载/删除）

**Files:**
- Modify: `app/Http/Controllers/Api/PerformanceController.php`（新增 `uploadAttachment()`/`downloadAttachment()`/`deleteAttachment()`；私有路径/权限 helper）
- Modify: `routes/api.php`（加三条路由，download 用 get，delete 用 delete，均在登录路由组内）
- Test: `tests/Feature/PerformanceAttachmentTest.php`

**Interfaces:**
- Produces:
  - `POST /api/performance/attachment`（multipart：id, itemId, file）→ `{ok:true,name,url,size}`，url 形如 `/api/performance/attachment?id=1&file=<stored>`
  - `GET /api/performance/attachment?id=&file=` → 二进制流（图片 image/jpeg|png、pdf application/pdf）
  - `DELETE /api/performance/attachment`（JSON：id,itemId,file）→ `{ok:true}`
  - item.attachments[] 元素：`{name(原名), file(存储文件名), size, uploaderId, uploaderName, ts}`
  - `private function perfAttachmentDir(int $planId): string` = storage_path('app/perf-attachments/'.$planId)
  - `private function safeAttachmentFile(int $planId, string $file): ?string` —— 仅允许 `/^[A-Za-z0-9_\-]+\.(jpg|jpeg|png|pdf)$/i`，realpath 必须位于目录内，否则 null
- 权限：
  - 上传：status==='self' 且（employee 本人 staff 匹配 或 founderId 匹配）或 admin；itemId 必须属于该单的 manual 项；≤5 个
  - 下载：canViewPlan() 通过
  - 删除：status!=='done' 且（admin 或 attachment.uploaderId==account.legacy_id），同时删磁盘文件与数组元素

- [ ] **Step 1: 写失败测试**

1. `test_subject_uploads_attachment_in_self_stage`：self 态、manual 项，UploadedFile fake 图片上传 → 200；磁盘存在；detail 中该项 attachments 长度 1 含 name/uploaderId
2. `test_upload_rejected_wrong_type_size_count`：.txt → 400；伪造 11MB → 403/400（400）；连传第 6 个 → 400
3. `test_upload_forbidden_non_self_status_and_outsider`：ongoing 态 → 400；无关账号 self 态 → 403
4. `test_download_requires_view_permission`：本人 200 且 Content-Type 正确；无关账号 403
5. `test_download_blocks_path_traversal`：file=`../../.env` 与 `1/../x.jpg`（planId=2）→ 400/404
6. `test_delete_attachment_owner_before_done_and_block_after`：本人 self 态删除 → 200 且文件消失、数组清空；done 态删除 → 400；非上传者 → 403

- [ ] **Step 2: 运行确认失败** → FAIL（404）

- [ ] **Step 3: 实现**

- 用 `Illuminate\Http\UploadedFile`（$request->file('file')）；存储名 `{itemId}_{YmdHis}_{rand4}.{ext}`；`$file->move($dir,$stored)`
- 下载用 `response()->file($path)` 或 `response()->download($path,$name)`；先 safeAttachmentFile 校验
- 三个方法均先 plan() + 状态/权限判断；错误返回与系统风格一致
- 路由：post/get/delete `/performance/attachment`（注意与现有 `/performance/plans/{id}` 不冲突）

- [ ] **Step 4: 全绿 + 全量回归**

- [ ] **Step 5: 提交**

`git commit -m "feat(perf): 自评依据附件上传/鉴权下载/删除，非公开目录存储"`

---

### Task 5: 前端逻辑层 + 发起页（类型/check/核查人）

**Files:**
- Modify: `frontend/src/modules/perf/perfLogic.js`（CALC 类型、perfCalcItem check 分支）
- Modify: `frontend/src/modules/perf/perfLogic.test.js`
- Modify: `frontend/src/modules/perf/PerfCreate.vue`（CALC_LABEL L125、CALC_HELP L126-、指标行 L57-75：check 型无参数 UI；核查人下拉确认含本人）
- Test: vitest（perfLogic.test.js）

**Interfaces（后端已支持）：** detail 返回 `calcTypes` 已含 check（后端常量）；report 入参 checkScore

- [ ] **Step 1: 写/改 vitest 用例（先失败）**

- perfCalcItem：`{calcType:'check'}` → null（不自动算）；ratio/ladder/count 回归不变
- 如有类型标签导出常量，补 check 标签断言

- [ ] **Step 2: 运行** `npx vitest run src/modules/perf/perfLogic.test.js` → 新用例 FAIL

- [ ] **Step 3: 实现**

- perfCalcItem check 分支返回 null（得分由核查人填报）
- PerfCreate：CALC_LABEL 改 `{ratio:'比例计分',ladder:'阶梯扣分',count:'达标扣分',check:'核查定分',manual:'主观评分'}`；CALC_HELP 补 check「核查人直接填写该项得分（0~权重），提交后锁定，本人与上级不可更改。如：延期、违规等按事实直接定分的指标」；count 帮助文案改直白（达标线、每差1单位扣分）
- check 型选中时：隐藏 calcParams 输入，显示说明；新项默认 calcParams={}（提交时 check 不校验参数）
- 核查人下拉数据源确认包含被考核人本人（人员选择列表为全量 staff；若现有列表过滤了本人则去掉该过滤）；选中本人时橙色提示「核查人为本人，分数将在签字时由领导知情核对」

- [ ] **Step 4: vitest 全绿**

- [ ] **Step 5: 提交** `git commit -m "feat(perf-ui): 发起页指标类型更新（核查定分/直白文案），核查人可选本人"`

---

### Task 6: 前端详情页改造（审核编辑/定分填报/锁定/附件/撤销/打印入口）

**Files:**
- Modify: `frontend/src/modules/perf/PerfDetail.vue`（约 L313-379 操作区、指标表 L79-120、脚本 L242-265 等）
- 复用：PerfCreate 的指标编辑行结构（抽不抽组件由实现者定；可先复制最小区块，YAGNI）
- API 调用沿用现有 fetch 封装（参照同文件既有 api 调用方式）

**改动清单（按状态）：**
- confirm 态 + 有审核权（me 匹配首位审批人或 isAdmin）：指标区可编辑（仅类型/内容/定义/权重/参数/核查人），按钮「保存修改」(confirm_save)、「审核通过」、「驳回」；保存失败弹 error；页面展示日志区指标变更记录
- report 态：check 项显示"得分(0~权重)"数字输入，提交 report 带 checkScore；其余类型维持 actualValue/actualText
- self 态：仅 manual 项可填自评分；客观项显示锁定标识🔒与锁定分；manual 项渲染附件区（上传 input accept=image/*,application/pdf、多文件列表带大小、删除按钮、缩略图）；调附件三接口（下载用 fetch 带 X-Token → blob 预览）
- approve 态：仅 manual 项可填上级分/终审微调；客观项锁定；附件可预览不可传删；终审返回 400 时透弹 `missing` 列表文案
- done 态：显示「打印考核表」按钮（canView 即可见，新窗口打开 `/app/perfPrint/{id}`——本项目为 createWebHistory base `/app/`）；admin 显示「撤销归档」按钮 + 二次确认（确认文案说明上级评分将清空）
- 指标表头/行补充：核查人、类型标签；客观项自评/上级分列显示「—」

- [ ] **Step 1: 手工走查脚本（实现中自测，无新单测）**

admin + 员工两个测试账号走：发起（含 check 项、核查人选本人）→ 审核改权重保存（看日志）→ 通过 → 手动结束周期 → 本人核查定分填报 → 自评传 2 张图提交 → 上级评分终审（先制造缺分看拦截，补齐再归档）→ 看汇总分。

- [ ] **Step 2: 实现上述改动**

- [ ] **Step 3: 构建验证** `npm run build`（在 frontend 目录）必须成功，无未定义变量/导入错误

- [ ] **Step 4: 提交** `git commit -m "feat(perf-ui): 详情页审核改指标/核查定分/客观锁定/自评附件/撤销归档/打印入口"`

---

### Task 7: 打印考核表页面

**Files:**
- Create: `frontend/src/modules/perf/PerfPrint.vue`
- Modify: `frontend/src/router/index.js`（在 perf 路由组 L81-113 内加 `{ path:'perfPrint/:id', name:'perfPrint', component:()=>import('@/modules/perf/PerfPrint.vue'), meta:{perm:'perf'} }`）

**页面规格（spec §4.8）：**
- 进入即按 route.params.id 调 GET detail；403/404 显示提示；无菜单
- A4 竖版：@media print 隐藏操作按钮；屏幕态顶部放「打印 / 另存PDF」按钮（window.print）
- 抬头：标题、被考核人、岗位/条线(line)、项目、周期起止
- 明细表列：类别/内容/目标与评分标准/权重/实际完成/核查人/自评分/上级评分/最终得分；客观项自评上级列「—」；check 项实际完成显示「核查定分：X」
- manual 项实际完成下渲染附件缩略图（图片 img 限高 90px；PDF 显示文件名）；图片 src 走 blob 鉴权下载（复用 Task 6 的下载逻辑，可抽到 perfLogic.js 导出 `attachmentUrl(api,id,file)` 之类，避免重复）
- 汇总：自评总分/上级评分总分/最终总分/等级
- 签字区：被考核人签字＿＿＿；审批链每人一行「{name} 签字＿＿＿」；日期＿＿＿

- [ ] **Step 1: 实现 PerfPrint.vue + 路由**
- [ ] **Step 2: 构建验证** `npm run build`
- [ ] **Step 3: 浏览器走查**（dev 或构建产物）：打印预览版式完整、无权限账号提示 403、附件图显示
- [ ] **Step 4: 提交** `git commit -m "feat(perf-ui): 网页A4考核表打印页（签字版）"`

---

### Task 8: 全量回归、文档、收尾

**Files:**
- Modify: `DEV_CHANGELOG.md`（追加本次改动段，引用 spec 路径）
- Spec 已在仓库内，随代码一起提交（若尚未 commit）

- [ ] **Step 1: 后端全量** `& C:\Users\87284\.local\lib\php-8.3\php.exe vendor/bin/phpunit` 全绿
- [ ] **Step 2: 前端全量** `npx vitest run` 全绿；`npm run build` 成功
- [ ] **Step 3: 更新 DEV_CHANGELOG.md**（功能清单、接口、数据字段、无迁移说明、测试数）
- [ ] **Step 4: 提交** `git commit -m "docs(perf): 考核流程改造开发记录"`
- [ ] **Step 5: push（用户已授权 push 的仓库惯例）并给出部署清单**：后端仅 1 个 PHP 文件 + routes/api.php；前端需重新 build 发布；storage/app/perf-attachments 目录确保 fpm 用户可写；无 migration
