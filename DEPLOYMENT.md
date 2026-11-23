# 部署与运维手册

> 更新日期：2026-11-21 ｜ 适用：物业薪酬管理系统生产环境
> 读者：接手开发/运维。本文与 `docs/数据库说明.md`、`docs/项目开发文档.md` 配套，可脱离对话独立使用。
> 生产域名：`oa.haoxuanyunshu.cn` 与 `www.haoxuanyunshu.cn`（Laravel 系统）；裸域 `haoxuanyunshu.cn` 为 landing 页。旧机（已退役）的 IP/域名已从仓库清除，**不要使用**。

---

## 1. 生产环境

| 项 | 值（2026-11-21 实测） |
|---|---|
| 服务器 | `120.53.8.216` |
| 系统 | Ubuntu 22.04.5 LTS，内核 5.15 |
| 规格 | 2 核 / 3.7G 内存 / 59G 磁盘（已用约 9.8G） |
| SSH | `ubuntu@120.53.8.216:22`（`payroll_deploy` 密钥，`ubuntu` 有 passwordless sudo；私钥禁止写入仓库） |
| Web 根目录 | `/var/www/payroll/laravel-app/public` |
| 应用目录 | `/var/www/payroll/laravel-app` |
| PHP | 8.2.35 CLI + `php8.2-fpm`，FPM 运行用户 **www-data** |
| Nginx | 1.18（apt 安装，配置在 `/etc/nginx/sites-enabled/`） |
| MySQL | 8.0.45，`127.0.0.1:3306`，业务库 `payroll` |
| 缓存在线 | `CACHE_STORE=file`，`QUEUE_CONNECTION=sync` |
| 时区 | Asia/Shanghai |
| 备份目录 | `/var/backups/payroll-db/` |

必需 PHP 扩展：`pdo_mysql`、`mbstring`、`fileinfo`、`zip`、`gd`、`openssl`。

---

## 2. 目录与关键文件

```text
/var/www/payroll/laravel-app/
├── app/                        业务代码（Purchase/Finance 使用独立 PDO）
├── routes/api.php              API 路由
├── config/procurement.php      采购连接配置
├── config/finance.php          财务连接配置
├── database/migrations/        Laravel 迁移
├── changelog.json              版本日志（/api/changelog 读取）
├── storage/                    日志、缓存、上传、导入快照（www-data:www-data 775）
├── bootstrap/cache/            配置/路由缓存（www-data:www-data 775）
├── public/app/                 前端构建产物（不入 git，整包部署）
└── deploy/                     发布与运维脚本
    ├── deploy-backend.sh
    ├── deploy-frontend-full.sh
    └── merge-procurement-db.sh
```

---

## 3. 发布前检查

1. 本地全量测试通过：
   - `php artisan test`（当前基线 504 tests / 3592 assertions）
   - `cd frontend && npm.cmd test`（28 文件 / 373 用例）
   - `npm run build` 成功
2. 版本号顺延并写入 `changelog.json` 顶部（格式 `vYYYY.MM.DD.N`，不覆盖他人条目）。
3. 服务器快照（数据库 + 代码 + 前端），记录路径与 md5：

```bash
TS=$(date +%Y%m%d_%H%M%S)
sudo mkdir -p /root/pre_deploy_$TS
sudo mysqldump --single-transaction --no-tablespaces --routines --triggers \
  --databases payroll gy_finance > /tmp/db_$TS.sql
sudo gzip -c /tmp/db_$TS.sql | sudo tee /root/pre_deploy_$TS/db.sql.gz >/dev/null
sudo rm -f /tmp/db_$TS.sql
sudo gzip -t /root/pre_deploy_$TS/db.sql.gz
sudo tar -czf /root/pre_deploy_$TS/app_code.tar.gz \
  -C /var/www/payroll/laravel-app app routes config database/migrations changelog.json
sudo tar -czf /root/pre_deploy_$TS/app_front.tar.gz \
  -C /var/www/payroll/laravel-app/public app
sudo md5sum /root/pre_deploy_$TS/*
```

4. 只改前端时不要跑后端发布；只改后端时不要重传前端整包。

---

## 4. 后端发布

### 4.1 打包上传

```powershell
# 本地，laravel-app 根目录
tar -czf $env:TEMP\backend-bundle.tar.gz app routes config database/migrations changelog.json
scp -i $HOME\.ssh\payroll_deploy $env:TEMP\backend-bundle.tar.gz ubuntu@120.53.8.216:/tmp/
scp -i $HOME\.ssh\payroll_deploy deploy/deploy-backend.sh ubuntu@120.53.8.216:/tmp/
```

### 4.2 执行

```bash
# 脚本会写 /root 快照，必须用 sudo 运行
ssh -i ~/.ssh/payroll_deploy ubuntu@120.53.8.216 "sudo bash /tmp/deploy-backend.sh"
```

脚本固定顺序：部署前快照 → 解压代码 → `sudo -u www-data php artisan migrate --force` → 清缓存 → 重建 `config/route/view` 缓存 → 重置 `storage`/`bootstrap/cache` 属主 → `systemctl reload php8.2-fpm` → 错误密码登录冒烟（期望 400）。

