# ============================================================
# 广盈物业薪酬系统 - 宝塔服务器端部署脚本
# 使用：首次 IMPORT_LEGACY=1 bash deploy.sh
#       后续                bash deploy.sh
# ============================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

# 1. 基础命令检查
command -v php     >/dev/null || { echo "错误：未找到 PHP，请在宝塔『软件商店』安装 PHP 8.2+ 并启用 fileinfo/mbstring/openssl/pdo_mysql/zip/gd 扩展" >&2; exit 1; }
command -v composer >/dev/null || { echo "错误：未找到 Composer，请先安装 Composer 2" >&2; exit 1; }

# 2. .env 初始化
if [ ! -f .env ]; then
  if [ -f .env.production ]; then
    cp .env.production .env
  else
    cp .env.example .env
  fi
  echo "已创建 .env，请填写以下占位符后重新运行："
  grep -E '__SET_|CHANGE_ME' .env || true
  exit 2
fi

# 3. 强制要求数据库密码已替换占位符
if grep -qE '__SET_DB_PASSWORD__|CHANGE_ME' .env; then
  echo "错误：.env 中仍有占位符（__SET_DB_PASSWORD__ / CHANGE_ME），请用实际密码替换后再运行" >&2
  exit 3
fi

# 4. 权限预热
mkdir -p storage/app/private/backups storage/app/private/maintenance storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R 775 storage bootstrap/cache

# 5. Composer
if [ ! -d vendor ] || [ composer.json -nt vendor/autoload.php ]; then
  composer install --no-dev --optimize-autoloader
fi

# 6. APP_KEY 仅在空时生成
if grep -q '^APP_KEY=$' .env; then
  php artisan key:generate --force
fi

# 7. 数据库迁移
php artisan migrate --force

# 8. 首次旧 JSON 导入
if [ "${IMPORT_LEGACY:-0}" = "1" ]; then
  echo "==> 导入旧 JSON 数据（仅首次）"
  php artisan db:seed --class=LegacyJsonSeeder --force
  php artisan db:seed --class=PayrollCoreSeeder --force
  php artisan db:seed --class=PayrollOperationSeeder --force
  php artisan db:seed --class=MaintenanceSeeder --force
  php artisan db:seed --class=PerformanceSeeder --force
fi

# 9. 优化
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==================================================="
echo "Laravel 部署完成"
echo "请在宝塔『网站』中创建站点："
echo "  域名：    oa.haoxuanyunshu.cn"
echo "  根目录：  $ROOT_DIR/public"
echo "  PHP 版本：8.2+（FPM 模式）"
echo "然后在站点『设置 → SSL』申请 Let's Encrypt 证书并开启强制 HTTPS"
echo "==================================================="

