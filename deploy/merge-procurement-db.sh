#!/bin/bash
# ============================================================
# 采购库合并 / 核验脚本（幂等，可重复执行）
# ------------------------------------------------------------
# 背景：采购模块自 2026-09-30 起已把 14 张业务表并入主库 payroll，
#       代码连接由 config/procurement.php + .env 的 PROC_DB_* 控制。
#       旧库 gy_procurement 仅作历史副本保留，可能已被删除。
#
# 本脚本做两件事，两种环境都能跑：
#   A. 旧库存在：逐表 CREATE TABLE IF NOT EXISTS / INSERT IGNORE 补差，
#      再按主键核对旧库行是否全部进入主库，并比对关键金额合计。
#   B. 旧库不存在：退化为纯核验，输出主库现状并通过。
#
# 约束：
#   - 只处理下面 TABLES 里的采购表，绝不触碰 payroll_* 等薪酬表；
#   - 不 DROP / TRUNCATE 任何表；
#   - 旧库比主库多出的行才会被写入，已有主键一律忽略（主库为准）；
#   - purchase_items 主库多一列 custom_reviewed，按列交集写入，避免错位。
#
# 用法：
#   bash deploy/merge-procurement-db.sh
#   LEGACY_DB=gy_procurement APP_DIR=/var/www/payroll/laravel-app bash ...
# ============================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/payroll/laravel-app}"
LEGACY_DB="${LEGACY_DB:-gy_procurement}"
cd "$APP_DIR"

env_get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'; }
MAIN_DB="$(env_get DB_DATABASE)"
PROC_DB="$(env_get PROC_DB_NAME)"
DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"

[ -n "$MAIN_DB" ] || { echo "!! .env 缺少 DB_DATABASE"; exit 2; }
[ "$PROC_DB" = "$MAIN_DB" ] || {
  echo "!! 合并未生效：PROC_DB_NAME=$PROC_DB，DB_DATABASE=$MAIN_DB（两者必须一致）"
  exit 2
}

export MYSQL_PWD="$DB_PASS"
MYSQL=(mysql -u"$DB_USER" -N -B)
q() { "${MYSQL[@]}" -e "$1"; }

TABLES="products product_synonyms purchase_items archived_purchases budget_plan fill_windows monthly_archive op_logs audit_records notifications sys_settings projects"
declare -A PK=(
  [products]=id [product_synonyms]=id [purchase_items]=id
  [archived_purchases]=id [budget_plan]=id [fill_windows]=id
  [monthly_archive]=month [op_logs]=id [audit_records]=id
  [notifications]=id [sys_settings]=skey [projects]=id
)

legacy_exists="$(q "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$LEGACY_DB'")"
echo "main=$MAIN_DB proc=$PROC_DB legacy=$LEGACY_DB legacy_exists=$legacy_exists"

if [ "$legacy_exists" = "1" ]; then
  echo "=== 1. 结构补齐（仅主库缺失时 CREATE TABLE ... LIKE，不动已存在表） ==="
  for t in $TABLES; do
    in_main="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$MAIN_DB' AND table_name='$t'")"
    in_legacy="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LEGACY_DB' AND table_name='$t'")"
    if [ "$in_legacy" = "0" ]; then
      echo "  skip $t（旧库无此表）"
      continue
    fi
    if [ "$in_main" = "0" ]; then
      q "CREATE TABLE \`$MAIN_DB\`.\`$t\` LIKE \`$LEGACY_DB\`.\`$t\`"
      echo "  created $MAIN_DB.$t"
    else
      echo "  exists  $MAIN_DB.$t"
    fi
  done

  echo "=== 2. 补差（INSERT IGNORE，按列交集，可重复执行） ==="
  for t in $TABLES; do
    in_legacy="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LEGACY_DB' AND table_name='$t'")"
    [ "$in_legacy" = "0" ] && continue
    cols="$(q "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$LEGACY_DB' AND table_name='$t' AND column_name IN (SELECT column_name FROM information_schema.columns WHERE table_schema='$MAIN_DB' AND table_name='$t')")"
    legacy_only="$(q "SELECT GROUP_CONCAT(column_name) FROM information_schema.columns WHERE table_schema='$LEGACY_DB' AND table_name='$t' AND column_name NOT IN (SELECT column_name FROM information_schema.columns WHERE table_schema='$MAIN_DB' AND table_name='$t')")"
    if [ -n "$legacy_only" ] && [ "$legacy_only" != "NULL" ]; then
      echo "  !! $t 旧库独有列 [$legacy_only] 在主库不存在，拒绝写入以免丢列"
      exit 3
    fi
    [ -z "$cols" ] && { echo "  !! $t 无可写列"; exit 3; }
    before="$(q "SELECT COUNT(*) FROM \`$MAIN_DB\`.\`$t\`")"
    q "INSERT IGNORE INTO \`$MAIN_DB\`.\`$t\` (\`${cols//,/\`,\`}\`) SELECT \`${cols//,/\`,\`}\` FROM \`$LEGACY_DB\`.\`$t\`"
    after="$(q "SELECT COUNT(*) FROM \`$MAIN_DB\`.\`$t\`")"
    echo "  $t: main $before -> $after (+$((after - before)))"
  done
else
  echo "=== 1-2. 旧库不存在：跳过结构补齐与补差，进入纯核验 ==="
fi

echo "=== 3. 逐表行数与主键缺失核对 ==="
FAIL=0
printf "%-24s %10s %10s %10s\n" "table" "legacy" "main" "missing"
for t in $TABLES; do
  k="${PK[$t]}"
  if [ "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$MAIN_DB' AND table_name='$t'")" = "0" ]; then
    echo "!! 主库缺少表 $MAIN_DB.$t"
    FAIL=1
    continue
  fi
  m="$(q "SELECT COUNT(*) FROM \`$MAIN_DB\`.\`$t\`")"
  if [ "$legacy_exists" = "1" ] && [ "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LEGACY_DB' AND table_name='$t'")" = "1" ]; then
    l="$(q "SELECT COUNT(*) FROM \`$LEGACY_DB\`.\`$t\`")"
    miss="$(q "SELECT COUNT(*) FROM \`$LEGACY_DB\`.\`$t\` o LEFT JOIN \`$MAIN_DB\`.\`$t\` m ON m.\`$k\`=o.\`$k\` WHERE m.\`$k\` IS NULL")"
  else
    l="-"; miss="-"
  fi
  printf "%-24s %10s %10s %10s\n" "$t" "$l" "$m" "$miss"
  if [ "$miss" != "-" ] && [ "$miss" != "0" ]; then FAIL=1; fi
done

echo "=== 4. 关键金额合计 ==="
for pair in "budget_plan:amount" "purchase_items:quantity*price" "archived_purchases:total"; do
  t="${pair%%:*}"; col="${pair##*:}"
  mv="$(q "SELECT IFNULL(SUM($col),0) FROM \`$MAIN_DB\`.\`$t\`")"
  if [ "$legacy_exists" = "1" ] && [ "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LEGACY_DB' AND table_name='$t'")" = "1" ]; then
    lv="$(q "SELECT IFNULL(SUM($col),0) FROM \`$LEGACY_DB\`.\`$t\`")"
  else
    lv="-"
  fi
  echo "  $t.$col legacy=$lv main=$mv"
done

if [ "$FAIL" -ne 0 ]; then
  echo "!! 核验失败：存在旧库行未进入主库"
  exit 1
fi
echo "OK: 核验通过（旧库行在主库缺失数为 0；旧库不存在时仅输出主库现状）"
