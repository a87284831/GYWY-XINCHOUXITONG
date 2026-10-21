# 薪酬核算系统

面向物业服务企业的内部一体化管理平台，核心是**薪酬核算**（项目员工/案场/管理/总部四类人员），并集成：考勤上传与符号库、钉钉花名册同步、个税独立计税、五险一金、绩效（月度/季度/半年度）、财务台账、采购申报、维保台账、审批流（OA）、组织架构、报表导出、工资条查询。

## 文档索引

| 文档 | 位置 | 用途 |
|---|---|---|
| **联合开发指南** | [docs/联合开发指南.md](docs/联合开发指南.md) | 协作工作流、分支/PR/部署规范，**新成员必读** |
| 项目开发文档 | [docs/项目开发文档.md](docs/项目开发文档.md) | 活文档，每次开发后追加记录 |
| 项目交接文档 | workspace 根目录（不入库） | 生产环境事实、备份机制、运维 runbook |

## 本地开发快速开始

### 环境要求

| 组件 | 版本 |
|---|---|
| PHP | 8.2+（扩展：pdo_sqlite/mysql、mbstring、zip、gd、fileinfo） |
| Composer | 2.x |
| Node.js | 20+（前端构建与测试） |

> Windows 本机无 PHP/Node 时，可用绿色版方案，见 `.trae/skills/windows-portable-php-composer/`。

### 首次设置

```bash
git clone git@github.com:a87284831/haoxuanyunshu.git laravel-app
cd laravel-app

composer install
cp .env.example .env
# .env 默认即可本地运行（SQLite）；向管理员索取 data/ 配置目录副本放到 ../data

php artisan key:generate
php artisan migrate          # 自动创建 database/local.sqlite
php artisan payroll:init-admin admin 你的密码   # 创建本地管理员（密码至少8位）
```

> **没有默认账号密码。** 数据库为空时必须先用上面的命令创建管理员才能登录。
> 如需演示数据（17 个项目 + 历史工资），可运行 `php database/seed_demo_payroll.php`（**仅限本地，生产严禁**）。

### 启动本地服务

```powershell
# 不要用 artisan serve（/app 目录导致 SPA 刷新 404），用专用 router：
php -S 127.0.0.1:8910 -t public server_router.php
```

前端开发（可选，改前端源码时）：

```bash
cd frontend
npm ci
npm run dev      # 或 npm run build 后由后端托管
```

### 测试（提交前必跑）

```bash
php vendor/bin/phpunit        # 后端（:memory: SQLite）
cd frontend && npm run test   # 前端 vitest
npm run build                 # 验证构建不破坏
```

## 协作工作流（简版）

```
git pull origin main          # 每次开发前
git checkout -b feat/xxx      # 一个功能一个分支
# 开发 + 本地测试
git push origin feat/xxx      # 提 PR → CI 绿 → review → 合并 main
# 合并后由指定人员从 main 构建并部署（见联合开发指南）
```

详细规范（分支命名、数据库变更纪律、部署纪律、数据安全红线）见 [docs/联合开发指南.md](docs/联合开发指南.md)。

## 生产部署（指定人员执行）

- 前端：`deploy/deploy-frontend-full.sh`（整包同步 + 资源自检 + 失败自动回滚）
- 后端：快照 → 同步 PHP 文件 → `php artisan migrate --force`（如有迁移）→ 缓存重建 → 冒烟
- **禁止在生产服务器上改代码、禁止在服务器上 npm build**

## 功能概览

- **钉钉集成**：通讯录/花名册/离职名单同步，定时全量同步（凌晨 2 点）+ 事件回调（可选）
- **人员档案**：按项目/部门/岗位管理，薪资、职级、试用期、转正日期随钉钉同步
- **考勤管理**：Excel 模板下载、按人上传合并、锁定防篡改
- **工资核算**：逐日分段（试用期/转正/离职当月）、绩效三模式（月度/分期兑现/季度绩效法）、错误码显式失败不静默降级
- **采购管理**：项目填报 → 管理员确认 → 清单外审核（归入/新增规格/忽略）→ 导出报价 → 导入价格 → 归档
- **财务台账**、**维保台账**、**审批流（OA）**、**绩效模块**、**报表导出**

## 目录结构

```
laravel-app/
├── app/
│   ├── Console/Commands/     # 钉钉同步、管理员初始化、重算命令
│   ├── Http/Controllers/Api/ # 全部业务接口
│   └── Services/             # 核算引擎、钉钉服务、规则读取
├── database/migrations/      # 数据库结构（变更只允许走迁移）
├── frontend/                 # Vue3 + Vite 前端源码（构建输出到 public/app）
├── public/app/               # 前端构建产物（不入库，部署时构建）
├── deploy/                   # 部署脚本
├── docs/                     # 开发文档（活文档）
└── routes/                   # 路由与调度定义
```
