<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 人员分类变更时迁移未归档工资行：
 * - 基层 / 管理 / 案场 / 总部之间切换时，把未归档月份的旧工资行改到新分类；
 * - 已归档月份不动，只返回月份清单供界面提示；
 * - 同一人同月只保留一份未归档有效行（优先保留已属于新分类的行）；
 * - 调用方负责事务；本服务在同一事务内写迁移审计日志。
 */
class StaffCategoryMigration
{
    public const HQ_PROJECT = '物业公司总部';

    /** 分类 key → 中文（人员列表已归档标记文案用） */
    public const LABELS = ['staff' => '基层', 'manager' => '管理', 'case' => '案场', 'hq' => '总部'];

    public static function keyFor(?string $type, ?string $project): string
    {
        if ($project === self::HQ_PROJECT || $type === 'hq') return 'hq';
        if ($type === 'manager') return 'manager';
        if ($type === 'case') return 'case';
        return 'staff';
    }

    /** @return array{from:string,to:string,moved_months:array,moved_rows:int,deleted_rows:int,archived_months:array} */
    public function migrate(
        int $staffLegacyId,
        string $staffName,
        ?string $fromType,
        ?string $fromProject,
        string $toType,
        ?string $toProject,
        string $operator = '',
        string $source = 'staff_edit',
        string $reason = '',
        ?string $effectiveFrom = null
    ): array {
        $fromKey = self::keyFor($fromType, $fromProject);
        $toKey = self::keyFor($toType, $toProject);
        $targetProject = (string) ($toProject ?? '');
        $targetFlags = [
            'is_manager_row' => $toKey === 'manager',
            'is_case_row' => $toKey === 'case',
            'is_hq_row' => $toKey === 'hq',
        ];

        $rows = DB::table('payroll_results')
            ->where('staff_legacy_id', $staffLegacyId)
            ->orderBy('year_month')->orderBy('id')
            ->lockForUpdate()->get();

        $movedMonths = [];
        $movedRows = 0;
        $deletedRows = 0;
        $archivedMonths = [];

        foreach ($rows->groupBy('year_month') as $ym => $monthRows) {
            // 生效日之前的月份仍属于旧分类：不迁移、不提示
            if ($effectiveFrom && (string) $ym < substr($effectiveFrom, 0, 7)) continue;
            $archived = $monthRows->filter(fn ($r) => (int) $r->archived === 1);
            $unarchived = $monthRows->filter(fn ($r) => (int) $r->archived !== 1)->sortByDesc('id')->values();

            if ($archived->isNotEmpty()) {
                $mismatch = $archived->contains(fn ($r) => !$this->matches($r, $targetFlags, $targetProject));
                if ($mismatch) $archivedMonths[] = (string) $ym;
            }

            if ($unarchived->isEmpty()) continue;
            $targetRows = $unarchived->filter(fn ($r) => $this->matches($r, $targetFlags, $targetProject));
            $keep = $targetRows->isNotEmpty() ? $targetRows->first() : $unarchived->first();
            if ($targetRows->isEmpty()) {
                DB::table('payroll_results')->where('id', $keep->id)->update(array_merge($targetFlags, [
                    'project_name' => $targetProject,
                    'updated_at' => now(),
                ]));
                $movedRows++;
                $movedMonths[] = (string) $ym;
            }
            foreach ($unarchived as $row) {
                if ((int) $row->id === (int) $keep->id) continue;
                DB::table('payroll_results')->where('id', $row->id)->delete();
                $deletedRows++;
            }
        }

        $movedMonths = array_values(array_unique($movedMonths));
        sort($movedMonths);
        $archivedMonths = array_values(array_unique($archivedMonths));
        sort($archivedMonths);

        DB::table('payroll_staff_type_migrations')->insert([
            'staff_legacy_id' => $staffLegacyId,
            'staff_name' => $staffName,
            'from_type' => $fromKey,
            'to_type' => $toKey,
            'from_project' => $fromProject,
            'to_project' => $targetProject,
            'moved_months' => json_encode($movedMonths, JSON_UNESCAPED_UNICODE),
            'moved_rows' => $movedRows,
            'deleted_rows' => $deletedRows,
            'archived_months' => json_encode($archivedMonths, JSON_UNESCAPED_UNICODE),
            'operator' => $operator,
            'source' => $source,
            'reason' => mb_substr($reason, 0, 255),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'from' => $fromKey, 'to' => $toKey,
            'moved_months' => $movedMonths, 'moved_rows' => $movedRows,
            'deleted_rows' => $deletedRows, 'archived_months' => $archivedMonths,
        ];
    }

    private function matches(object $row, array $flags, string $project): bool
    {
        foreach ($flags as $k => $v) {
            if ((int) ($row->{$k} ?? 0) !== ($v ? 1 : 0)) return false;
        }
        return (string) ($row->project_name ?? '') === $project;
    }
}
