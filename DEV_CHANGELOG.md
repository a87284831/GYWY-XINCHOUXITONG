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

### 2026-09-29 · 李昊轩

**改动 1：前端 dev 代理改指向本地后端**

- 文件：`frontend/vite.config.js`（第 13-22 行 `server.proxy` 块）
- 改动前：`/api` 代理到 `https://oa.haoxuanyunshu.cn`（生产域名）
- 改动后：`/api` 代理到 `http://127.0.0.1:8899`（本地测试后端）
- 同时把 `secure: true` 改为 `secure: false`（本地走 HTTP 不需要 SSL 校验）
- 影响：仅影响**本地开发**的 `npm run dev`；生产构建产物（`public/app/`）不受影响，生产部署仍然走 Nginx + Laravel 直连
- 提交：`3864dab` chore(frontend): vite dev proxy 改指向本地后端 127.0.0.1:8899

---
