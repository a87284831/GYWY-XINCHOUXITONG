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
#   3. scp $env:TEMP\app-bundle.tar.gz ubuntu@120.53.8.216:/tmp/
#   4. scp deploy/deploy-frontend-full.sh ubuntu@120.53.8.216:/tmp/
#   5. ssh ubuntu@120.53.8.216 "bash /tmp/deploy-frontend-full.sh"
# ============================================================
set -e
REMOTE=/var/www/payroll/laravel-app
TS=$(date +%Y%m%d_%H%M%S)

echo "=== 1. 备份当前 index.html ==="
cp $REMOTE/public/app/index.html /root/index.html.bak.$TS
echo "备份: /root/index.html.bak.$TS"

echo "=== 2. 整包解压覆盖（旧产物在 3b 自检通过后清理）==="
tar -xzf /tmp/app-bundle.tar.gz -C $REMOTE/public/

echo "=== 2b. 包完整性检查（public/app 已移出版本控制，防未 build 打出空包）==="
REFS=$(grep -oE '/app/assets/[^"]+' $REMOTE/public/app/index.html 2>/dev/null | sort -u || true)
if [ ! -f "$REMOTE/public/app/index.html" ] || [ -z "$REFS" ]; then
  echo "!!! index.html 缺失或未引用任何资源，包无效，回滚并退出 !!!"
  cp /root/index.html.bak.$TS $REMOTE/public/app/index.html 2>/dev/null || true
  echo "提示：public/app 自 2026-10-21 起不入库，请先在本地 cd frontend && npm run build 再打包上传"
  exit 1
fi
echo "index.html 引用资源数: $(echo "$REFS" | wc -l)"

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

echo "=== 3b. 清理不在本次构建中的旧哈希产物（保持三端一致）==="
# 必须在自检通过后执行：自检失败会回滚 index.html，旧产物此时不能删
# 注意：清理后旧标签页需硬刷新；若需保留回滚窗口，可改为保留上一代产物
tar tzf /tmp/app-bundle.tar.gz | grep '^app/assets/' | grep -v '/$' | sed 's|^app/|public/app/|' | sort > /tmp/keep-assets-deploy.txt
KEEP_CNT=$(wc -l < /tmp/keep-assets-deploy.txt)
# 关键：cur 也 strip 成相对路径（public/app/assets/xxx.js），否则 comm 和 keep 的前缀对不上，
# 全部 cur 都会被误判为"旧产物"（2026-10-22 事故：cur 完整路径 vs keep 相对路径，误删 199 个）
find $REMOTE/public/app/assets -type f | sed "s|^$REMOTE/||" | sort > /tmp/cur-assets-deploy.txt
CUR_CNT=$(wc -l < /tmp/cur-assets-deploy.txt)

# 防呆（2026-10-21 二次白屏事故）：若包内清单异常偏少，comm 会把服务器上全部产物判为“旧产物”而清空，
# 直接导致 index.html 引用资源 404 白屏。任何可疑情况一律跳过清理，宁留垃圾不删文件。
if [ "$KEEP_CNT" -lt 10 ] || [ "$KEEP_CNT" -gt "$CUR_CNT" ]; then
  echo "!!! 包内产物清单异常（包内 $KEEP_CNT / 服务器 $CUR_CNT），跳过旧产物清理，请人工核对 !!!"
else
  comm -23 /tmp/cur-assets-deploy.txt /tmp/keep-assets-deploy.txt > /tmp/del-assets-deploy.txt
  echo "删除旧产物: $(wc -l < /tmp/del-assets-deploy.txt) 个；保留: $KEEP_CNT 个"
  if [ -s /tmp/del-assets-deploy.txt ]; then
    xargs -d '\n' rm -f < /tmp/del-assets-deploy.txt
  fi
fi

echo "=== 3c. 清理后复检（引用资源必须仍全部 200）==="
MISS=0
for ref in $(grep -oE '/app/assets/[^"]+' $REMOTE/public/app/index.html); do
  code=$(curl -sk -o /dev/null -w "%{http_code}" "https://oa.haoxuanyunshu.cn$ref")
  [ "$code" != "200" ] && MISS=1 && echo "404: $ref"
done
if [ $MISS -ne 0 ]; then
  echo "!!! 清理后出现 404，说明误删，立即重新整包解压恢复 !!!"
  tar -xzf /tmp/app-bundle.tar.gz -C $REMOTE/public/
  exit 1
fi
echo "清理后引用资源仍全部 200"

echo "=== 4. 产物清单比对（三端一致：本地构建包 vs 服务器） ==="
echo "包内产物: $KEEP_CNT 个；部署时服务器已有: $CUR_CNT 个"
if [ "$KEEP_CNT" != "$CUR_CNT" ]; then
  echo "提示：数量不一致通常因旧产物清理时机，核对下列新增项"
  comm -13 /tmp/keep-assets-deploy.txt /tmp/cur-assets-deploy.txt || true
fi

echo "=== 5. 首页状态 ==="
curl -sk -o /dev/null -w "GET /app/ -> %{http_code}\n" https://oa.haoxuanyunshu.cn/app/

echo "=== 6. 重置 storage / bootstrap/cache 权限（防 artisan 以 root 运行污染属主） ==="
# 2026-10-25 登录 500 事故：php artisan migrate/cache:clear/route:cache 等以 root 身份运行后，
# 新生成的缓存文件属主为 root，PHP-FPM (www-data) 无法写入 →
# 登录时 Cache::put 触发 file_put_contents 失败 → Laravel 异常处理器又尝试写 laravel.log 同样失败 →
# 递归异常，返回 500 "Server Error"。
# 修复：部署末尾必须强制重置这两个目录的属主与权限。
chown -R www-data:www-data $REMOTE/storage $REMOTE/bootstrap/cache
chmod -R 775 $REMOTE/storage $REMOTE/bootstrap/cache
mkdir -p $REMOTE/storage/framework/cache/data
chown -R www-data:www-data $REMOTE/storage/framework/cache/data

echo "=== 部署完成 ==="
