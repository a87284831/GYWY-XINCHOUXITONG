<?php

namespace Tests\Feature;

use App\Services\StaffCategoryMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 人员分类变更：未归档月份工资行随分类迁移；已归档月份不动并返回提示；
 * 同一人同月只保留一份有效行；迁移与审计日志同事务，失败整体回滚；支持批量变更。
 */
class StaffCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private StaffCategoryMigration $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new StaffCategoryMigration();
    }

    private function seedResult(
        int $sid,
        string $ym,
        bool $archived = false,
        bool $mgr = false,
        bool $case = false,
        bool $hq = false,
        float $gross = 1000,
        string $project = '甲项目'
    ): int {
        return (int) DB::table('payroll_results')->insertGetId([
            'year_month' => $ym, 'project_name' => $project, 'staff_legacy_id' => $sid,
            'row_data' => json_encode(['project' => $project, 'name' => '张三', 'gross' => $gross], JSON_UNESCAPED_UNICODE),
            'archived' => $archived, 'is_manager_row' => $mgr, 'is_case_row' => $case, 'is_hq_row' => $hq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(int $id): ?object
    {
        return DB::table('payroll_results')->where('id', $id)->first();
    }

    public function test_unarchived_rows_move_and_archived_rows_stay_with_notice(): void
    {
        $r3 = $this->seedResult(1, '2026-03', false, false, false, false, 1000);
        $r4 = $this->seedResult(1, '2026-04', false, false, false, false, 1200);
        $r5 = $this->seedResult(1, '2026-05', true, false, false, false, 1300);

        $out = DB::transaction(fn () => $this->svc->migrate(
            1, '张三', 'staff', '甲项目', 'manager', '甲项目', 'admin', 'staff_edit', '测试', '2026-01-01'
        ));

        $this->assertSame('staff', $out['from']);
        $this->assertSame('manager', $out['to']);
        $this->assertSame(['2026-03', '2026-04'], $out['moved_months']);
        $this->assertSame(2, $out['moved_rows']);
        $this->assertSame(['2026-05'], $out['archived_months'], '已归档月份必须提示给操作人');

        foreach ([$r3, $r4] as $id) {
            $row = $this->row($id);
            $this->assertSame(1, (int) $row->is_manager_row);
            $this->assertSame(0, (int) $row->is_case_row);
            $this->assertSame(0, (int) $row->is_hq_row);
            $this->assertSame(1, DB::table('payroll_results')->where('staff_legacy_id', 1)
                ->where('year_month', $row->year_month)->count(), '同一人同月只能有一份有效行');
        }
        // 金额不因迁移变化
        $this->assertEqualsWithDelta(1000.0, (float) (json_decode($this->row($r3)->row_data, true)['gross']), 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) (json_decode($this->row($r4)->row_data, true)['gross']), 0.001);

        // 已归档月份原样不动
        $archived = $this->row($r5);
        $this->assertSame(1, (int) $archived->archived);
        $this->assertSame(0, (int) $archived->is_manager_row);
        $this->assertEqualsWithDelta(1300.0, (float) (json_decode($archived->row_data, true)['gross']), 0.001);

        // 审计日志：分类前后、月份、行数
        $log = DB::table('payroll_staff_type_migrations')->where('staff_legacy_id', 1)->first();
        $this->assertNotNull($log);
        $this->assertSame('staff', $log->from_type);
        $this->assertSame('manager', $log->to_type);
        $this->assertSame(2, (int) $log->moved_rows);
        $this->assertSame(['2026-03', '2026-04'], json_decode((string) $log->moved_months, true));
        $this->assertSame(['2026-05'], json_decode((string) $log->archived_months, true));
    }

    public function test_same_month_already_migrated_is_idempotent_and_keeps_single_row(): void
    {
        // 生产库对 (year_month, staff_legacy_id) 有唯一约束，同月新旧两行在库层面不可能共存；
        // 迁移服务仍做了「保留目标分类行、删除重复行」的防御。这里验证重复执行不产生第二行、金额不变。
        $new = $this->seedResult(2, '2026-06', false, true, false, false, 5000);
        $this->seedResult(2, '2026-05', false, false, false, false, 1000);

        $out = DB::transaction(fn () => $this->svc->migrate(
            2, '张三', 'staff', '甲项目', 'manager', '甲项目', 'admin', 'staff_edit', '测试', '2026-01-01'
        ));

        $this->assertSame(['2026-05'], $out['moved_months'], '已是目标分类的月份不再改写');
        $this->assertSame(1, DB::table('payroll_results')->where('staff_legacy_id', 2)->where('year_month', '2026-06')->count());
        $this->assertSame(1, (int) $this->row($new)->is_manager_row);
        $this->assertEqualsWithDelta(5000.0, (float) (json_decode($this->row($new)->row_data, true)['gross']), 0.001,
            '保留的行金额不得变化');
    }

    public function test_months_before_effective_date_are_not_touched(): void
    {
        $before = $this->seedResult(3, '2026-03', false);
        $after = $this->seedResult(3, '2026-05', false);

        $out = DB::transaction(fn () => $this->svc->migrate(
            3, '张三', 'staff', '甲项目', 'manager', '甲项目', 'admin', 'staff_edit', '测试', '2026-05-01'
        ));

        $this->assertSame(['2026-05'], $out['moved_months']);
        $this->assertSame(0, (int) $this->row($before)->is_manager_row, '生效日之前的月份仍属旧分类');
        $this->assertSame(1, (int) $this->row($after)->is_manager_row);
    }

    public function test_failure_rolls_back_rows_and_log(): void
    {
        $this->seedResult(4, '2026-03', false);

        try {
            DB::transaction(function () {
                $this->svc->migrate(4, '张三', 'staff', '甲项目', 'manager', '甲项目', 'admin', 'staff_edit', '测试', '2026-01-01');
                throw new \RuntimeException('后续步骤失败');
            });
            $this->fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('后续步骤失败', $e->getMessage());
        }

        $row = DB::table('payroll_results')->where('staff_legacy_id', 4)->first();
        $this->assertSame(0, (int) $row->is_manager_row, '整体回滚：迁移不得生效');
        $this->assertSame(0, DB::table('payroll_staff_type_migrations')->where('staff_legacy_id', 4)->count(),
            '整体回滚：审计日志不得残留半条');
    }

    public function test_batch_migration_of_multiple_staff(): void
    {
        $a = $this->seedResult(10, '2026-03', false);
        $b = $this->seedResult(11, '2026-03', false);

        DB::transaction(function () {
            $this->svc->migrate(10, '张三', 'staff', '甲项目', 'case', '甲项目', 'admin', 'staff_edit', '批量', '2026-01-01');
            $this->svc->migrate(11, '李四', 'staff', '甲项目', 'case', '甲项目', 'admin', 'staff_edit', '批量', '2026-01-01');
        });

        foreach ([$a, $b] as $id) {
            $row = $this->row($id);
            $this->assertSame(1, (int) $row->is_case_row);
            $this->assertSame(0, (int) $row->is_manager_row);
        }
        $this->assertSame(2, DB::table('payroll_staff_type_migrations')->count());
    }
}
