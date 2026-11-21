# 开发交接文档

> 协同开发人员（李昊轩 / 谢印良）每完成一次代码修改并推送到远端后，**必须在此文档追加一条记录**，让对方拉取代码后能立刻知道动了什么、为什么动、有什么影响。

---

## 协作约定

- **远端仓库**：https://github.com/a87284831/haoxuanyunshu.git
- **协作分支**：`feature/frontend-unification`
- **开工前**：`git pull` 同步最新代码
- **改完**：`git add` → `git commit` → `git push`
- **生产域名**：`oa.haoxuanyunshu.cn` / `oa.haoxuanyunshu.cn`（部署在宝塔）→ **严禁改动**任何与该域名相关的配置、DNS、API 目标
- **本地测试后端**：`http://127.0.0.1:8899`（两人各自在自己本机 8899 端口跑 Laravel）

## 本地启动方式

### 1. 后端（Laravel，端口 8899）

```bash
cd e:\2026-0929\haoxuanyunshu
php artisan serve --port=8899
```
- 访问入口：`http://127.0.0.1:8899`（API 走 `/api/*`，根路径返回登录页 `public/index.html`）
- 首次运行需先准备 `.env`（参考 `.env.example`）、`php artisan migrate`、生成 `APP_KEY`

### 2. 前端（Vue 3 + Vite，端口 5173）

```bash
cd e:\2026-0929\haoxuanyunshu\frontend
npm install
npm run dev
```
- 访问入口：**`http://localhost:5173/app/`**（注意 base 是 `/app/`，必须带这个路径）
- `/api/*` 请求会被代理到 `http://127.0.0.1:8899`（在 `frontend/vite.config.js` 配置）
- 构建产物：`npm run build` 输出到 `public/app/`

### 3. 默认登录账号

- 用户名：`admin`
- 密码：`admin123`

---

## 改动历史

### 2026-11-20 · 修复：三模块审计 3 项严重问题（v2026.11.20.2）

- 采购填报明细 `handle_fill_items` 新增 `purchase_fill_scope()`：非管理员必须绑定有效项目，`project_id<=0` 直接拒绝并写「越权拒绝」审计（账号/项目/路径/动作），禁止退化为全量查询；`handle_fill_frequency` 同步收口。
- 报价导入新增 `purchase_import_archive_rows()`：只清理/覆盖本次上传涉及的「项目 + 条线」，其他项目存档不动；写库前生成整月快照（`storage/app/purchase_import_snapshots`），失败整体回滚并删除快照；日志记录涉及项目、新增/覆盖删除/跳过条数与快照路径；价格回写去掉 `LIMIT 1`。
- 报表中心 `ReportController` 统一 `splitResultRecords/projectRows/categoryBreakdown`：项目合计=基层+管理，案场/总部单列；驾驶舱、人力、薪酬三入口同口径，人均应发分子分母同时只取基层+管理；前端薪酬/人力报表增加四类金额与口径说明。
- 测试：新增 `PurchaseScopeGuardTest`（5）、`ReportScopeConsistencyTest`（4）；PHPUnit 489/3504、Vitest 372、`npm run build` 通过。

### 2026-10-03 · 绩效考核：最终自审安全/边界修复（未推送）

