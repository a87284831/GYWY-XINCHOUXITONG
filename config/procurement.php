<?php

// 采购模块数据库配置（2026-09-30 起合并入平台主库 payroll；旧库 gy_procurement 已废弃，不再作为兜底）
return [
    'host'   => env('PROC_DB_HOST', '127.0.0.1'),
    'port'   => (int) env('PROC_DB_PORT', 3306),
    'dbname' => env('PROC_DB_NAME', 'payroll'),
    'user'   => env('PROC_DB_USER', 'payroll'),
    'pass'   => env('PROC_DB_PASS', ''),
];
