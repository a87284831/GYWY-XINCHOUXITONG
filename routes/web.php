<?php

use Illuminate\Support\Facades\Route;

// 旧 v3 单页系统已下线：根路径一律进新 SPA（/app/）
Route::get('/', function () {
    return redirect('/app/');
});

Route::get('/index.html', function () {
    return redirect('/app/');
});

// 工资条自助查询页（员工免登录入口，新 SPA 设置页直接引用此 URL）
Route::get('/payslip.html', function () {
    return response()->file(public_path('payslip.html'));
});

// SPA fallback：前端 router 为 history 模式（base=/app/），深链/刷新时把 /app/{any} 交回 index.html
// 静态文件（/app/assets/*、/app/index.html）由 PHP 内置服务器优先直返，不会进入此路由
Route::get('/app/{any}', function () {
    return response()->file(public_path('app/index.html'));
})->where('any', '.*');
