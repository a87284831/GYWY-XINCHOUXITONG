<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 旧的「数据导出 → ③ 绩效工资专项表」（GET /api/export/performance）已下线，
 * 由「薪资模块 → 绩效导出」（GET /api/export/performance-detail）取代。
 *
 * 本用例锁定三件事：
 * 1. 旧路由彻底移除（404），避免残留入口；
 * 2. 新绩效导出路由仍然注册且受鉴权保护；
 * 3. 数据导出页其他导出接口不受影响。
 */
class ExportPerformanceLegacyRemovedTest extends TestCase
{
    public function test_old_performance_export_route_is_removed(): void
    {
        $this->getJson('/api/export/performance?ym=2026-04')->assertStatus(404);

        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();
        $this->assertNotContains('api/export/performance', $uris);
    }

    public function test_new_performance_export_route_still_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();
        $this->assertContains('api/export/performance-detail', $uris);
        $this->assertContains('api/export/performance-detail/preview', $uris);

        // 未登录仍返回 401：路由存在且鉴权生效
        $this->getJson('/api/export/performance-detail?ym=2026-04')->assertStatus(401);
    }

    public function test_other_data_export_routes_unaffected(): void
    {
        foreach ([
            '/api/export/project?ym=2026-04&project=甲项目',
            '/api/export/projects_all?ym=2026-04',
            '/api/export/summary?ym=2026-04',
            '/api/export/attendance?ym=2026-04&project=all&person_type=all&mode=merged',
        ] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }
}
