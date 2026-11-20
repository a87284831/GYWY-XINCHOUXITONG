<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 采购项目范围守卫（修复 S1）与导入覆盖范围（修复 S2）的纯逻辑回归。
 * 采购模块运行态依赖独立 MySQL，此处只测不依赖连接的确定逻辑；
 * 数据库全流程由本地 SQLite 审计脚本另行验证（不进入 CI）。
 */
class PurchaseScopeGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/fill.php';
        require_once dirname(__DIR__, 2) . '/app/Purchase/handlers/export_import.php';
    }

    public function test_project_account_without_project_is_rejected(): void
    {
        foreach ([0, -1] as $pid) {
            $scope = \purchase_fill_scope(['role' => 'staff', 'project_id' => $pid], 0);
            $this->assertFalse($scope['ok'], 'project_id=' . $pid . ' 必须拒绝');
            $this->assertSame(0, $scope['project_id'], '拒绝时不得返回任何项目范围');
            $this->assertNotSame('', $scope['error']);
        }
        $scope = \purchase_fill_scope(['role' => 'staff'], 3);
        $this->assertFalse($scope['ok'], '缺少 project_id 的项目账号必须拒绝');
    }

    public function test_project_account_cannot_override_scope_via_request(): void
    {
        $scope = \purchase_fill_scope(['role' => 'staff', 'project_id' => 5], 9);
        $this->assertTrue($scope['ok']);
        $this->assertSame(5, $scope['project_id'], '请求参数不得越权切换到其他项目');
    }

    public function test_admin_scope_allows_all_or_selected_project(): void
    {
        $all = \purchase_fill_scope(['role' => 'admin', 'project_id' => 0], 0);
        $this->assertTrue($all['ok']);
        $this->assertSame(0, $all['project_id'], '0 表示管理员查看全部项目');

        $one = \purchase_fill_scope(['role' => 'admin'], 7);
        $this->assertSame(7, $one['project_id']);
    }

    public function test_import_scope_pairs_only_cover_uploaded_projects_and_lines(): void
    {
        $rows = [
            ['project_id' => 1, 'line' => '环境'],
            ['project_id' => 1, 'line' => '环境'],   // 重复
            ['project_id' => 1, 'line' => '工程'],
            ['project_id' => 2, 'line' => '环境'],
            ['project_id' => 0, 'line' => '环境'],   // 非法项目
            ['project_id' => 3, 'line' => ''],       // 缺条线
        ];
        $pairs = \purchase_import_scope_pairs($rows);
        $this->assertCount(3, $pairs);
        foreach ([[1, '环境'], [1, '工程'], [2, '环境']] as $expected) {
            $this->assertContains($expected, $pairs);
        }

        $projects = array_values(array_unique(array_column(array_map(fn ($p) => ['project_id' => $p[0]], $pairs), 'project_id')));
        sort($projects);
        $this->assertSame([1, 2], $projects, '未出现在上传文件中的项目不会进入覆盖范围');
    }

    public function test_import_scope_is_empty_when_no_valid_rows(): void
    {
        $this->assertSame([], \purchase_import_scope_pairs([]));
        $this->assertSame([], \purchase_import_scope_pairs([['project_id' => 0, 'line' => '环境']]));
    }
}
