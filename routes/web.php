<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

Route::get('/index.html', function () {
    return response()->file(public_path('index.html'));
});

Route::get('/payslip.html', function () {
    return response()->file(public_path('payslip.html'));
});

Route::get('/setup.html', function () {
    return response()->file(public_path('setup.html'));
});

// SPA fallback：前端 router 为 history 模式（base=/app/），深链/刷新时把 /app/{any} 交回 index.html
// 静态文件（/app/assets/*、/app/index.html）由 PHP 内置服务器优先直返，不会进入此路由
Route::get('/app/{any}', function () {
    return response()->file(public_path('app/index.html'));
})->where('any', '.*');