- **防分数注入（安全）**：指标定义入口（`plans/save` 草稿保存、`confirm_save` 审核态改指标）新增白名单清洗 `sanitizeCategories()`，只接受定义字段（id/内容/定义/来源部门/权重/类型/参数/核查人）；此前被考核人本人(founder)可经这两个入口注入 `checkScore/selfScore/approverScore/finalScore/attachments` 等流程字段直接加分
- **未指派客观项语义闭环**：合法五类指标未指派核查/填报人时，终审不再死锁拦截，按"自动跳过、该项 0 分、不做权重重归一化"处理（历史脏数据：calcType 缺失/非法仍拦截）；同时封堵越权——非管理员对未指派项的填报一律 403（管理员可代填）
- **主观分限界**：自评/上级评分/终审微调分限定 `0~该项权重`，越界返回 400（与 check 定分同口径；权重合计=100、总分=Σ 各项分）；前端三个评分输入加 min/max、范围占位与提交前校验，核查定分提交前同样校验
- **附件锁定收紧**：自评阶段结束（进入审批）后本人不可再删附件，仅管理员可纠错删除；归档后完全冻结
- **打印表**：未指派核查/填报人的客观项在 A4 表上标注"未指派核查/填报人，该项不考核，不计分"、最终分列显示"未考核"，纸质签字可追溯
- **验证**：PHPUnit 226/226（757 assertions，新增注入攻击/未指派跳过+越权/主观分越界/审批态删附件 5 个用例）；vitest 260/260；`vite build` 通过，产物已更新到 `public/app/`
- **Ruling**：① 跳过项权重作废（总分可能低于 100），纸质表可追溯；若业务希望按剩余权重重归一化需另改算分模型；② 历史无 calcType 脏数据不享受"跳过"，仍强制修正

### 2026-10-03 · 绩效考核模块改造（经理级/总部 ≤40 人，纸质签字、不联动薪资）

- **流程定稿**：本人发起选周期 → 上级审核（可直接改指标或驳回，修改留痕）→ 通过即生效、指标冻结 → 周期结束核查人填报 → 本人自评（仅主观项，可传截图附件）→ 上级逐级评分（仅主观项）→ 归档出分 → 网页打印 A4 纸质签字；管理员可撤销归档重审
- **五类指标（算分模型）**：比例计分 ratio / 阶梯扣分 ladder / 达标扣分 count / **核查定分 check（新增）** / 主观评分 manual。客观四类在数据填报/核查后**锁定**，本人与上级均不可评分；check 由核查人直接在 0~权重 内定分（含 0）；主观项最终分=自评×占比+上级×占比，终审可逐项微调
- **后端改动**（仅 2 文件，无 migration）：`app/Http/Controllers/Api/PerformanceController.php`（算分闭环、指标冻结、confirm_save/reopen/attachment 接口、详情 canViewPlan 数据级权限）、`routes/api.php`（新增 `confirm_save`、`reopen`、`attachment` GET/POST/DELETE 共 5 条路由）
- **附件存储**：`storage/app/perf-attachments/{考核单legacy_id}/`（非 public），jpg/jpeg/png/pdf、单文件 ≤10MB、每项 ≤5 个；存储名服务端重生成、白名单+realpath 防穿越；下载走鉴权接口，仅考核单可见人可访问
- **前端改动**：perfLogic.js（五类常量集中导出、check 不参与自动算分）；PerfCreate.vue（五类直白文案、check 无参数 UI、核查人可指定本人）；PerfDetail.vue（审核态行内改指标、check 定分输入、客观项 🔒、主观项自评+附件上传/缩略图/删除、终审缺失项透传弹窗、打印入口、管理员撤销归档二次确认）；新增 PerfPrint.vue 与顶层路由 `/perfPrint/:id`（A4 纵向打印，含明细/汇总/审批意见/附件缩略图/签字栏）
- **验证**：PHPUnit 221/221（新增 4 个测试文件 32 用例：算分闭环/审核改指标/撤销归档与查看矩阵/附件）；vitest 260/260；`npm run build` 通过
- **部署注意**：① 需确保 php-fpm 对 `storage/app/perf-attachments/` 可写（目录首次上传自动创建）；② 前端需重新 build 并发布 `public/app/`（本次产物 hash 全量更新）；③ 无数据库迁移；④ 撤销归档仅管理员，退回审批起点时上级评分清空、自评/核查分/附件保留
- 设计与实施记录：`docs/superpowers/specs/2026-10-03-performance-appraisal-design.md`、`docs/superpowers/plans/2026-10-03-performance-appraisal.md`

### 2026-10-03 · 清理：旧系统残留/测试数据/一次性脚本

