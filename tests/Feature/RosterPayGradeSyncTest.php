<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DingtalkCallbackController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 钉钉智能人事花名册 → payroll_staff.data 映射（薪酬档位）。
 * applyRosterFields 是内部私有方法，用反射直测映射写入，不依赖钉钉 API。
 */
class RosterPayGradeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function seedStaff(int $id, string $dataJson = '{}'): void
    {
        DB::table('payroll_staff')->insert([
            'legacy_id' => $id, 'name' => '张三', 'project_name' => '测试项目', 'position' => '经理',
            'status' => '正式', 'fixed_monthly' => 6000, 'base_salary' => 5000,
            'hire_date' => '2025-01-01', 'regular_date' => '2025-02-01',
            'resign_date' => null, 'deleted' => false, 'person_type' => 'manager',
            'data' => $dataJson,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function applyRoster(int $dbId, array $fields): void
    {
        $controller = (new \ReflectionClass(DingtalkCallbackController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DingtalkCallbackController::class, 'applyRosterFields');
        $method->setAccessible(true);
        $method->invoke($controller, $dbId, $fields);
    }

    private function dataOf(int $dbId): array
    {
        $json = DB::table('payroll_staff')->where('id', $dbId)->value('data');
        return json_decode($json ?: '{}', true) ?: [];
    }

    public function test_roster_pay_grade_maps_to_data(): void
    {
        $this->seedStaff(1, json_encode(['gender' => '男'], JSON_UNESCAPED_UNICODE));
        $this->applyRoster(1, ['薪酬档位' => '经理级', '性别' => '男']);
        $data = $this->dataOf(1);
        $this->assertEquals('经理级', $data['pay_grade'] ?? null);
        $this->assertEquals('男', $data['gender'] ?? null, '其他花名册字段映射不受影响');
    }

    public function test_empty_pay_grade_does_not_overwrite_existing(): void
    {
        // 同步逻辑对空值跳过（清空不回写）
        $this->seedStaff(1, json_encode(['pay_grade' => '主管级'], JSON_UNESCAPED_UNICODE));
        $this->applyRoster(1, ['薪酬档位' => '']);
        $this->assertEquals('主管级', $this->dataOf(1)['pay_grade'] ?? null);
    }

    /** 钉钉调薪同步：薪资变化时应追加 salary_history 段，生效日为同步当月 1 号 */
    public function test_roster_salary_change_appends_salary_history(): void
    {
        $this->seedStaff(1);
        $this->applyRoster(1, ['月度薪资标准' => 8000, '月度基本工资' => 6500]);
        $staff = DB::table('payroll_staff')->where('id', 1)->first();
        $this->assertEquals(8000.0, (float) $staff->fixed_monthly);
        $this->assertEquals(6500.0, (float) $staff->base_salary);

        $history = $this->dataOf(1)['salary_history'] ?? [];
        $this->assertCount(2, $history, '首次调薪应补全基线 + 新段共 2 条');
        // 基线：入职日生效、旧薪资
        $this->assertEquals('2025-01-01', $history[0]['effective_date']);
        $this->assertEquals(6000.0, (float) $history[0]['fixed_monthly']);
        $this->assertEquals(5000.0, (float) $history[0]['base_salary']);
        // 新段：当月 1 号生效、新薪资
        $this->assertEquals(date('Y-m-01'), $history[1]['effective_date']);
        $this->assertEquals(8000.0, (float) $history[1]['fixed_monthly']);
        $this->assertEquals(6500.0, (float) $history[1]['base_salary']);
        $this->assertEquals('调薪', $history[1]['type'] ?? null);
    }

    /** 薪资未变化时，不应追加 salary_history 段 */
    public function test_roster_salary_unchanged_does_not_append_history(): void
    {
        $this->seedStaff(1);
        $this->applyRoster(1, ['月度薪资标准' => 6000, '月度基本工资' => 5000]);
        $history = $this->dataOf(1)['salary_history'] ?? [];
        $this->assertEmpty($history, '薪资未变不应写 salary_history');
    }
}
