# 昊轩云枢 一次性架构整合与性能优化 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 一次性完成垃圾文件清理部署、静态资源缓存/压缩、财务图表修复、token 传递安全化、采购驾驶舱缓存、iframe 保活，显著改善采购/财务模块加载速度。

**Architecture:** 保持现有 iframe 多前端架构不变（采购 SPA 无源码，完整统一不可行）。所有改动在本地完成并经用户确认后，按任务分批部署到服务器（120.53.8.216），每次部署前先打包备份。

**Tech Stack:** Laravel 12 / PHP 8.2 / Nginx（宝塔）/ 原生 JS SPA / Vite 产物（只读补丁）/ MySQL 8 双库（payroll + gy_procurement）

**Spec:** 本会话架构评估报告（2026-09-26），要点：采购/财务缓慢的根因 = 无 gzip/缓存（1.18MB 主 chunk 每次重下）+ 12 请求驾驶舱风暴 + iframe 冷启动；多前端架构是间接放大器。

## Global Constraints

- **所有服务器操作必须先经用户逐项确认**（用户明确要求）
- 服务器删除前必须先打包备份到 `/root/`，命名 `<what>_backup_<date>.tar.gz`
- 本地改动先 `php -l` 语法检查再提交；正式测试（`PayrollParityTest` 等）在服务器部署前跑通
- 不修改采购 SPA 的打包产物 JS（`public/purchase/assets/*.js`），只允许在其 `index.html` 注入补丁脚本（沿用 `__dashAllShim` 既有模式）
- 不动 `vendor/`、`storage/` 业务数据；不提交 `.env`
- git 提交信息用中文，风格参照 `git log`（如"修复清单外审核双加载根因: ..."）
- 服务器站点配置真实路径：`/etc/nginx/sites-enabled/oa.haoxuanyunshu.cn`（本地样例 `nginx.conf.example` 仅供参考）

## Review Focus

以下五个最可能咬人的失败模式，每个都绑定了验证任务：

1. **采购 token 存储键未知** → 注入 shim 存错 localStorage 键会导致采购登录死循环。验证：Task 6 部署后浏览器完整登录→采购流程。绑定 Task 4 发现步骤。
2. **驾驶舱缓存陈旧** → 填报/审核后数字不更新，用户误以为丢数据。验证：Task 8 填报后 5 分钟内看驾驶舱刷新。绑定 Task 5。
3. **iframe 保活的跨账号残留** → 退出后另一账号登录，仍见上一账号的采购/财务会话。验证：Task 8 登出换号检查。绑定 Task 6。
4. **缓存头与发版冲突** → index.html 被缓存导致新旧 chunk 混用 404。验证：Task 11 curl 确认 index.html 不缓存、assets 长缓存。绑定 Task 3。
5. **echarts 路径仍错** → 财务图表区空白。验证：Task 11 curl 实际 URL 返回 200。绑定 Task 4。

---

### Task 0: 本地 git 基线提交（回滚锚点）

**Files:** 无新文件；提交当前工作区全部既有改动（这些改动 = 当前线上运行状态，来自先前会话）

**Interfaces:** Produces: 干净基线 commit，后续任务的 diff 只含本次改动

- [ ] **Step 1: 确认工作区与服务器一致**

Run: `git -C laravel-app status --short | wc -l`（预期与清理后状态一致，~30 行）

- [ ] **Step 2: 全量提交为基线**

```bash
git add -A
git commit -m "基线: 与线上 12cb42b 部署状态对齐（含未提交的钉钉/财务/清理改动）"
```

- [ ] **Step 3: 确认基线 hash 并记录**

Run: `git log --oneline -1`，把 hash 告知用户

### Task 1: 财务 echarts 引用修复

**Files:**
- Modify: `public/finance/index.html:7`（`<link rel="stylesheet" href="./assets/echarts.min.js">` → `<script src="./echarts.min.js">`）

