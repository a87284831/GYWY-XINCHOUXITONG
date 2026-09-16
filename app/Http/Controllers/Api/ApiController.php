<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class ApiController extends Controller
{
    protected function account(Request $request): ?object
    {
        $token = trim((string) $request->header('X-Token'));
        if ($token === '') {
            return null;
        }
        $accountId = Cache::get('payroll_api_token:' . $token);
        if (!$accountId) {
            return null;
        }
        return DB::table('payroll_accounts')->where('id', $accountId)->first();
    }

    protected function requireAccount(Request $request): mixed
    {
        $account = $this->account($request);
        return $account ?: response()->json(['ok' => false, 'error' => '未登录'], 401);
    }

    protected function isProjectScope(object $account): bool
    {
        $role = DB::table('payroll_roles')->where('role_key', $account->role)->first();
        return $role?->scope === 'project';
    }

    protected function jsonValue(?string $value, mixed $default = []): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    protected function tokenFor(object $account): string
    {
        $token = Str::random(64);
        Cache::put('payroll_api_token:' . $token, $account->id, now()->addHours(8));
        return $token;
    }

    protected function cellValue(object $sheet, int $column, int $row): mixed
    {
        $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row;
        $cell = $sheet->getCell($coordinate);
        // 公式单元格返回计算值（模板统计列带 COUNTIF 公式时，上传解析需读结果而非公式字符串）
        if (str_starts_with((string) $cell->getValue(), '=')) {
            try {
                $v = $cell->getCalculatedValue();
                return $v;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $cell->getValue();
    }
}
