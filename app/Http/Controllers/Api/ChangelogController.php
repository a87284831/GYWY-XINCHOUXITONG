<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统更新日志：读仓库根 changelog.json（随代码部署，一部署一条目）。
 * 版本号规则 vYYYY.M.D.N（当日序号），回滚定位用；条目分类限定 新增/修复/优化。
 */
class ChangelogController extends ApiController
{
    /** 更新日志列表（按版本倒序，最新在前） */
    public function list(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $path = base_path('changelog.json');
        if (!is_file($path)) {
            // 数据缺失显式报错，禁止静默返回空列表（用户规则）
            return response()->json(['ok' => false, 'error' => '更新日志数据缺失'], 500);
        }
        $entries = json_decode((string) file_get_contents($path), true);
        if (!is_array($entries)) {
            return response()->json(['ok' => false, 'error' => '更新日志数据损坏'], 500);
        }
        $limit = min(200, max(1, (int) $request->input('limit', 50)));

        return response()->json([
            'ok' => true,
            'version' => $entries[0]['version'] ?? null,
            'items' => array_values(array_slice($entries, 0, $limit)),
        ]);
    }
}