### 4.3 硬性规则

- **所有 `php artisan` 必须用 `sudo -u www-data` 执行**。root 执行会把缓存/日志文件属主改成 root，导致 www-data 登录时写缓存失败并返回 500。
- `route:cache` 前先 `route:clear`。
- 若 `view:cache` 报 `resources/views` 不存在，先 `mkdir -p resources/views` 再重建。
- 生产环境 `migrate` 会提示确认，必须加 `--force`。

---

## 5. 前端整包发布

前端产物 `public/app` 不入 git，Vite 每次构建都会生成新的哈希文件，**必须整包同步，严禁手动挑文件**（两次白屏事故均由此产生）。

```powershell
cd frontend
npm.cmd run build
cd ..
tar -czf $env:TEMP\app-bundle.tar.gz -C public app
scp -i $HOME\.ssh\payroll_deploy $env:TEMP\app-bundle.tar.gz ubuntu@120.53.8.216:/tmp/
scp -i $HOME\.ssh\payroll_deploy deploy/deploy-frontend-full.sh ubuntu@120.53.8.216:/tmp/
ssh -i $HOME\.ssh\payroll_deploy ubuntu@120.53.8.216 "sudo bash /tmp/deploy-frontend-full.sh"
```

脚本会：备份 `index.html` → 解压覆盖 → 包完整性检查 → 逐个校验 `index.html` 引用资源返回 200 → 清理旧哈希产物 → 清理后复检 200 → 重置 storage 属主。

部署后建议在本地比对 md5：

```powershell
# 本地
Get-ChildItem -Recurse -File public/app | ForEach-Object { ... }
# 服务器
ssh -i $HOME\.ssh\payroll_deploy ubuntu@120.53.8.216 "cd /var/www/payroll/laravel-app && find public/app -type f -exec md5sum {} +"
```

注意：Windows 生成的清单若带 CRLF，服务器 `md5sum -c` 会报 `$'\r'`；在服务器端 `tr -d '\r'` 后再校验。

---

## 6. 数据库变更

- Laravel 迁移：放 `database/migrations/`，随后端发布执行。
- 采购/财务不使用 Eloquent 连接，而是 `app/Purchase/Support.php::db()`、`app/Finance/Support.php::fdb()` 建独立 PDO；配置分别来自 `.env` 的 `PROC_DB_*` 与 `FINANCE_DB_DATABASE`。
- 任何 DDL/数据修复前先按第 3 节做数据库快照；只读核对优先，写入必须可回滚。
- 采购库合并/核验脚本：

```bash
cd /var/www/payroll/laravel-app
bash deploy/merge-procurement-db.sh
```

旧库存在时补差并核验，旧库不存在时退化为纯核验；不触碰 `payroll_*` 薪酬表。详见 `docs/数据库说明.md`。

---

## 7. cron 与属主

### 7.1 当前 root crontab（2026-11-21 实测）

```cron
0 3 * * *   /usr/local/bin/payroll-backup.sh
* * * * *   cd /var/www/payroll/laravel-app && sudo -u www-data /usr/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * *   /usr/local/bin/cpu-watchdog.sh >> /var/log/cpu-watchdog.log 2>&1
```

Laravel 调度任务（`routes/console.php`）：

| 任务 | 频率 | 说明 |
|---|---|---|
| `invoice:retry-deliveries` | 每分钟 | 开票邮件重试 |
| `dingtalk:sync` | 每日 02:00 | 钉钉花名册同步 |
| `maintenance:scan-alerts` | 每日 08:30 | 维保告警扫描 |

三个任务都带 `withoutOverlapping()` / `onOneServer()`，依赖文件缓存锁。

### 7.2 属主要求（必须遵守）