**Interfaces:** Consumes: 无。Produces: `window.echarts` 在财务 SPA 初始化前可用

- [ ] **Step 1: 修正引用**

`<link rel="stylesheet" href="./assets/echarts.min.js" />` 改为 `<script src="./echarts.min.js"></script>`，放在 `<div id="app">` 之前（文件实际位于 `finance/` 根目录，非 `assets/` 子目录）

- [ ] **Step 2: 语法检查 + 本地确认文件存在**

Run: `php -l public/finance/index.html` 跳过（HTML 无 php）；确认 `public/finance/echarts.min.js` 存在（已确认，1001KB）

- [ ] **Step 3: Commit**

```bash
git add public/finance/index.html
git commit -m "财务: 修复 echarts 错误的 stylesheet 引用导致图表库不加载"
```

### Task 2: token 传递去 URL 化（同源共享存储方案）

**关键设计决策（已定）:** 主 SPA 与 iframe **同源同 tab**，sessionStorage 天然共享 → 无需 postMessage。方案：主 SPA 登录后把 token 写入 `sessionStorage.gw_token`（财务已读此键）；采购侧若只认 URL 参数，则在 `purchase/index.html` 注入 shim（学 `__dashAllShim` 模式）在应用启动前从 sessionStorage 读 token 存入采购 bundle 实际使用的键。

**Files:**
- Modify: `public/static/app.js:285-394`（openPurchase/openFinance）
- Modify: `public/purchase/index.html`（注入 token shim，位于 `__dashAllShim` 之前）

**Interfaces:** Consumes: 采购 bundle 的 token 键名（Step 1 发现）。Produces: iframe URL 不再携带 `?token=`

- [ ] **Step 1: 发现采购 bundle 的 token 获取方式**

在 `public/purchase/assets/api-h9mtjBgk.js`（51KB，可读）中搜 `token`、`localStorage`、`sessionStorage`、`URLSearchParams`，确定：a) 是否读 URL `?token=`；b) 存储键名。记录结论。

- [ ] **Step 2a: 若采购读 URL 参数 → 注入 shim**

在 `purchase/index.html` 的 `__dashAllShim` 注释块前插入：

```html
<script>
/* token shim: 优先从 sessionStorage(主SPA共享) 取 token，兼容 URL ?token= */
(function () {
  try {
    var q = new URLSearchParams(location.search).get('token');
    var t = q || sessionStorage.getItem('gw_token') || '';
    if (t) { sessionStorage.setItem('gw_token', t); localStorage.setItem('<Step1发现的键>', t); }
  } catch (e) {}
})();
</script>
```

（若 Step 1 发现采购已兼容 sessionStorage，则 Step 2a 跳过，仅主 SPA 侧去 URL）

- [ ] **Step 3: 主 SPA 去掉 URL 传参**

`app.js` 的 `openPurchase()`/`openFinance()`：登录成功后已有 token 变量 → `sessionStorage.setItem('gw_token', token)`；iframe src 去掉 `?token=` 拼接（保留函数内 token 变量供其他用途）

- [ ] **Step 4: 语法与回归检查**

Run: `node -c public/static/app.js`（或 `node --check`）；人工过一遍 diff 确认无遗漏拼接

- [ ] **Step 5: Commit**

```bash
git add public/static/app.js public/purchase/index.html
git commit -m "安全: token 不再走 URL 传参, 同源 sessionStorage 共享 + 采购 shim 兜底"
```

### Task 3: Nginx gzip + 静态缓存配置（本地准备，部署经确认）

**Files:**
- Create: `deploy/nginx-haoxuanyunshu.conf`（本地准备完整 server 块）
- Modify（部署时）: 服务器 `/etc/nginx/sites-enabled/oa.haoxuanyunshu.cn`

**Interfaces:** Produces: 带 hash 的 assets 长缓存 + 文本资源 gzip + index.html 不缓存

