<?php

return [
    // 工资表导出默认模式：formula=带公式版；plain=纯数值版（数据导出页二选一，默认取此配置）
    'export_mode' => env('PAYROLL_EXPORT_MODE', 'formula'),
];
