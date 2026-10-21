#!/bin/bash
# 整包部署前端（生产标准流程，2026-10-21 白屏事故后固化）
# ============================================================
# 事故背景：2026-10-21 00:34 手动挑 4 个 chunk + index.html 单独 scp，
# index.html 引用的主入口 index-Dew5XZlX.js 未上传 → 404 → 白屏。
# 规则：vite 构建会重新生成全部带哈希文件，必须整包同步，严禁手动挑文件。
#
# 用法（本地 PowerShell，cwd=laravel-app）：
#   1. cd frontend; npm.cmd run build
#   2. tar -czf $env:TEMP\app-bundle.tar.gz -C public app
#   3. scp $env:TEMP\app-bundle.tar.gz root@120.53.8.216:/tmp/
#   4. scp deploy/deploy-frontend-full.sh root@120.53.8.216:/tmp/
#   5. ssh root@120.53.8.216 "bash /tmp/deploy-frontend-full.sh"
# ============================================================
set -e
REMOTE=/var/www/payroll/laravel-app
TS=$(date +%Y%m%d_%H%M%S)

echo "=== 1. 备份当前 index.html ==="
cp $REMOTE/public/app/index.html /root/index.html.bak.$TS
echo "备份: /root/index.html.bak.$TS"

echo "=== 2. 整包解压覆盖（只增改不删，旧哈希文件保留作浏览器缓存兜底）==="
tar -xzf /tmp/app-bundle.tar.gz -C $REMOTE/public/

echo "=== 3. 校验 index.html 引用的所有资源（不过 200 自动回滚）=="
FAIL=0
for ref in $(grep -oE '/app/assets/[^"]+' $REMOTE/public/app/index.html); do
  code=$(curl -sk -o /dev/null -w "%{http_code}" "https://oa.haoxuanyunshu.cn$ref")
  [ "$code" != "200" ] && FAIL=1
  echo "$ref -> $code"
done
if [ $FAIL -ne 0 ]; then
  echo "!!! 存在 404，回滚 index.html 并退出 !!!"
  cp /root/index.html.bak.$TS $REMOTE/public/app/index.html
  exit 1
fi
echo "全部资源 200，部署自洽"

echo "=== 4. 首页状态 ==="
curl -sk -o /dev/null -w "GET /app/ -> %{http_code}\n" https://oa.haoxuanyunshu.cn/app/
echo "=== 部署完成 ==="