- [ ] **Step 1: 本地读取服务器现有配置（只读）**

`ssh root@120.53.8.216 "cat /etc/nginx/sites-enabled/oa.haoxuanyunshu.cn"`，以其为基底（不是以 nginx.conf.example）

- [ ] **Step 2: 在 server 块内追加三段**

```nginx
gzip on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_types text/css application/javascript application/json image/svg+xml;

location /purchase/assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
location ~* \.(js|css|woff2?|png|jpg|svg)$ { expires 7d; add_header Cache-Control "public"; }
```

注意：现有 `location /` 的 try_files 与 `\.php$` 块保持原样；`index.html` 与 `purchase/index.html`、`finance/index.html` **不加**长缓存（HTML 默认无 expires 即可）

- [ ] **Step 3: 语法自检**

本地通读配置确认无重复 location 冲突；记录"部署时必须先 `nginx -t` 再 reload"

- [ ] **Step 4: Commit**

```bash
git add deploy/nginx-haoxuanyunshu.conf
git commit -m "运维: 准备 nginx gzip+静态缓存配置(部署待确认)"
```

### Task 4: 采购驾驶舱后端缓存

**Files:**
- Modify: `app/Purchase/handlers/dashboard.php`（`/dashboard/all` 聚合入口处）

**Interfaces:** Consumes: Laravel `Cache` facade（handler 在 PurchaseController 内被 require，Laravel 容器可用）。Produces: 缓存键 `purchase_dash_all:{month}:{filter-hash}`，TTL 300 秒

- [ ] **Step 1: 定位 dashboard/all 聚合入口**

在 dashboard.php 中找到处理 `dashboard/all` 的函数（对应前端 MERGED_KEYS 聚合的 12 子查询合并响应），确认其入参（month、项目过滤）

- [ ] **Step 2: 包裹缓存**

聚合结果数组生成处包裹：键 = `'purchase_dash_all:' . $month . ':' . md5(json_encode($filters))`，`Cache::remember($key, 300, fn() => $computed)`。**不做显式失效**（权衡：数字最多滞后 5 分钟，换取不必侵入 fill/admin 多个 handler；若用户不接受再改为写操作 Cache::forget）

- [ ] **Step 3: 语法检查**

Run: `php -l app/Purchase/handlers/dashboard.php` → 无错

- [ ] **Step 4: Commit**

```bash
git add app/Purchase/handlers/dashboard.php
git commit -m "采购: 驾驶舱聚合查询加 5 分钟缓存, 消除每次进入的 12 组 SQL 重算"
```

### Task 5: iframe 保活（二次进入秒开）

**Files:**
- Modify: `public/static/app.js:285-394`（openPurchase/openFinance + 登出逻辑）

**Interfaces:** Consumes: Task 2 产出的 token 共享约定。Produces: 模块级 `iframeCache = { purchase: null, finance: null }`

- [ ] **Step 1: 改造 open 函数为"创建一次、显示切换"**

每次调用：若缓存实例存在 → 显示并 `iframeWin.postMessage`（或直接依赖 sessionStorage，因 Task 2 已同源共享）→ return；否则创建 iframe、存入缓存。切换离开时不 remove，只 hide

- [ ] **Step 2: 登出与账号切换时销毁**

在登出处理（app.js 中 logout 流程）追加：销毁两个缓存 iframe（remove DOM + 置 null）+ `sessionStorage.removeItem('gw_token')`，防止跨账号残留（对应 Review Focus #3）

- [ ] **Step 3: 语法检查 + diff 自查**

Run: `node --check public/static/app.js`；确认登出路径覆盖 purchase/finance 两个 iframe

- [ ] **Step 4: Commit**

```bash
git add public/static/app.js
git commit -m "前端: 采购/财务 iframe 保活, 二次进入免冷启动; 登出销毁防串号"
```

### Task 6: 服务器垃圾文件清理部署（备份→删除→健康检查）