- `storage/`、`bootstrap/cache/` 必须属于 `www-data:www-data`，目录 775。
- 任何 artisan 命令用 `sudo -u www-data php artisan ...`，必要时加 `HOME=/tmp`。
- 发布末尾固定执行：

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
mkdir -p storage/framework/cache/data
chown -R www-data:www-data storage/framework/cache/data
```

### 7.3 属主现状

当前 root crontab 的 `schedule:run` 已使用 `sudo -u www-data`，不会再生成 root 属主缓存目录。所有手工 artisan 命令仍必须用 `sudo -u www-data`；若发现 `storage/framework/cache/data/**` 属主异常，按第 10 节处理。

---

## 8. 备份与恢复

- 脚本：`/usr/local/bin/payroll-backup.sh`，root crontab 每日 03:00。
- 内容：`payroll` 全库 dump + `.env` + 关键存储目录 + 源代码。
- 目录：`/var/backups/payroll-db/`，每日份保留 7 天；每月 1 日另存 `monthly/`（约 13 个月）。
- 恢复：

```bash
gunzip < /var/backups/payroll-db/payroll_YYYYMMDD_HHMMSS.sql.gz | mysql -uroot -p
```

- 备份脚本只 dump `payroll`；`gy_finance` 为只读历史副本，不再每日备份；`gy_procurement` 已于 2026-11-20 退役，退役前 dump 在 `/root/gy_procurement_deprecated_20261120.sql.gz`。

---

## 9. 冒烟清单

发布后至少确认：

```bash
curl -sk -o /dev/null -w "/app/ %{http_code}\n" https://oa.haoxuanyunshu.cn/app/
curl -sk -o /dev/null -w "/api/changelog %{http_code}\n" https://oa.haoxuanyunshu.cn/api/changelog
curl -sk -o /dev/null -w "/api/payroll %{http_code}\n" https://oa.haoxuanyunshu.cn/api/payroll
curl -sk -o /dev/null -w "/api/purchase/fill/items %{http_code}\n" https://oa.haoxuanyunshu.cn/api/purchase/fill/items
```

- `/app/` = 200；未登录 API = 401；`/api/changelog` 返回新版本号。
- 前端入口及 `index.html` 引用资源全部 200。
- `storage/logs/laravel.log` 无新增 ERROR；`/var/log/nginx/error.log` 无新增异常。
- 如涉及薪酬：`php artisan payroll:recalc --dry-run` 应零写库、组数稳定。
- 如涉及采购：填报查询、导入预览、导出、归档状态接口均可用。

---

## 10. 常见故障与处理

| 现象 | 原因 | 处理 |
|---|---|---|
| 打开 `/app/` 白屏，控制台入口 JS 404 | 前端只挑了部分文件上传，`index.html` 引用的哈希文件缺失 | 按第 5 节整包重传；`deploy-frontend-full.sh` 会自检并回滚 `index.html` |
| 登录返回 500 `Server Error`，日志写不进去 | root 跑过 artisan，`storage`/`bootstrap/cache` 属主变 root | `chown -R www-data:www-data storage bootstrap/cache`，`chmod -R 775`，再 reload FPM |
| `php artisan schedule:list` 报 `fopen(.../cache/data/xx/xx): No such file or directory` | 缓存子目录属主为 root，www-data 无法创建/写入；`withoutOverlapping` 需要锁文件 | 重置 `storage/framework/cache/data` 属主；长期方案是把 crontab 改为 `sudo -u www-data php artisan schedule:run`（需用户批准） |
| `view:cache` 报 `resources/views` 不存在 | 部署包未带空目录 | `mkdir -p resources/views` 后重建缓存 |
| `migrate` 在 production 提示 cancelled | 未加 `--force` | `sudo -u www-data php artisan migrate --force` |
| 前端 md5 校验报 `$'\r'` | Windows 清单 CRLF | 服务器端 `tr -d '\r'` 后再 `md5sum -c` |
| `git push` 报 `Failed to connect to github.com:443 over proxy` | 本机代理 `127.0.0.1:7898` 不可达 | 如实报错，不改 git 配置、不 force push；等代理恢复后重试 |
| 采购接口返回 403/空列表 | 项目账号未绑定项目或跨项目访问 | 查 `payroll_accounts.project_name` 能否映射到 `payroll_projects`；越权会写 `op_logs`「越权拒绝」 |
| 数据库连接失败 | `.env` 数据库配置错误或 MySQL 未启动 | 核对 `DB_*`/`PROC_DB_*`；`systemctl status mysql`；用 `mysql -upayroll -p payroll -e 'select 1'` 验证 |
| `/api/changelog` 仍是旧版本 | `changelog.json` 未同步或缓存未清 | 同步文件后 `sudo -u www-data php artisan config:clear && config:cache` |

---

## 11. 回滚

```bash
# 代码
tar -xzf /root/pre_deploy_<TS>/app_code.tar.gz -C /var/www/payroll/laravel-app

# 前端整包
tar -xzf /root/pre_deploy_<TS>/app_front.tar.gz -C /var/www/payroll/laravel-app/public

# 数据库（会覆盖当前库，先确认）
sudo gunzip < /root/pre_deploy_<TS>/db.sql.gz | sudo mysql

# 缓存与权限
cd /var/www/payroll/laravel-app
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo systemctl reload php8.2-fpm
```

回滚后按第 9 节重新冒烟。

---

## 12. 2026-11-20 采购库合并（运维备注）

- 采购早已通过 `PROC_DB_NAME=payroll` 使用主库；本次完成旧库 `gy_procurement` 退役。
- 退役前 dump：`/root/gy_procurement_deprecated_20261120.sql.gz`（14 表，`gzip -t` 通过，md5 `117bc494416679034328bd9f8986dcdf`）。
- 删除前后主库 12 张采购表行数与金额合计完全一致；`payroll` 69 表、`gy_finance` 6 表未受影响。
- 代码零业务改动；新增 `deploy/merge-procurement-db.sh` 供以后核验/重建。
- 回滚：`sudo gunzip < /root/gy_procurement_deprecated_20261120.sql.gz | sudo mysql` 可重建旧库；但**不可把 `PROC_DB_NAME` 切回旧库运行**（结构落后）。
- 详细字段与合并记录见 `docs/数据库说明.md` 第 5、6 节。

---

## 13. 发布记录

版本与逐次部署结果见 `changelog.json`（前端「更新日志」）与 `docs/开发记录.md`（流水账）。最近版本：`v2026.11.20.3`。
