#!/bin/bash
# 后端代码部署脚本（含 artisan 命令，2026-10-25 登录 500 事故后固化）
# ============================================================
# 适用场景：本次发布包含 PHP 代码变更（新接口/迁移/服务类等）
# 仅前端构建产物变更请用 deploy-frontend-full.sh，不要跑本脚本
#
# 事故背景：2026-10-25 部署时以 root 身份运行 php artisan migrate/cache:clear/route:cache，
# 新生成的缓存文件属主变成 root，PHP-FPM (www-data) 无法写入 →
# 登录接口 Cache::put 抛 file_put_contents 失败 → 异常处理器写 laravel.log 同样被拒 →
# 递归异常，前端收到 500 "Server Error"。
#
# 核心规则（不可违反）：
#   1. 所有 php artisan 命令必须用 sudo -u www-data 执行
#   2. 部署末尾必须重置 storage 和 bootstrap/cache 的属主为 www-data:www-data
#   3. PHP-FPM 用户是 www-data，不是 www（宝塔默认是 www，本机已改为 www-data）
# ============================================================
set -e
REMOTE=/var/www/payroll/laravel-app
TS=$(date +%Y%m%d_%H%M%S)

echo "=== 0. 部署前快照 ==="
mkdir -p /root/pre_deploy_${TS}
tar -czf /root/pre_deploy_${TS}/app_code.tar.gz -C $REMOTE app routes config database/migrations
echo "快照: /root/pre_deploy_${TS}/app_code.tar.gz"

echo "=== 1. 同步代码（默认假设已通过 scp 上传 /tmp/backend-bundle.tar.gz） ==="
if [ -f /tmp/backend-bundle.tar.gz ]; then
  tar -xzf /tmp/backend-bundle.tar.gz -C $REMOTE
  echo "代码包解压完成"
else
  echo "!!! /tmp/backend-bundle.tar.gz 不存在，请先 scp 上传代码包 !!!"
  exit 1
fi

echo "=== 2. 跑迁移（用 www-data 身份，避免属主污染） ==="
sudo -u www-data php $REMOTE/artisan migrate --force

echo "=== 3. 清缓存（用 www-data 身份） ==="
sudo -u www-data php $REMOTE/artisan config:clear
sudo -u www-data php $REMOTE/artisan cache:clear
sudo -u www-data php $REMOTE/artisan route:clear
sudo -u www-data php $REMOTE/artisan view:clear

echo "=== 4. 重建缓存（用 www-data 身份） ==="
sudo -u www-data php $REMOTE/artisan config:cache
sudo -u www-data php $REMOTE/artisan route:cache
sudo -u www-data php $REMOTE/artisan view:cache

echo "=== 5. 重置 storage / bootstrap/cache 权限（双保险，防意外） ==="
chown -R www-data:www-data $REMOTE/storage $REMOTE/bootstrap/cache
chmod -R 775 $REMOTE/storage $REMOTE/bootstrap/cache
mkdir -p $REMOTE/storage/framework/cache/data
chown -R www-data:www-data $REMOTE/storage/framework/cache/data

echo "=== 6. 重载 PHP-FPM ==="
systemctl reload php8.2-fpm

echo "=== 7. 冒烟测试 ==="
LOGIN_CODE=$(curl -sk -o /dev/null -w "%{http_code}" -X POST https://oa.haoxuanyunshu.cn/api/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"username":"admin","password":"wrong_password_for_smoke"}')
echo "POST /api/login (错误密码) -> $LOGIN_CODE (期望 400)"
if [ "$LOGIN_CODE" != "400" ]; then
  echo "!!! 登录接口异常（期望 400，实际 $LOGIN_CODE），请检查 !!!"
  exit 1
fi

echo "=== 部署完成 ==="
echo "回滚：tar -xzf /root/pre_deploy_${TS}/app_code.tar.gz -C $REMOTE"
