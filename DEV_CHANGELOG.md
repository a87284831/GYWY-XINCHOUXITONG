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
