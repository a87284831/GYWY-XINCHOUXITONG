<?php

return [
    'host'   => env('DB_HOST', '127.0.0.1'),
    'port'   => (int) env('DB_PORT', 3306),
    'dbname' => env('FINANCE_DB_DATABASE', 'gy_finance'),
    'user'   => env('DB_USERNAME', 'payroll'),
    'pass'   => env('DB_PASSWORD', ''),
];