- **仓库文件删除（git 可恢复）**：
  - `_fin_import_cli.php`：一次性财务导入 CLI，从未投入使用（财务明细表均 0 行），无任何引用
  - `public/uploads/20260904_015100_8100.xlsx`、`...015108_7213.pdf`：9-4 手工拷入的附件，全库 51 张表文本列扫描 0 引用
- **生产同步清理**：上述 3 文件的线上副本、`public/app.bak-57e88bd` 旧前端、9-26 前端统一期手工对照目录、已失效的 patch_frontend.php、废弃 worktree、2 个 CRLF 误建的空"幽灵目录"、/tmp 共 92 个历史脚本/包；全部先归档于 `/root/cleanup-archive-20261003.tar.gz`（含 MANIFEST）
- **生产测试数据删除**：`payroll_attendance` id=1/2/3（罗庄春暖花开 2026-07/08/09 各 42 人随机考勤，由临时脚本生成、locked=0、从未核算）；删除前已 mysqldump 归档。系统当前无任何考勤批次，属干净起点
- **生产备份瘦身**：删 9-25 两个手工备份目录、export_bundle 交付包（含明文 env.txt）、/root 7 个旧部署快照；保留 `/root/pre_redesign_backup`、最新 formula 快照、/var/backups 每日自动备份
- **验证**：冒烟 /app/ 200、主 JS 200、payslip 200、鉴权接口 401、laravel.log 零新增
- 提交：本提交（chore: 旧系统残留与随机考勤测试数据清理）

### 2026-10-03 · 修复：中文展示公式无法求值致任何真实核算中止

- 背景：生产 calc_rules 的 gross/net 公式存中文展示词（绕过前端英文转换的存量），Expr 只认 ASCII，任何真实核算都在公式求值处中止
- 改动：`app/Services/CalcRules.php` 新增 `normalizeFormula()`（22 个中文内置词归一化，词表与前端 VAR_CN 单一事实源互指）；`app/Services/Expr.php` tokenizer 支持 CJK 标识符（未定义变量仍显式报错）
- 测试：新增 `tests/Unit/FormulaCnNormalizeTest.php` 8 用例；全量 PHPUnit 189/189
- 提交：`70020c0` fix(payroll)（已部署生产并零痕迹端到端验证）

### 2026-09-29 · 李昊轩

**改动 1：前端 dev 代理改指向本地后端**

- 文件：`frontend/vite.config.js`（第 13-22 行 `server.proxy` 块）
- 改动前：`/api` 代理到 `https://oa.haoxuanyunshu.cn`（生产域名）
- 改动后：`/api` 代理到 `http://127.0.0.1:8899`（本地测试后端）
- 同时把 `secure: true` 改为 `secure: false`（本地走 HTTP 不需要 SSL 校验）
- 影响：仅影响**本地开发**的 `npm run dev`；生产构建产物（`public/app/`）不受影响，生产部署仍然走 Nginx + Laravel 直连
- 提交：`3864dab` chore(frontend): vite dev proxy 改指向本地后端 127.0.0.1:8899

---

## 2026-11-21 · 采购库合并收尾（v2026.11.20.3）

- 背景：用户批准把采购独立 MySQL 并入主库。只读核查确认采购连接早已指向 `payroll`（`PROC_DB_NAME=payroll`），旧库 `gy_procurement` 仅剩陈旧副本。
- 改动：新增 `deploy/merge-procurement-db.sh`（幂等；旧库存在时补差+核验，旧库不存在时纯核验）；旧库 dump 到 `/root/gy_procurement_deprecated_20261120.sql.gz` 后 `DROP DATABASE`；删除前后主库 12 张采购表行数与金额合计逐项一致。
- 文档：新增 `docs/数据库说明.md`；重写 `DEPLOYMENT.md`；补齐 `docs/项目开发文档.md` 第十二章；纠正 `docs/项目交接文档.md` 的过期生产 IP 与旧库删除记录。
- 未改动：薪酬表结构、核算逻辑、导出与汇总口径、采购业务代码。
- 提交：`576ee1a` docs（changelog 提交另行记录）。

