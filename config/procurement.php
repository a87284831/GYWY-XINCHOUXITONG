<?php

// 采购模块数据库配置（与平台主库分离）
return [
    'host'   => env('PROC_DB_HOST', '127.0.0.1'),
    'port'   => (int) env('PROC_DB_PORT', 3306),
    'dbname' => env('PROC_DB_NAME', 'gy_procurement'),
    'user'   => env('PROC_DB_USER', 'payroll'),
    'pass'   => env('PROC_DB_PASS', ''),
];
