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