**Files:** 服务器 `/var/www/payroll/laravel-app/` 下 36 个文件（与本地清理清单一致）+ `public/purchase/assets_bak_20260912/`（2.21MB 公网暴露的旧产物）+ `public/finance/` 乱码 xlsx

**Interfaces:** Consumes: 本地 Task 0 基线。Produces: 服务器与本地工作区一致

- [ ] **Step 1: 【经用户确认后】打包备份**

```bash
tar czf /root/cleanup_backup_20260926.tar.gz -C /var/www/payroll/laravel-app \
  check_rules.php _check_staff.php check_sym.php diag_suntao.php final_test*.php \
  fix_rules.php fix_sym.php fix_sym2.php test_*.php StaffController.php TemplateController.php \
  public/static/app.js.bak* public/index.html.bak_settings public/purchase/assets_bak_20260912 \
  app/Services/*.bak.* app/Purchase/handlers/*.bak_* app/Http/Controllers/Api/*.bak* \
  database/db.pre_reg.bak routes/api.php.bak* .env.bak_*
```

- [ ] **Step 2: 【经用户确认后】删除**

同清单 rm -rf（含 assets_bak 目录与乱码 xlsx）

- [ ] **Step 3: 健康检查**

`curl -s https://oa.haoxuanyunshu.cn/up` → `Application up`；抽查采购/财务页面 200

### Task 7: Nginx 配置部署

- [ ] **Step 1: 【经用户确认后】上传并备份现配置**

`cp /etc/nginx/sites-enabled/oa.haoxuanyunshu.cn /root/nginx_conf_backup_20260926.conf`，再覆盖为新配置

- [ ] **Step 2: nginx -t 通过后 reload**

`nginx -t && systemctl reload nginx`；失败则立即还原备份

- [ ] **Step 3: 验证响应头**

`curl -sI https://oa.haoxuanyunshu.cn/purchase/assets/index-I9x5KFB--v3.js | grep -iE 'content-encoding|expires|cache-control'` → 应见 gzip + immutable；`curl -sI .../purchase/index.html` → 无长缓存

- [ ] **Step 4: 顺带核查 OPcache**

`php -r "echo ini_get('opcache.enable');"` → 应为 1；若为 0，记录并在 Task 8 部署时一并开启（宝塔 PHP 设置）

### Task 8: 后端代码部署 + 回归

- [ ] **Step 1: 【经用户确认后】打包备份服务器将被覆盖的文件**

- [ ] **Step 2: scp 上传 4 个改动文件**（finance/index.html、static/app.js、purchase/index.html、dashboard.php）

- [ ] **Step 3: 服务器跑正式测试**

`cd /var/www/payroll/laravel-app && php artisan test --filter=PayrollParity` → 全绿（Review Focus #2 的驾驶舱数据正确性由 parity 测试兜底）

- [ ] **Step 4: 线上冒烟**

浏览器：登录 → 采购首次进入（应明显变快）→ 二次进入（秒开）→ 填报一条 → 5 分钟内看驾驶舱 → 财务图表正常 → 登出换号无残留

### Task 9: 主 SPA app.js 拆分（469KB/7150 行 → 按模块多文件）

> **建议延后**：gzip 后仅约 120KB，收益边际；回归风险最高（7150 行手写 JS 无测试覆盖）。默认从本次范围剔除，用户在评审时明确要求才执行。

- [ ] **Step 1-5: （占位，仅在用户坚持时展开）** 按页面模块拆 defer script，共享全局命名空间，逐页人工回归

## 部署顺序与回滚

1. Task 0（本地）→ Task 1/2/4/5（本地编码+提交）→ Task 3（本地备配置）
2. Task 6 → 7 → 8（服务器，每步用户确认）
3. 回滚：代码 = 服务器 tarball 还原 / git revert 基线后重传；Nginx = 还原 conf 备份 reload
