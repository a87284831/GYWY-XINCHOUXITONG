<?php
/**
 * 自定义 PHP 内置服务器 router 脚本：替代 artisan serve 默认的 vendor server.php
 * 逻辑：静态文件直返，其余交给 Laravel 入口 public/index.php
 * 用法：php -S 127.0.0.1:8910 -t public server_router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // 静态文件直返
}
// /app/* SPA fallback：直接返回 app/index.html，避免 PHP 内置服务器把 SCRIPT_NAME 设为
// /app/index.html 干扰 Laravel 路由解析（前端 router 为 history 模式，base=/app/）
if (strpos($uri, '/app') === 0) {
    readfile(__DIR__ . '/public/app/index.html');
    return true;
}
require __DIR__ . '/public/index.php';
