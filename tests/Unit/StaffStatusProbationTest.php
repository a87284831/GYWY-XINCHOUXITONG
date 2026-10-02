<?php

namespace Tests\Unit;

use App\Services\StaffStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2026-10-03 修复风险1：无实际转正日期的员工离职后，离职状态吞掉在职时的
 * 试用标记，离职当月反按正式工计绩效。用钉钉花名册「计划转正日期」兜底
 * （该字段员工离职后仍保留），逐日判定试用期。
 */
class StaffStatusProbationTest extends TestCase
{
    #[DataProvider('probationCases')]
    public function test_is_probation_on_date(
        bool $expected,
        ?string $regularDate,
        ?string $plannedDate,
        string $status,
        string $date
    ): void {
        $this->assertSame(
            $expected,
            StaffStatus::isProbationOnDate($regularDate, $plannedDate, $status, $date)
        );
    }

    public static function probationCases(): array
    {
        return [
            '1. 有实际转正日，转正日前=试用' => [true, '2026-10-15', null, '正式', '2026-10-14'],
            '2. 有实际转正日，转正日当天=正式' => [false, '2026-10-15', null, '正式', '2026-10-15'],
            '3. 实际转正日优先于计划日' => [false, '2026-08-01', '2026-10-29', '离职', '2026-08-01'],
            '4. 无转正日+在职试用=试用' => [true, null, null, '试用', '2026-10-01'],
            '5. 无转正日+在职正式=正式' => [false, null, null, '正式', '2026-10-01'],
            '6. 离职+计划转正日在未来=试用（测试人员场景）' => [true, null, '2026-10-29', '离职', '2026-10-02'],
            '7. 离职+计划转正日当天=正式' => [false, null, '2026-10-29', '离职', '2026-10-29'],
            '8. 离职+计划转正日已过=正式（到期自动转正）' => [false, null, '2026-07-15', '离职', '2026-08-01'],
            '9. 离职+无任何转正日=正式（无证据不臆断）' => [false, null, null, '离职', '2026-10-01'],
            '10. 0000-00-00 空日期按 null 处理' => [true, '0000-00-00', null, '试用', '2026-10-01'],
        ];
    }
}
