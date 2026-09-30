<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 根路径重定向到新 SPA（旧 v3 首页已于 2026-09-30 下线）。
     */
    public function test_root_redirects_to_spa(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertRedirect('/app/');
    }

    /**
     * 工资条自助查询页保持直出（员工免登录入口）。
     */
    public function test_payslip_page_is_served(): void
    {
        $response = $this->get('/payslip.html');

        $response->assertStatus(200);
    }
}
