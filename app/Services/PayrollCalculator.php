<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 薪资核算服务：读取"系统设置→工资计算规则"（CalcRules），按 UI 配置的参数与
 * 公式驱动计算。原 PayrollWriteController::calculate() 的硬编码全部迁移到这里，
 * 并按 UI 里的 13 类规则做真正生效。
 *
 * 与旧实现的关键差异：
 *   1. 病假系数 / 旷工倍数 / 缺卡阶梯 / 试用期不计绩效 / 是否按日期分段 等
 *      全部从 calc_rules 读取；缺省值保持与旧代码一致，避免未配置时结果漂移。
 *   2. 应发(gross) 与 实发(net) 使用 rules.formula.gross / rules.formula.net
 *      通过 Expr 求值（表达式引擎，无 eval）。
 *   3. 累计预扣的起点受 tax.cum_start 影响（jan=当年一月, hire=入职月）。
 *   4. 无考勤数据时不再 delete+空 insert，避免误清空历史结果。
 *   5. 重算保留 archived 标记；未被重新核算的归档行原样回插，不再静默丢失。
 *   6. 公式求值失败（含未定义变量）直接抛异常，整月结果不落库，避免静默按 0 计薪。
 *   7. 考勤缺人不再静默跳过：missing 名单随结果返回，由前端透出提示。
 *   8. 写库走 GET_LOCK 命名锁 + chunk 批量插入，并记录规则快照哈希 rules_hash 便于追溯。
 */
class PayrollCalculator
{
    /** 类别到内部键的映射（考勤符号分类）。 */
    private const CATEGORIES = [
        '事假' => 'personal', '病假' => 'sick', '产假' => 'maternity', '年假调休' => 'paid',
        '缺卡' => 'miss', '旷工' => 'absent', '迟到' => 'late', '早退' => 'early',
    ];

    // 注意：welfare（已发福利/奖励）在应发公式中是加项（计入计税口径），在实发公式中是减项
    // （实发时扣回——即"福利计税但不实发"）。两条默认公式成对设计，对拍测试已锁定该口径；
    // 若在系统设置里自定义公式，必须成对同步调整，勿只改其一。
    private const DEFAULT_GROSS = 'base_pay + perf_pay + sick_pay + night + meal + title_sub + reward + welfare - punish - miss_d - late_d - other_d - uniform_d';

    /**
     * 绩效配置缺失错误码 → 中文文案。Task 3 归档检查将消费 perfErrorCode()
     * 据此决定是否拦截归档；文案需与 warnings danger reason 保持一致。
     */
    public const PERF_ERROR_CN = [
        'missing_coef'       => '季度/半年度绩效系数未录入',
        'missing_pay_grade'  => '薪酬档位缺失',
        'invalid_pay_grade'  => '薪酬档位不在专员级/主管级/经理级范围内',
        'missing_pay_rule'   => '该档位未配置绩效发放规则',
    ];

    /** 国家综合所得年度累计预扣率表（7 级），存储级距不全时的兜底 */
    public const DEFAULT_TAX_BRACKETS = [
        [36000, 0.03, 0], [144000, 0.10, 2520], [300000, 0.20, 16920],
        [420000, 0.25, 31920], [660000, 0.30, 52920], [960000, 0.35, 85920],
        [99999999999, 0.45, 181920],
    ];
    private const DEFAULT_NET   = 'gross - soc_total - actual_tax - welfare';

    public function __construct(private readonly CalcRules $rules = new CalcRules()) {}

    /** 总部载体项目名：该项目人员走"物业总部"自己的考勤/工资入口，不计入跨项目管理表 */
    public const HQ_PROJECT = '物业总部';

    /**
     * 考勤下拉框的跨项目虚拟"项目"：哨兵值 => [显示名, 人员分类]。
     * 下载 = 全公司该类人员（按项目分组）一张表；上传 = 按人合并回各自项目考勤块。
     */
    public const ATT_VIRTUAL_GROUPS = [
        '__managers__' => ['label' => '管理人员', 'category' => 'manager'],
        '__case__'     => ['label' => '案场人员', 'category' => 'case'],
    ];

    /**
     * 当月生效人员分类映射：legacy_id => category（staff/manager/case/hq）。
     *
     * person_type 保存"当前"分类，person_type_since 是该分类的生效日：
     *   - 生效日 <= 核算月月末：本月已按当前分类生效，直接使用；
     *   - 生效日 >  核算月月末：当前分类是该月之后才调整的，本月旧分类无记录可查，
     *     回退按基层员工（staff）核算并记日志——宁可少算，不冒算。
     */
    public static function categoryMap(string $ym): array
    {
        $monthEnd = date('Y-m-t', strtotime($ym . '-01'));
        $rows = DB::table('payroll_staff')->where('deleted', false)
            ->get(['legacy_id', 'person_type', 'person_type_since']);
        $map = [];
        foreach ($rows as $r) {
            $since = substr((string)($r->person_type_since ?? ''), 0, 10);
            if ($since !== '' && $since > $monthEnd && ($r->person_type ?: 'staff') !== 'staff') {
                \Illuminate\Support\Facades\Log::info('person_type_since 晚于核算月份，该月按基层员工核算', [
                    'legacy_id' => $r->legacy_id, 'person_type' => $r->person_type,
                    'person_type_since' => $since, 'ym' => $ym,
                ]);
                $map[$r->legacy_id] = 'staff';
                continue;
            }
            $map[$r->legacy_id] = $r->person_type ?: 'staff';
        }
        return $map;
    }

    /**
     * 计算并写库。返回 ['count'=>int,'skipped'=>array,'preserved_archived'=>int,'missing'=>array,'warnings'=>array]
     * 项目员工核算：限定项目清单，类别=staff。
     */
    public function calculate(string $ym, array $projects): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new RuntimeException('无效月份：' . $ym);
        }
        $projects = array_values(array_filter(array_map('strval', $projects), fn($p) => $p !== ''));
        if (!$projects) throw new RuntimeException('未指定项目');
        return $this->calculateGroup($ym, 'staff', [false, false, false], $projects);
    }

    /**
     * 管理人员核算：按月份汇总所有项目的管理人员（is_manager=1），
     * 生成独立的管理人员工资表（payroll_results.is_manager_row=1）。
     * 考勤仍取自各项目考勤块（管理人员随项目一起上传考勤）。
     */
    public function calculateManagers(string $ym): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new RuntimeException('无效月份：' . $ym);
        }
        return $this->calculateGroup($ym, 'manager', [true, false, false]);
    }

    /**
     * 案场人员核算：按月份汇总所有项目的案场人员（is_case_field=1），
     * 生成独立的案场人员工资表（payroll_results.is_case_row=1）。
     * 考勤仍取自各项目考勤块（案场人员随项目一起上传考勤）。
     */
    public function calculateCaseStaff(string $ym): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new RuntimeException('无效月份：' . $ym);
        }
        return $this->calculateGroup($ym, 'case', [false, true, false]);
    }

    /**
     * 总部人员核算：物业总部所有人员（无论是否管理人员/案场标记）单独核算，
     * 生成独立的总部人员工资表（payroll_results.is_hq_row=1）。
     * 考勤取自物业总部考勤块（总部考勤表单独上传）。
     */
    public function calculateHq(string $ym): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new RuntimeException('无效月份：' . $ym);
        }
        return $this->calculateGroup($ym, 'hq', [false, false, true]);
    }

    /**
     * 归档异常清单（danger 类）：locked=true 归档前调用，返回范围内需用户确认的异常名单。
     * 仅含两类：缺基数未核算者（missing_base）与绩效配置错误行（perf_error）；
     * info 类（net≤0）不在此列，不拦截归档。
     *
     * 与 calculateGroup 同源（同一份 symbols / categoryMap / 考勤块 / 行类型 where），
     * 防止两处口径漂移。findAttRow 保持 private，本方法作为同类实例方法用 $this 调用。
     *
     * @param string $ym    核算月 YYYY-MM
     * @param string $scope 'staff'|'manager'|'case'|'hq'
     * @return array<int,array{name:string,project:string,reason:string,kind:string}>
     */
    public function archiveBlockers(string $ym, string $scope): array
    {
        [$isManager, $isCase, $isHq] = match ($scope) {
            'manager' => [true, false, false],
            'case'    => [false, true, false],
            'hq'      => [false, false, true],
            default   => [false, false, false], // staff
        };

        $symbols   = self::symbols();
        $attBlocks = DB::table('payroll_attendance')->where('year_month', $ym)->where('locked', true)->get()->keyBy('project_name');
        $catMap    = self::categoryMap($ym);
        $staff     = DB::table('payroll_staff')->where('deleted', false)->get()
            ->filter(fn ($p) => ($catMap[$p->legacy_id] ?? 'staff') === $scope);

        $blockers = [];

        // 1) missing_base：薪资基数为 0 且当月有实际出勤的人员（核算时已被跳过，无 results 行）
        foreach ($staff as $p) {
            $block = $attBlocks[$isHq ? '物业总部' : $p->project_name] ?? null;
            if (!$block) continue; // 无考勤块无从判出勤，与核算 no_attendance 护栏一致
            $attRows = $this->jsonValue($block->rows) ?: [];
            $att = $this->findAttRow($attRows, $p);
            if ($att === null) continue; // 无考勤行属 info missing，不拦截归档
            $actual = self::actualAttendance($att, $symbols);
            if (self::hasMissingBase($p) && $actual > 0) {
                $isResigned = trim((string)$p->status) === '离职';
                $blockers[] = [
                    'name' => $p->name, 'project' => $p->project_name,
                    'reason' => $isResigned
                        ? "离职人员本月有出勤{$actual}天但固定月薪/基本工资均为0，未生成工资行；请补录钉钉花名册薪资并同步后重算"
                        : '薪资数据缺失（固定月薪/基本工资均为0），未生成工资行；请检查钉钉花名册同步后重算',
                    'kind' => 'missing_base',
                ];
            }
        }

        // 2) perf_error：已落库但绩效配置错误的 results 行（仅未归档范围；行类型 where 与 archive update 完全一致）
        $resQ = DB::table('payroll_results')->where('year_month', $ym)->where('archived', false);
        if ($isManager) {
            $resQ->where('is_manager_row', true)->where('is_hq_row', false);
        } elseif ($isCase) {
            $resQ->where('is_case_row', true);
        } elseif ($isHq) {
            $resQ->where('is_hq_row', true);
        } else {
            $resQ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
        }
        foreach ($resQ->get() as $r) {
            $row = $this->jsonValue($r->row_data) ?: [];
            $code = self::perfErrorCode($row);
            if ($code === null) continue;
            $blockers[] = [
                'name' => $row['name'] ?? '', 'project' => $row['project'] ?? $r->project_name,
                'reason' => '绩效工资未计入：' . (self::PERF_ERROR_CN[$code] ?? $code) . '，请补录后重算',
                'kind' => 'perf_error',
            ];
        }

        return $blockers;
    }

    /**
     * 四类人员共用的核算主体：取考勤 → 逐人计算 → 命名锁下事务内删旧插新（chunk）。
     *
     * @param string     $category 类别键（staff/manager/case/hq），来自 categoryMap
     * @param array      $flags    [is_manager_row, is_case_row, is_hq_row]
     * @param array|null $projects 仅项目核算（staff）用：限定项目清单；其余三类汇总全部项目
     * @return array{count:int,skipped:array,preserved_archived:int,missing:array,warnings:array}
     */
    private function calculateGroup(string $ym, string $category, array $flags, ?array $projects = null): array
    {
        [$isManager, $isCase, $isHq] = $flags;
        $symbols   = self::symbols();
        $attBlocks = DB::table('payroll_attendance')->where('year_month', $ym)->where('locked', true)->get()->keyBy('project_name');
        $catMap    = self::categoryMap($ym);
        $staffQ    = DB::table('payroll_staff')->where('deleted', false);
        if ($category === 'staff') $staffQ->whereIn('project_name', $projects);
        $staff = $staffQ->get()->filter(fn ($p) => ($catMap[$p->legacy_id] ?? 'staff') === $category);

        // 预取当年历史核算结果（累计预扣个税用），避免逐员工 N+1 查询
        $historyByStaff = collect();
        if (!$staff->isEmpty()) {
            $historyByStaff = DB::table('payroll_results')
                ->where('year_month', 'like', substr($ym, 0, 4) . '-%')
                ->where('year_month', '<', $ym)
                ->whereIn('staff_legacy_id', $staff->pluck('legacy_id')->all())
                ->get()->groupBy('staff_legacy_id');
        }

        // 安全护栏：该月没有任何可用考勤就别动已有结果（防止 recalc 把有结果无考勤的历史清空）
        if ($category === 'staff') {
            $anyAttendance = false;
            foreach ($projects as $p) { if (isset($attBlocks[$p])) { $anyAttendance = true; break; } }
            if (!$anyAttendance) return ['count' => 0, 'skipped' => ['no_attendance'], 'preserved_archived' => 0, 'missing' => [], 'warnings' => []];
        } elseif ($isHq) {
            // 总部人员考勤统一取"物业总部"考勤块（总部考勤表单独上传）
            if (!isset($attBlocks['物业总部'])) return ['count' => 0, 'skipped' => ['no_attendance'], 'preserved_archived' => 0, 'missing' => [], 'warnings' => []];
        } elseif ($attBlocks->isEmpty()) {
            return ['count' => 0, 'skipped' => ['no_attendance'], 'preserved_archived' => 0, 'missing' => [], 'warnings' => []];
        }

        $rows = []; $missing = []; $warnings = [];
        foreach ($staff as $person) {
            $block   = $attBlocks[$isHq ? '物业总部' : $person->project_name] ?? null;
            $attRows = $block ? ($this->jsonValue($block->rows) ?: []) : [];
            $att = $this->findAttRow($attRows, $person);
            if (!$att) {
                // 考勤缺人不再静默跳过：汇总进 missing（info），由调用方透传前端提示
                $missing[] = [
                    'name' => $person->name, 'project' => $person->project_name,
                    'reason' => trim((string)$person->status) === '离职'
                        ? '离职人员，本月无考勤记录（已跳过）'
                        : '考勤表中无此人的记录（已跳过）',
                    'level' => 'info',
                ];
                continue;
            }
            // 数据缺失护栏（适用所有人员）：薪资基数固定月薪/基本工资均为 0 且当月有实际出勤
            // → 不得静默出 0 工资行：跳过本行并进 missing danger 清单，提示补录/同步钉钉花名册后重算。
            // 有考勤行但实际出勤为 0（整月公休等）不在此列，正常核算出行。
            $actual = self::actualAttendance($att, $symbols);
            if (self::hasMissingBase($person) && $actual > 0) {
                $isResigned = trim((string)$person->status) === '离职';
                $missing[] = [
                    'name' => $person->name, 'project' => $person->project_name,
                    'reason' => $isResigned
                        ? "离职人员本月有出勤{$actual}天但固定月薪/基本工资均为0，未生成工资行；请补录钉钉花名册薪资并同步后重算"
                        : '薪资数据缺失（固定月薪/基本工资均为0），未生成工资行；请检查钉钉花名册同步后重算',
                    'level' => 'danger',
                ];
                continue;
            }
            $row = $this->computeRow($person, $att, $symbols, $ym, $historyByStaff[$person->legacy_id] ?? collect(), $category);
            // 行级 warnings（Task 2）：合法 0/负实发（info）与绩效配置缺失（danger）。
            // 行照常生成落库（只有 Task 1 基数缺失才跳过）；顺序：先 net info 再 perf danger。
            if ((float)($row['net'] ?? 0) <= 0.0) {
                $warnings[] = [
                    'name' => $row['name'], 'project' => $row['project'],
                    'reason' => '实发' . $row['net'] . '元（出勤' . $row['act_att'] . '天），请确认是否为产假/停薪等合法情形',
                    'level' => 'info',
                ];
            }
            $code = self::perfErrorCode($row);
            if ($code !== null) {
                $warnings[] = [
                    'name' => $row['name'], 'project' => $row['project'],
                    'reason' => '绩效工资未计入：' . (self::PERF_ERROR_CN[$code] ?? $code) . '，请补录后重算',
                    'level' => 'danger',
                ];
            }
            $rows[] = $row;
        }

        if (!$rows) return ['count' => 0, 'skipped' => ['no_matching_staff'], 'preserved_archived' => 0, 'missing' => $missing, 'warnings' => $warnings];

        // 统一按 项目→部门→岗位→姓名 排序后落库，列表与导出顺序一致
        $rows = self::orderRows($rows);
        $rulesHash = $this->rulesHash();

        $preserved = $this->withCalcLock($ym, function () use ($ym, $projects, $rows, $category, $isManager, $isCase, $isHq, $rulesHash) {
            return DB::transaction(function () use ($ym, $projects, $rows, $category, $isManager, $isCase, $isHq, $rulesHash) {
                $delQ = DB::table('payroll_results')->where('year_month', $ym);
                if ($category === 'staff') {
                    // 仅项目表行；管理人员行/案场人员行/总部行不受项目核算影响
                    $delQ->whereIn('project_name', $projects)
                        ->where('is_manager_row', false)->where('is_case_row', false)->where('is_hq_row', false);
                } elseif ($isManager) {
                    $delQ->where('is_manager_row', true)->where('is_hq_row', false);
                } elseif ($isCase) {
                    $delQ->where('is_case_row', true);
                } else {
                    $delQ->where('is_hq_row', true);
                }

                // 归档保全：先快照归档行；重算后未被重新核算的人原样回插，归档标记不再静默丢失
                $archivedRows = (clone $delQ)->where('archived', true)->get();
                $archivedIds  = $archivedRows->pluck('staff_legacy_id')->all();

                $delQ->delete();

                $records = []; $insertedIds = [];
                foreach ($rows as $row) {
                    $records[] = [
                        'year_month' => $ym, 'staff_legacy_id' => $row['staff_id'],
                        'project_name' => $row['project'], 'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
                        'rules_hash' => $rulesHash,
                        'archived' => false, 'is_manager_row' => (int)$isManager, 'is_case_row' => (int)$isCase, 'is_hq_row' => (int)$isHq,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $insertedIds[] = $row['staff_id'];
                }
                // chunk 批量插入，避免逐行 insert 的开销
                foreach (array_chunk($records, 100) as $chunk) {
                    DB::table('payroll_results')->insert($chunk);
                }

                // 未被本次重插的归档行原样回插（保留原 id/归档标记/历史数据）
                $restored = 0;
                foreach ($archivedRows as $ar) {
                    if (!in_array($ar->staff_legacy_id, $insertedIds, true)) {
                        DB::table('payroll_results')->insert((array)$ar);
                        $restored++;
                    }
                }

                // 重新核算过的归档人员：重插行恢复归档标记（唯一键 ym+staff_id 冲突，只能事后恢复）
                // 仅标记本次重插的人；原样回插者本身已带 archived=true，不得重复计数/更新
                $preserved = $restored;
                $reMarkIds = array_values(array_intersect($archivedIds, $insertedIds));
                if ($reMarkIds) {
                    $preserved += (clone $delQ)->whereIn('staff_legacy_id', $reMarkIds)
                        ->update(['archived' => true, 'updated_at' => now()]);
                }
                return $preserved;
            });
        });

        return ['count' => count($rows), 'skipped' => [], 'preserved_archived' => $preserved, 'missing' => $missing, 'warnings' => $warnings];
    }

    /**
     * 考勤行匹配：上传考勤时已按档案给每行打上 staff_id / dingtalk_userid 标记，
     * 核算时优先按 dingtalk_userid → staff_id 匹配（同名安全），最后回退姓名（兼容旧数据）。
     */
    private function findAttRow(array $attRows, object $person): ?array
    {
        $duid = trim((string)($person->dingtalk_userid ?? ''));
        if ($duid !== '') {
            foreach ($attRows as $row) {
                if (trim((string)($row['dingtalk_userid'] ?? '')) === $duid) return $row;
            }
        }
        foreach ($attRows as $row) {
            if ((int)($row['staff_id'] ?? 0) === (int)$person->legacy_id) return $row;
        }
        return $attRows[$person->name] ?? null;
    }

    /**
     * 薪资基数缺失判定：固定月薪与基本工资均为 0（松散比较，null/''/0 均视为 0）。
     * 仅缺一项（如固定月薪有值、基本工资为 0）不算缺失，正常核算。
     */
    public static function hasMissingBase(object $person): bool
    {
        return (float)$person->fixed_monthly == 0.0 && (float)$person->base_salary == 0.0;
    }

    /**
     * 实际出勤天数：优先取考勤行汇总值 act_attend；其 <=0（含缺失）时，
     * 按考勤符号回退统计 days 的出勤天数（与 computeRow 内口径一致）。
     */
    public static function actualAttendance(array $att, array $symbols): float
    {
        $actual = (float)($att['act_attend'] ?? 0);
        if ($actual <= 0) {
            $actual = (float)self::attendanceStats($att['days'] ?? [], $symbols)['attend'];
        }
        return $actual;
    }

    /**
     * 提取行的绩效错误码：顶层 perf_detail.error 优先；为空再取嵌套
     * perf_detail.half_year.error；均无或 perf_detail 不存在返回 null。
     */
    public static function perfErrorCode(array $rowData): ?string
    {
        $detail = $rowData['perf_detail'] ?? null;
        if (!is_array($detail)) return null;
        $top = trim((string)($detail['error'] ?? ''));
        if ($top !== '') return $top;
        $half = $detail['half_year'] ?? null;
        if (is_array($half)) {
            $h = trim((string)($half['error'] ?? ''));
            if ($h !== '') return $h;
        }
        return null;
    }

    /**
     * 核算写库互斥锁：同一月份同时只允许一个核算任务（MySQL GET_LOCK 命名锁），
     * 防止并发重算交错删插。非 MySQL 后端（如测试用 sqlite 内存库）没有命名锁，直接执行。
     */
    private function withCalcLock(string $ym, callable $fn)
    {
        $name = 'payroll_calc_' . $ym;
        try {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);
            $ok = $lock !== null && (int)($lock->acquired ?? 0) === 1;
        } catch (\Throwable) {
            $ok = true; // 非 MySQL 后端
        }
        if (!$ok) throw new RuntimeException('本月已有另一核算任务正在进行，请稍后重试');
        try {
            return $fn();
        } finally {
            try { DB::statement('DO RELEASE_LOCK(?)', [$name]); } catch (\Throwable) {}
        }
    }

    /**
     * 规则快照哈希：本次核算使用的 calc_rules + symbols 快照指纹，
     * 落库到 payroll_results.rules_hash，便于事后追溯该月按哪套规则计算。
     */
    private function rulesHash(): string
    {
        $snaps = DB::table('legacy_json_snapshots')
            ->whereIn('file_name', ['calc_rules.json', 'symbols.json'])
            ->pluck('payload', 'file_name');
        return md5(($snaps['calc_rules.json'] ?? '') . '|' . ($snaps['symbols.json'] ?? ''));
    }

    /** 单行完整计算 */
    private function computeRow(object $person, array $att, array $symbols, string $ym, $historyByStaff = null, string $category = 'staff'): array
    {
        $stats = self::attendanceStats($att['days'] ?? [], $symbols);

        // 应出勤 h 与 实际出勤 actual
        $h = (float)($att['req_attend'] ?? 0);
        if ($h <= 0) {
            // prorate_base=calendar 时按当月自然天数，否则按符号统计应出勤（与旧版/设计文档一致）
            $h = ($this->rules->str('base_salary.prorate_base', 'required') === 'calendar')
                ? (float)date('t', strtotime($ym . '-01'))
                : $stats['required'];
        }
        $actual = (float)($att['act_attend'] ?? 0); if ($actual <= 0) $actual = $stats['attend'];
        if ($h <= 0) $h = $actual > 0 ? (int)date('t', strtotime($ym . '-01')) : 1;

        $coef = array_key_exists('coef', $att) && $att['coef'] !== null && $att['coef'] !== ''
            ? (float)$att['coef'] : 1.0;

        // 分母统一为应出勤 H（旧版/设计文档：基本工资=Σ段基本×段出勤/H），
        // 与 prorate_base=calendar 联动；不再使用旧版未定义的 actual 分支。
        $denom = $h;
        if ($denom <= 0) $denom = 1.0;

        // 分段（可关闭 segment_by_date）
        $segments = $this->salarySegments($person, $ym);
        if (!$this->rules->flag('base_salary.segment_by_date', true)) {
            $segments = [[
                'from' => 1,
                'to' => (int)date('t', strtotime($ym . '-01')),
                'fixed' => (float)$person->fixed_monthly,
                'base' => (float)$person->base_salary,
                'probation' => $this->isProbationAllMonth($person, $ym),
            ]];
        }

        $segmentAttend = array_fill(0, count($segments), 0.0);
        $segmentSick   = array_fill(0, count($segments), 0);
        $segmentAbsent = array_fill(0, count($segments), 0);
        foreach (($att['days'] ?? []) as $dayIndex => $symbol) {
            $day = $dayIndex + 1;
            $item = self::symbolLookup((string)$symbol, $symbols);
            foreach ($segments as $i => $seg) {
                if ($day >= $seg['from'] && $day <= $seg['to']) {
                    if (!empty($item['in_actual'])) $segmentAttend[$i] += (float)($item['value'] ?? 1);
                    if (($item['category'] ?? '') === '病假') $segmentSick[$i]++;
                    if (($item['category'] ?? '') === '旷工') $segmentAbsent[$i]++;
                    break;
                }
            }
        }
        $symbolAttend = array_sum($segmentAttend);
        $scale = $symbolAttend > 0 && $actual > 0 ? $actual / $symbolAttend : 1.0;

        // 病假参数
        $sickEnabled = $this->rules->flag('sick_pay.enabled', true);
        $factorA     = $this->rules->num('sick_pay.params.factor_a', 0.7);
        $factorB     = $this->rules->num('sick_pay.params.factor_b', 0.6);
        $sickBase    = $this->rules->str('sick_pay.params.sick_base', 'base'); // base | fixed

        // 绩效参数
        $perfEnabled = $this->rules->flag('performance.enabled', true);
        $perfSkipProbation = $this->rules->flag('performance.probation_excluded', true);

        // 旷工扣款
        $absentEnabled = $this->rules->flag('deduction_rules.absent.enabled', true);
        $absentMult    = $this->rules->num('deduction_rules.absent.multiplier', 2);

        $basePay = 0.0; $perfPay = 0.0; $sickPay = 0.0; $absentFine = 0.0;
        foreach ($segments as $i => $seg) {
            $segDays = $segmentAttend[$i] * $scale;
            $basePay += $seg['base'] * $segDays / $denom;

            if ($perfEnabled && !($perfSkipProbation && !empty($seg['probation']))) {
                $perfPay += max(0, $seg['fixed'] - $seg['base']) * $coef * $segDays / $denom;
            }
            if ($sickEnabled) {
                $sickSegBase = $sickBase === 'fixed' ? $seg['fixed'] : $seg['base'];
                $sickPay += $segmentSick[$i] * $sickSegBase * $factorA * $factorB / $denom;
            }
            if ($absentEnabled) {
                // 旷工罚款 = Σ(段基本/H×(倍数−1)×段旷工天数)：旷工当日已在出勤扣回 1 倍，
                // 罚款仅按「倍数−1」计，避免多扣一倍（与旧版/设计文档 1:1）。
                $absentFine += $segmentAbsent[$i] * $seg['base'] * max(0, $absentMult - 1) / $denom;
            }
        }
        // 绩效计薪出勤：仅统计非试用段（试用期不参与绩效计薪，perf_att 显示 0）
        $perfAttend = 0.0;
        foreach ($segments as $i => $seg) {
            if ($perfEnabled && !($perfSkipProbation && !empty($seg['probation']))) {
                $perfAttend += $segmentAttend[$i] * $scale;
            }
        }
        $perfAttend = round($perfAttend, 2);

        $basePay = round($basePay, 2);
        $perfPay = round($perfPay, 2);
        $sickPay = round($sickPay, 2);

        // 满勤基准：供"微调"按出勤/系数可恢复地缩放 base_pay、perf_pay
        $basePayRef = $actual > 0 ? round($basePay * $h / $actual, 2) : $basePay;
        $perfPayRef = ($actual > 0 && $coef != 0.0) ? round($perfPay * $h / ($actual * $coef), 2) : $perfPay;

        // 季度绩效覆盖：管理/总部人员在季度末月按季度累计计算；7月/1月同时发半年度部分（两笔分开）
        $perfDetail = null;
        if (in_array($category, ['manager', 'hq'], true)) {
            $personData = $this->jsonValue($person->data) ?: [];
            $payGrade = trim((string)($personData['pay_grade'] ?? ''));
            $payRule = $this->rules->getPayRule($category, $payGrade);
            if (($payRule['cycle'] ?? 'monthly') === 'quarterly') {
                $year = (int)substr($ym, 0, 4);
                $month = (int)substr($ym, 5, 2);
                // 档位校验（显式失败，禁止静默按 default 发放）：空 → missing_pay_grade；不在三档 → invalid_pay_grade；未配比例 → missing_pay_rule
                $gradeError = null;
                if ($payGrade === '') {
                    $gradeError = 'missing_pay_grade';
                } elseif (!in_array($payGrade, CalcRules::PAY_GRADES, true)) {
                    $gradeError = 'invalid_pay_grade';
                } elseif (empty($payRule['configured'])) {
                    $gradeError = 'missing_pay_rule';
                }
                if ($this->isQuarterEnd($month)) {
                    // 季度部分：发"刚结束的季度"（4月发Q1、7月发Q2、10月发Q3、1月发上年Q4）
                    [$qKey, $qYms] = $this->quarterPeriod($year, $month);
                    if ($gradeError) {
                        $perfPay = 0.0;
                        $perfDetail = ['error' => $gradeError, 'period' => $qKey, 'pay_grade' => $payGrade];
                    } else {
                        [$qBase, $qMonths] = $this->accumulatePeriodPerf($person, $category, $qYms, $historyByStaff);
                        $qCoef = $this->periodCoef((int)$person->legacy_id, 'quarterly', $qKey);
                        if ($qCoef === null) {
                            // 缺季度系数：季度部分为 0 并标记（半年度部分有系数仍发）
                            $perfPay = 0.0;
                            $perfDetail = ['error' => 'missing_coef', 'period' => $qKey, 'pay_grade' => $payGrade];
                        } else {
                            $qRatio = $payRule['quarter_ratio'] ?? 0.0;
                            $perfPay = round($qBase * $qCoef * $qRatio, 2);
                            $perfDetail = [
                                'period' => $qKey, 'type' => 'quarterly', 'pay_grade' => $payGrade,
                                'ratio' => $qRatio, 'coef' => $qCoef,
                                'months' => $qMonths,
                            ];
                        }
                    }
                    // 半年度部分（7月发H1、1月发上年H2）
                    if ($this->isHalfYearEnd($month)) {
                        [$hKey, $hYms] = $this->halfYearPeriod($year, $month);
                        if ($gradeError) {
                            $perfDetail['half_year'] = ['error' => $gradeError, 'period' => $hKey, 'pay_grade' => $payGrade];
                        } else {
                            [$hBase, $hMonths] = $this->accumulatePeriodPerf($person, $category, $hYms, $historyByStaff);
                            $hCoef = $this->periodCoef((int)$person->legacy_id, 'half_year', $hKey);
                            if ($hCoef === null) {
                                $perfDetail['half_year'] = ['error' => 'missing_coef', 'period' => $hKey];
                            } else {
                                $hRatio = $payRule['half_year_ratio'] ?? 0.0;
                                $perfPay = round($perfPay + round($hBase * $hCoef * $hRatio, 2), 2);
                                $perfDetail['half_year'] = [
                                    'period' => $hKey, 'ratio' => $hRatio, 'coef' => $hCoef,
                                    'months' => $hMonths,
                                ];
                            }
                        }
                    }
                } else {
                    // 季度中：绩效为 0
                    $perfPay = 0.0;
                }
            } elseif (($payRule['cycle'] ?? '') === 'quarter_grade') {
                // 季度绩效法：经理级=季度型（季度末按 季度系数×Σ(基数×出勤比例) 全额发放，无比例、无半年度）；
                // 主管/专员级=月度型（不覆盖常规段当月绩效，不查季度系数）。
                $year = (int)substr($ym, 0, 4);
                $month = (int)substr($ym, 5, 2);
                $isQuarterMode = ($payRule['mode'] ?? 'monthly') === 'quarter';
                $gradeError = null;
                if ($payGrade === '') {
                    $gradeError = 'missing_pay_grade';
                } elseif (!in_array($payGrade, CalcRules::PAY_GRADES, true)) {
                    $gradeError = 'invalid_pay_grade';
                }
                if ($isQuarterMode) {
                    if ($this->isQuarterEnd($month)) {
                        [$qKey, $qYms] = $this->quarterPeriod($year, $month);
                        if ($gradeError) {
                            $perfPay = 0.0;
                            $perfDetail = ['error' => $gradeError, 'period' => $qKey, 'pay_grade' => $payGrade];
                        } else {
                            [$qBase, $qMonths] = $this->accumulatePeriodPerf($person, $category, $qYms, $historyByStaff);
                            $qCoef = $this->periodCoef((int)$person->legacy_id, 'quarterly', $qKey);
                            if ($qCoef === null) {
                                $perfPay = 0.0;
                                $perfDetail = ['error' => 'missing_coef', 'period' => $qKey, 'pay_grade' => $payGrade];
                            } else {
                                $perfPay = round($qBase * $qCoef, 2);
                                $perfDetail = [
                                    'period' => $qKey, 'type' => 'quarterly', 'pay_grade' => $payGrade,
                                    'coef' => $qCoef, 'months' => $qMonths,
                                ];
                            }
                        }
                    } else {
                        // 季度型·季度中月份：绩效为 0
                        $perfPay = 0.0;
                    }
                } elseif ($gradeError && $this->isQuarterEnd($month)) {
                    // 月度型档位异常仅在季度末月挂错（非末月无 perf_detail 展示位，当月按常规段发放）
                    $perfPay = 0.0;
                    [$qKey] = $this->quarterPeriod($year, $month);
                    $perfDetail = ['error' => $gradeError, 'period' => $qKey, 'pay_grade' => $payGrade];
                }
                // 月度型且档位正常：保留常规段 perfPay，无 perf_detail，不查询季度系数
            }
        }

        // 缺卡阶梯
        $missPunchEnabled = $this->rules->flag('deduction_rules.miss_punch.enabled', true);
        $missFirst3 = $this->rules->num('deduction_rules.miss_punch.first_3', 30);
        $missAfter3 = $this->rules->num('deduction_rules.miss_punch.after_3', 50);
        $missPenalty = $missPunchEnabled
            ? min($stats['miss'], 3) * $missFirst3 + max(0, $stats['miss'] - 3) * $missAfter3
            : 0.0;
        $miss = round((float)($att['miss_deduct'] ?? 0) + $missPenalty, 2);
        $other = round((float)($att['other_deduct'] ?? 0) + $absentFine, 2);

        // 五险一金（个人）
        $social = array_sum(array_map(
            fn($k) => (float)($att[$k] ?? 0), ['pen', 'med', 'une', 'house', 'big']
        ));

        // 专项附加：读员工表；rules.special_deduction.items 里若有 disabled 则过滤
        $data = $this->jsonValue($person->data) ?: [];
        $specials = collect($data['special_deductions'] ?? []);
        $items = $this->rules->raw('special_deduction.items', null);
        if (is_array($items)) {
            $disabled = [];
            foreach ($items as $it) {
                if (is_array($it) && array_key_exists('enabled', $it) && !$this->rulesFlag($it['enabled'])) {
                    $disabled[] = (string)($it['key'] ?? $it['name'] ?? '');
                }
            }
            if ($disabled) {
                $specials = $specials->reject(fn($x) => in_array((string)($x['type'] ?? $x['key'] ?? $x['name'] ?? ''), $disabled, true));
            }
        }
        $spec = $specials->sum(fn($item) => (float)($item['amount'] ?? 0));
        // 专项附加分项（供微调弹窗回显与全量重算，避免只改一项时其余项被清零）
        $specKeyMap = [
            '租房租金' => 'spec_rent', '住房贷款' => 'spec_loan', '住房贷款利息' => 'spec_loan',
            '子女教育' => 'spec_child', '赡养老人' => 'spec_elder',
            '继续教育' => 'spec_edu', '婴幼儿照护' => 'spec_baby', '婴幼儿' => 'spec_baby',
        ];
        $specBreak = ['spec_rent' => 0.0, 'spec_loan' => 0.0, 'spec_child' => 0.0,
            'spec_elder' => 0.0, 'spec_edu' => 0.0, 'spec_baby' => 0.0];
        foreach ($specials as $item) {
            $itemName = (string)($item['item'] ?? $item['name'] ?? $item['type'] ?? $item['key'] ?? '');
            foreach ($specKeyMap as $kw => $key) {
                if ($itemName !== '' && mb_strpos($itemName, $kw) !== false) {
                    $specBreak[$key] += (float)($item['amount'] ?? 0);
                    break;
                }
            }
        }

        // 奖惩整体开关
        $rpEnabled = $this->rules->flag('reward_punish.enabled', true);
        $rpFull = $this->rules->flag('reward_punish.full_in_gross', true);
        $reward  = $rpEnabled && $rpFull ? (float)($att['reward'] ?? 0) : 0.0;
        $welfare = $rpEnabled && $rpFull ? (float)($att['welfare'] ?? 0) : 0.0;
        $punish  = $rpEnabled ? (float)($att['punish'] ?? 0) : 0.0;

        // 餐补 / 其他津贴 mode（full=全额，prorate=按出勤折算且不超过全额，与旧版一致）
        $mealMode  = $this->rules->str('meal_subsidy.mode', 'full');
        $allowMode = $this->rules->str('allowances.mode', 'full');
        $prorate = static fn(float $v, string $mode): float =>
            $mode === 'prorate' && $h > 0 ? min($v, $v * ($actual / $h)) : $v;
        $meal      = $prorate((float)($att['meal_sub'] ?? 0), $mealMode);
        $night     = $prorate((float)($att['night_sub'] ?? 0), $allowMode);
        $titleSub  = $prorate((float)($att['title_sub'] ?? 0), $allowMode);
        // 迟到早退自动扣款：按统计次数核算（考勤表仅保留"迟到(次)/早退(次)"统计列，扣款列已移除）
        $lateEarlyEnabled = $this->rules->flag('deduction_rules.late_early.enabled', true);
        $lateEarlyPer = $this->rules->num('deduction_rules.late_early.per_time', 10);
        $lateD = $lateEarlyEnabled
            ? (float)(($stats['late'] ?? 0) + ($stats['early'] ?? 0)) * $lateEarlyPer
            : 0.0;
        // 兼容旧模板：若考勤表仍手填了迟到早退扣款，则取较大值（不重复叠加）
        $lateD = max($lateD, (float)($att['late_deduct'] ?? 0));
        $uniformD  = (float)($att['uniform_deduct'] ?? 0);

        // 变量池 → 交给用户公式
        $vars = [
            'base_pay' => $basePay, 'perf_pay' => $perfPay, 'sick_pay' => $sickPay,
            'night' => $night, 'meal' => $meal, 'title_sub' => $titleSub,
            'reward' => $reward, 'welfare' => $welfare,
            'punish' => $punish, 'late_d' => $lateD, 'miss_d' => $miss,
            'other_d' => $other, 'uniform_d' => $uniformD,
            'coef' => $coef, 'req_att' => $h, 'act_att' => $actual,
            'h' => $h, 'actual' => $actual,
        ];
        $__cfVars = $this->rules->raw('custom_fields', []);
        if (is_array($__cfVars)) { foreach ($__cfVars as $__f) { if (empty($__f['enabled'])) continue; $__cn = trim((string)($__f['name'] ?? '')); if ($__cn !== '') $vars[$__cn] = (float)($att['cf_' . $__cn] ?? $__f['default'] ?? 0); } }
        $gross = round($this->rules->evaluate(
            $this->rules->str('formula.gross', ''), $vars, self::DEFAULT_GROSS
        ), 2);
        // 公式失败（含变量名打错）立即中止整月核算，绝不把 0 应发静默落库
        if ($err = $this->rules->getLastError()) {
            throw new RuntimeException("应发公式计算失败（员工 {$person->name}）：{$err['error']}；表达式：{$err['expr']}");
        }

        // 累计预扣个税
        $taxConfig = $this->taxConfig();
        $basicDeduction = (float)($taxConfig['basic_deduction'] ?? 5000);
        // 个税扣除模式：0=普通（每月 basic_deduction 累计）；1=6万扣除（年初一次性按全年6万）
        $taxMode = (int)($data['tax_mode'] ?? 0);
        $cumStartMode = $this->rules->str('tax.cum_start', 'jan');
        $yearStart = substr($ym, 0, 4) . '-01';
        $hireMonth = $person->hire_date ? substr((string)$person->hire_date, 0, 7) : null;
        if ($hireMonth !== null) {
            // 有入职日：起算月 = max(入职月, 当年1月)，与旧版一致（跨年不多算月数）
            $startMonth = max($hireMonth, $yearStart);
        } else {
            // 无入职日：cum_start=month 按当月，否则当年1月
            $startMonth = $cumStartMode === 'month' ? $ym : $yearStart;
        }
        if ($startMonth > $ym) $startMonth = $ym;
        $monthNumber = 0; $cursor = $startMonth;
        while ($cursor <= $ym) { $monthNumber++; $cursor = date('Y-m', strtotime($cursor . '-01 +1 month')); }
        $incomeBefore = 0.0; $socialBefore = 0.0; $specBefore = 0.0; $taxBefore = 0.0;
        if ($monthNumber > 1) {
            foreach ($historyByStaff ?: collect() as $oldResult) {
                // 与累计口径一致：只累计个税起算月（含）之后的历史行，入职/复职前的旧行不计入
                $oldYm = substr((string)($oldResult->year_month ?? ''), 0, 7);
                if ($oldYm !== '' && $oldYm < $startMonth) continue;
                $old = $this->jsonValue($oldResult->row_data) ?: [];
                $incomeBefore  += (float)($old['gross'] ?? 0);
                $socialBefore  += (float)($old['soc_total'] ?? 0);
                $specBefore    += (float)($old['spec_total'] ?? 0);
                $taxBefore     += (float)($old['actual_tax'] ?? 0);
            }
        }
        // 独立计税口径：年中入职只累计本系统内（入职月起）的收入/扣除/已预扣税，
        // 原单位数据不叠加，年度汇算清缴由员工自行处理
        $cumIncome  = $incomeBefore + $gross;
        $cumSocial  = $socialBefore + $social;
        $cumSpec    = $specBefore + $spec;
        // 累计减除费用：6万扣除模式全年按60000一次性扣除；普通模式按 每月减除额 × 月份数 累计
        $cumDeduction = $taxMode === 1 ? 60000.0 : $basicDeduction * $monthNumber;
        $cumTaxable = max(0, $cumIncome - $cumDeduction - $cumSocial - $cumSpec);
        $cumTax     = $this->taxAmount($cumTaxable, $taxConfig);
        $tax        = max(0, round($cumTax - $taxBefore, 2));

        $vars['gross'] = $gross;
        $vars['soc_total'] = round($social, 2);
        $vars['spec_total'] = round($spec, 2);
        $vars['actual_tax'] = $tax;
        $net = round($this->rules->evaluate(
            $this->rules->str('formula.net', ''), $vars, self::DEFAULT_NET
        ), 2);
        if ($err = $this->rules->getLastError()) {
            throw new RuntimeException("实发公式计算失败（员工 {$person->name}）：{$err['error']}；表达式：{$err['expr']}");
        }

        $__cfResult = [];
        $__cfList = $this->rules->raw('custom_fields', []);
        if (is_array($__cfList)) { foreach ($__cfList as $__f) { if (empty($__f['enabled'])) continue; $__cn = trim((string)($__f['name'] ?? '')); if ($__cn !== '') $__cfResult[$__cn] = round((float)($att['cf_' . $__cn] ?? $__f['default'] ?? 0), 2); } }
        return array_merge([
            'staff_id' => $person->legacy_id, 'project' => $person->project_name,
            'department' => $this->deptName($person->dept_path ?? null, $person->project_name),
            'position' => $person->position ?: '', 'name' => $person->name,
            'status' => $this->rowStatus($person, $data, $ym), 'fixed' => (float)$person->fixed_monthly,
            'base' => (float)$person->base_salary,
            'req_att' => $h, 'act_att' => $actual, 'perf_att' => $perfAttend, 'coef' => $coef,
            'base_pay' => $basePay, 'perf_pay' => $perfPay,
            'base_pay_ref' => $basePayRef, 'perf_pay_ref' => $perfPayRef,
            'sick_days' => $stats['sick'], 'sick_pay' => $sickPay,
            'night' => $night, 'meal' => $meal, 'title_sub' => $titleSub,
            'reward' => $reward, 'welfare' => $welfare, 'punish' => $punish,
            'late_d' => $lateD, 'miss_d' => $miss, 'other_d' => $other,
            'uniform_d' => $uniformD, 'gross' => $gross,
            'pen' => (float)($att['pen'] ?? 0), 'med' => (float)($att['med'] ?? 0),
            'une' => (float)($att['une'] ?? 0), 'house' => (float)($att['house'] ?? 0),
            'big' => (float)($att['big'] ?? 0), 'soc_total' => round($social, 2),
            'spec_total' => round($spec, 2),
            'spec_rent' => round($specBreak['spec_rent'], 2),
            'spec_loan' => round($specBreak['spec_loan'], 2),
            'spec_child' => round($specBreak['spec_child'], 2),
            'spec_elder' => round($specBreak['spec_elder'], 2),
            'spec_edu' => round($specBreak['spec_edu'], 2),
            'spec_baby' => round($specBreak['spec_baby'], 2),
            'cum_deduction' => round($cumDeduction, 2),
            'cum_taxable' => round($cumTaxable, 2),
            'cum_tax' => $cumTax, 'paid_before' => round($taxBefore, 2),
            'withhold' => $tax, 'actual_tax' => $tax, 'tax_diff' => 0,
            'net' => $net, 'remark' => (string)($att['remark'] ?? ''),
            'bank_card' => $data['bank_card'] ?? '', 'overrides' => [],
            'perf_detail' => $perfDetail,
        ], $__cfResult);
    }

    /** 微调后重算派生列（gross/net/tax），保持行内其它字段不变。 */
    public function recomputeDerived(array $row, ?int $staffId = null, ?string $ym = null): array
    {
        $vars = [
            'base_pay' => (float)($row['base_pay'] ?? 0),
            'perf_pay' => (float)($row['perf_pay'] ?? 0),
            'sick_pay' => (float)($row['sick_pay'] ?? 0),
            'night' => (float)($row['night'] ?? 0), 'meal' => (float)($row['meal'] ?? 0),
            'title_sub' => (float)($row['title_sub'] ?? 0),
            'reward' => (float)($row['reward'] ?? 0), 'welfare' => (float)($row['welfare'] ?? 0),
            'punish' => (float)($row['punish'] ?? 0),
            'late_d' => (float)($row['late_d'] ?? 0), 'miss_d' => (float)($row['miss_d'] ?? 0),
            'other_d' => (float)($row['other_d'] ?? 0), 'uniform_d' => (float)($row['uniform_d'] ?? 0),
            'coef' => (float)($row['coef'] ?? 1),
            'req_att' => (float)($row['req_att'] ?? 0), 'act_att' => (float)($row['act_att'] ?? 0),
        ];
        $__cfR = $this->rules->raw('custom_fields', []);
        if (is_array($__cfR)) { foreach ($__cfR as $__f) { if (empty($__f['enabled'])) continue; $__cn = trim((string)($__f['name'] ?? '')); if ($__cn !== '') $vars[$__cn] = (float)($row[$__cn] ?? $__f['default'] ?? 0); } }
        $gross = round($this->rules->evaluate(
            $this->rules->str('formula.gross', ''), $vars, self::DEFAULT_GROSS), 2);
        // 公式失败立即中止微调重算，不允许静默按 0 落库
        if ($err = $this->rules->getLastError()) {
            throw new RuntimeException("应发公式计算失败（微调重算）：{$err['error']}；表达式：{$err['expr']}");
        }
        $soc = round(array_sum(array_map(
            fn($f) => (float)($row[$f] ?? 0), ['pen', 'med', 'une', 'house', 'big'])), 2);
        $row['soc_total'] = $soc;

        // 专项附加：若行内带 spec_* 字段（微调过）则重新汇总，否则沿用原 spec_total
        $specFields = ['spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby'];
        $hasSpecFields = count(array_intersect($specFields, array_keys($row))) > 0;
        if ($hasSpecFields) {
            // 缺失的分项沿用行内原值（旧结果可能只回传了被改的几项），避免误清零
            foreach ($specFields as $sf) {
                if (!array_key_exists($sf, $row)) $row[$sf] = 0.0;
            }
            $spec = round(array_sum(array_map(fn($f) => (float)($row[$f] ?? 0), $specFields)), 2);
        } else {
            $spec = round((float)($row['spec_total'] ?? 0), 2);
        }
        $row['spec_total'] = $spec;

        $taxConfig = $this->taxConfig();
        $basicDeduction = (float)($taxConfig['basic_deduction'] ?? 5000);

        // 有 staffId+ym 时按年度累计预扣重算个税（与核算 1:1，支持微调后正确计税）
        if ($staffId !== null && $ym !== null) {
            $person = DB::table('payroll_staff')->where('legacy_id', $staffId)->first();
            $personData = $person ? ($this->jsonValue($person->data) ?: []) : [];
            // 个税扣除模式：0=普通（每月 basic_deduction 累计）；1=6万扣除（年初一次性按全年6万）
            $taxMode = (int)($personData['tax_mode'] ?? 0);
            $cumStartMode = $this->rules->str('tax.cum_start', 'jan');
            $yearStart = substr($ym, 0, 4) . '-01';
            $hireMonth = $person && $person->hire_date
                ? substr((string)$person->hire_date, 0, 7) : null;
            if ($hireMonth !== null) {
                $startMonth = max($hireMonth, $yearStart);
            } else {
                $startMonth = $cumStartMode === 'month' ? $ym : $yearStart;
            }
            if ($startMonth > $ym) $startMonth = $ym;
            $monthNumber = 0; $cursor = $startMonth;
            while ($cursor <= $ym) { $monthNumber++; $cursor = date('Y-m', strtotime($cursor . '-01 +1 month')); }
            $incomeBefore = 0.0; $socialBefore = 0.0; $specBefore = 0.0; $taxBefore = 0.0;
            if ($monthNumber > 1) {
                // 历史累计仅取「个税起算月」之后的行：避免入职/复职前的旧年度记录被错误累计
                // （如年中入职、离职后重新入职——历史查询口径必须与 monthNumber 的起算月一致）
                $previous = DB::table('payroll_results')
                    ->where('staff_legacy_id', $staffId)
                    ->where('year_month', 'like', substr($ym, 0, 4) . '-%')
                    ->where('year_month', '>=', $startMonth)
                    ->where('year_month', '<', $ym)->get();
                foreach ($previous as $oldResult) {
                    $old = $this->jsonValue($oldResult->row_data) ?: [];
                    $incomeBefore += (float)($old['gross'] ?? 0);
                    $socialBefore += (float)($old['soc_total'] ?? 0);
                    $specBefore   += (float)($old['spec_total'] ?? 0);
                    $taxBefore    += (float)($old['actual_tax'] ?? 0);
                }
            }
            $cumIncome  = $incomeBefore + $gross;
            $cumSocial  = $socialBefore + $soc;
            $cumSpec    = $specBefore + $spec;
            // 累计减除费用：6万扣除模式全年按60000一次性扣除；普通模式按 每月减除额 × 月份数 累计
            $cumDeduction = $taxMode === 1 ? 60000.0 : $basicDeduction * $monthNumber;
            $cumTaxable = max(0, $cumIncome - $cumDeduction - $cumSocial - $cumSpec);
            $cumTax     = $this->taxAmount($cumTaxable, $taxConfig);
            $tax        = max(0, round($cumTax - $taxBefore, 2));
            $row['cum_deduction'] = round($cumDeduction, 2);
            $row['cum_taxable'] = round($cumTaxable, 2);
            $row['cum_tax'] = $cumTax;
            $row['paid_before'] = round($taxBefore, 2);
        } else {
            // 兜底：无 staffId/ym（历史调用）时按单月简化计税
            $monthTaxable = max(0, $gross - $soc - $basicDeduction - $spec);
            $tax = $this->taxAmount($monthTaxable, $taxConfig);
        }

        $vars['gross'] = $gross; $vars['soc_total'] = $soc; $vars['actual_tax'] = $tax;
        $net = round($this->rules->evaluate(
            $this->rules->str('formula.net', ''), $vars, self::DEFAULT_NET), 2);
        if ($err = $this->rules->getLastError()) {
            throw new RuntimeException("实发公式计算失败（微调重算）：{$err['error']}；表达式：{$err['expr']}");
        }

        $row['gross'] = $gross;
        $row['actual_tax'] = $tax;
        $row['withhold'] = $tax;
        $row['net'] = $net;
        return $row;
    }

    // ---------------- helpers ----------------

    private function rulesFlag(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return (bool)$v;
        if (is_string($v)) return !in_array(strtolower(trim($v)), ['false','0','no','off',''], true);
        return (bool)$v;
    }

    /**
     * 考勤符号表（symbols.json 快照）。考勤解析与薪资核算共用。
     */
    public static function symbols(): array
    {
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'symbols.json')->first();
        $items = $snap ? (json_decode((string)$snap->payload, true)['items'] ?? []) : [];
        return collect($items)->mapWithKeys(fn($i) => [(string)($i['symbol'] ?? '') => $i])->all();
    }

    /**
     * 符号查找：先精确匹配，未命中时按"勾号家族"别名容错（√/V/v/∨/✓/✔ 互认）。
     * 背景：生产符号库曾把正常出勤录成 V，而考勤表用 √，精确匹配查不到导致
     * 全月出勤按 0 天计、基本/绩效工资全 0。别名容错让两侧任一写法都能命中，
     * 已入库的历史考勤无需重传即可正确核算。
     */
    public static function symbolLookup(string $symbol, array $symbols): ?array
    {
        $key = trim($symbol);
        if ($key === '') return null;
        if (isset($symbols[$key])) return $symbols[$key];
        $alias = self::symbolAlias($key);
        foreach ($symbols as $sym => $item) {
            if (self::symbolAlias(trim((string)$sym)) === $alias) return $item;
        }
        return null;
    }

    private static function symbolAlias(string $s): string
    {
        return in_array($s, ['√', '∨', 'V', 'v', '✓', '✔'], true) ? '√' : $s;
    }

    /**
     * 符号库保存前的兼容性检查（9/27 符号错位事故防复发）：
     * 扫描历史考勤 rows.days 里实际出现过的符号字面，返回新符号集无法识别的冲突描述。
     * 核算端对识别不了的符号是静默跳过（attendanceStats L1000），一旦失配 → 出勤少算 → 工资错误，
     * 所以必须在保存时拦截，而不是核算时才发现。
     */
    public static function symbolConflicts(array $newItems): array
    {
        $map = [];
        foreach ($newItems as $it) {
            if (is_array($it) && isset($it['symbol'])) $map[trim((string) $it['symbol'])] = $it;
        }
        $checked = [];
        $conflicts = []; // 字面 => [record_key,...]
        $rows = DB::table('payroll_attendance')->orderBy('year_month')->get(['record_key', 'rows']);
        foreach ($rows as $r) {
            $recs = json_decode((string) $r->rows, true) ?: [];
            foreach ($recs as $rec) {
                foreach (($rec['days'] ?? []) as $s) {
                    $s = trim((string) $s);
                    if ($s === '' || isset($checked[$s])) continue;
                    $checked[$s] = true;
                    if (!self::symbolLookup($s, $map)) $conflicts[$s][] = $r->record_key;
                }
            }
        }
        $out = [];
        foreach ($conflicts as $sym => $keys) {
            $u = array_values(array_unique($keys));
            $out[] = "「{$sym}」出现于 " . implode('、', array_slice($u, 0, 3)) . (count($u) > 3 ? ' 等' . count($u) . '条记录' : '');
        }
        return $out;
    }

    private function taxConfig(): array
    {
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'calc_rules.json')->first();
        $payload = $snap ? (json_decode((string)$snap->payload, true) ?: []) : [];
        $tax = ($payload['rules']['tax'] ?? []) + [
            'basic_deduction' => 5000,
            'brackets' => self::DEFAULT_TAX_BRACKETS,
        ];
        // 若存储的级距缺失或非数组，回退标准 7 级，保证计算端永远有完整级距
        if (!is_array($tax['brackets'] ?? null) || $tax['brackets'] === []) {
            $tax['brackets'] = self::DEFAULT_TAX_BRACKETS;
        }
        return $tax;
    }

    private function taxAmount(float $taxable, array $config): float
    {
        $brackets = $config['brackets'] ?? [];
        if (!is_array($brackets) || $brackets === []) $brackets = self::DEFAULT_TAX_BRACKETS;
        $top = null;
        foreach ($brackets as $br) {
            $top = $br;
            if ($taxable <= (float)($br[0] ?? 0)) {
                return round(max(0, $taxable * (float)($br[1] ?? 0) - (float)($br[2] ?? 0)), 2);
            }
        }
        // 超过已配置的最高档：按最高档税率与速算扣除数计（避免级距不全时高收入者被算成 0）
        if ($top !== null) {
            return round(max(0, $taxable * (float)($top[1] ?? 0) - (float)($top[2] ?? 0)), 2);
        }
        return 0.0;
    }

    /**
     * 向薪资时间线追加一段薪资，并在需要时补全「变更前基线」。
     *
     * 背景：主表 fixed_monthly/base_salary 始终保存最新值，而 salarySegments 按日取
     * “截至当日最后生效的一段”，若时间线里只有“新值生效日”一条记录，则生效日之前的
     * 工作日会回退到主表最新值，导致月中调薪/转正当月前半段错按新基数。故在首次发生
     * 变更（时间线中没有严格更早的记录）时，补一条入职日生效、值为变更前旧薪的基线。
     *
     * @param array       $history      原 salary_history
     * @param string|null $hireDate     入职日期（基线生效日，缺失时退化为本次生效日）
     * @param float       $oldFixed     变更前固定月薪
     * @param float       $oldBase      变更前基本工资
     * @param string      $effective    本次新生效日 Y-m-d
     * @param float       $newFixed     变更后固定月薪
     * @param float       $newBase      变更后基本工资
     * @param array       $meta         新段附加字段（type/note 等）
     */
    public static function appendSalarySegment(array $history, ?string $hireDate,
        float $oldFixed, float $oldBase, string $effective,
        float $newFixed, float $newBase, array $meta = []): array
    {
        $eff = substr((string)$effective, 0, 10);
        $hasEarlier = false;
        foreach ($history as $h) {
            $ed = substr((string)($h['effective_date'] ?? ''), 0, 10);
            if ($ed !== '' && $ed < $eff) { $hasEarlier = true; break; }
        }
        if (!$hasEarlier) {
            $hire = substr((string)($hireDate ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire)) $hire = $eff;
            if ($hire < $eff) {
                $history[] = ['effective_date' => $hire, 'fixed_monthly' => $oldFixed,
                    'base_salary' => $oldBase, 'type' => '入职定薪', 'note' => '系统补全薪资基线'];
            }
        }
        // 幂等：移除同一生效日的旧记录后再追加
        $history = array_values(array_filter($history,
            fn ($h) => substr((string)($h['effective_date'] ?? ''), 0, 10) !== $eff));
        $history[] = array_merge(['effective_date' => $eff, 'fixed_monthly' => $newFixed, 'base_salary' => $newBase], $meta);
        usort($history, fn ($a, $b) => strcmp((string)($a['effective_date'] ?? ''), (string)($b['effective_date'] ?? '')));
        return $history;
    }

    private function salarySegments(object $person, string $ym): array
    {
        $data = $this->jsonValue($person->data) ?: [];
        $history = $data['salary_history'] ?? [];
        if (!$history) {
            $history = [[
                'effective_date' => $person->hire_date,
                'fixed_monthly'  => $person->fixed_monthly,
                'base_salary'    => $person->base_salary,
            ]];
        }
        usort($history, fn($a, $b) => strcmp((string)($a['effective_date'] ?? ''), (string)($b['effective_date'] ?? '')));
        $days = (int)date('t', strtotime($ym . '-01'));
        $daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%s-%02d', $ym, $d);
            $current = ['fixed' => (float)$person->fixed_monthly, 'base' => (float)$person->base_salary];
            foreach ($history as $e) {
                if (($e['effective_date'] ?? '') === '' || substr((string)$e['effective_date'], 0, 10) <= $date) {
                    $current = ['fixed' => (float)($e['fixed_monthly'] ?? 0), 'base' => (float)($e['base_salary'] ?? 0)];
                }
            }
            $regular = $data['regular_date'] ?? $person->regular_date;
            // 离职会吞掉在职时的试用标记：无实际转正日的离职人员，用花名册
            // 「计划转正日期」（data.planned_regular_date，离职后仍保留）逐日兜底
            $planned = $data['planned_regular_date'] ?? null;
            $current['probation'] = StaffStatus::isProbationOnDate(
                $regular ? (string)$regular : null,
                $planned !== null ? (string)$planned : null,
                (string)($person->status ?? ''),
                $date
            );
            $daily[] = $current;
        }
        $segments = [];
        foreach ($daily as $i => $v) {
            $day = $i + 1; $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['fixed'] === $v['fixed']
                && $segments[$last]['base'] === $v['base']
                && $segments[$last]['probation'] === $v['probation']) {
                $segments[$last]['to'] = $day;
            } else {
                $segments[] = ['from' => $day, 'to' => $day] + $v;
            }
        }
        return $segments;
    }

    private function isProbationAllMonth(object $person, string $ym): bool
    {
        $data = $this->jsonValue($person->data) ?: [];
        $regular = $data['regular_date'] ?? $person->regular_date;
        $planned = $data['planned_regular_date'] ?? null;
        $lastDay = date('Y-m-t', strtotime($ym . '-01'));
        return StaffStatus::isProbationOnDate(
            $regular ? (string)$regular : null,
            $planned !== null ? (string)$planned : null,
            (string)($person->status ?? ''),
            $lastDay
        );
    }

    /**
     * 从 dept_path（形如 "罗庄春暖花开/客服部"）解析末级部门名；末级等于项目名时回退上一级。
     */
    private function deptName(?string $deptPath, string $project): string
    {
        $path = trim((string)$deptPath);
        if ($path === '') return '';
        $parts = array_values(array_filter(array_map('trim', preg_split('#[\\\/]#u', $path)), fn($s) => $s !== ''));
        if (!$parts) return '';
        $last = end($parts);
        if ($last === $project && count($parts) >= 2) $last = $parts[count($parts) - 2];
        return $last === $project ? '' : (string)$last;
    }

    /**
     * 结果行统一排序：项目 → 部门 → 岗位 → 姓名（列表展示与导出共用，保证顺序一致）。
     */
    public static function orderRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            foreach (['project', 'department', 'position', 'name'] as $k) {
                $cmp = strcmp((string)($a[$k] ?? ''), (string)($b[$k] ?? ''));
                if ($cmp !== 0) return $cmp;
            }
            return 0;
        });
        return $rows;
    }

    /**
     * 结果行人员状态：与 salarySegments 的试用期判定保持同一口径。
     * 无转正日但档案状态为「试用」的新员工，结果行也展示试用（否则会出现“状态正式却不发绩效”的矛盾）；
     * 离职仍优先（StaffStatus::derive 内离职日优先）。
     */
    private function rowStatus(object $person, array $data, string $ym): string
    {
        // status 列已明确为离职（钉钉离职名单为权威）时一律按离职处理，即使未取到离职日期
        if (trim((string)($person->status ?? '')) === '离职') return '离职';
        $regular = $data['regular_date'] ?? $person->regular_date;
        $status = StaffStatus::derive($person->resign_date ?? null, $regular, StaffStatus::monthEnd($ym));
        if (!$regular && (string)($person->status ?? '') === '试用' && empty($person->resign_date)) {
            $status = '试用';
        }
        return $status;
    }

    /**
     * 考勤符号统计（应出勤/实际出勤/各类别次数）。与 PayrollController 共用同一口径。
     */
    public static function attendanceStats(array $days, array $symbols): array
    {
        $stats = ['required'=>0,'attend'=>0,'personal'=>0,'sick'=>0,'maternity'=>0,'paid'=>0,
            'miss'=>0,'absent'=>0,'late'=>0,'early'=>0];
        foreach ($days as $symbol) {
            $symbol = trim((string)$symbol);
            if ($symbol === '') continue;
            $item = self::symbolLookup($symbol, $symbols);
            if (!$item) continue;
            if (!empty($item['in_required'])) $stats['required'] += 1;
            if (!empty($item['in_actual']))   $stats['attend'] += (float)($item['value'] ?? 1);
            $cat = self::CATEGORIES[$item['category'] ?? ''] ?? null;
            if ($cat) $stats[$cat]++;
        }
        return $stats;
    }

    private function jsonValue($v): ?array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : null; }
        return null;
    }

    // ---------------- 季度/半年度绩效 ----------------

    private function isQuarterEnd(int $month): bool
    {
        return in_array($month, [4, 7, 10, 1], true);
    }

    private function isHalfYearEnd(int $month): bool
    {
        return in_array($month, [7, 1], true);
    }

    /** 季度末月对应的"刚结束季度"：返回 [periodKey, [ym,...]]，1月归上年 Q4 */
    private function quarterPeriod(int $year, int $month): array
    {
        return match ($month) {
            4 => ["{$year}-Q1", ["{$year}-01", "{$year}-02", "{$year}-03"]],
            7 => ["{$year}-Q2", ["{$year}-04", "{$year}-05", "{$year}-06"]],
            10 => ["{$year}-Q3", ["{$year}-07", "{$year}-08", "{$year}-09"]],
            1 => [($year - 1) . '-Q4', [($year - 1) . '-10', ($year - 1) . '-11', ($year - 1) . '-12']],
        };
    }

    /** 半年度末月对应的"刚结束半年度"：返回 [periodKey, [ym,...]]，1月归上年 H2 */
    private function halfYearPeriod(int $year, int $month): array
    {
        return match ($month) {
            7 => ["{$year}-H1", ["{$year}-01", "{$year}-02", "{$year}-03", "{$year}-04", "{$year}-05", "{$year}-06"]],
            1 => [($year - 1) . '-H2', [($year - 1) . '-07', ($year - 1) . '-08', ($year - 1) . '-09', ($year - 1) . '-10', ($year - 1) . '-11', ($year - 1) . '-12']],
        };
    }

    /** 累计一个周期内各月绩效基数（fixed-base × 绩效出勤/应出勤）：返回 [合计基数, 逐月明细] */
    private function accumulatePeriodPerf(object $person, string $category, array $yms, $historyByStaff): array
    {
        $total = 0.0;
        $months = [];
        foreach ($yms as $m) {
            $histRow = $historyByStaff ? $historyByStaff->firstWhere('year_month', $m) : null;
            if (!$histRow) {
                $histRow = DB::table('payroll_results')
                    ->where('staff_legacy_id', $person->legacy_id)
                    ->where('year_month', $m)
                    ->where('is_manager_row', $category === 'manager')
                    ->where('is_case_row', false)
                    ->where('is_hq_row', $category === 'hq')
                    ->first();
            }
            if ($histRow) {
                $histData = $this->jsonValue($histRow->row_data) ?: [];
                $monthBase = max(0, (float)($histData['fixed'] ?? 0) - (float)($histData['base'] ?? 0));
                $monthAttend = (float)($histData['perf_att'] ?? 0);
                $monthAmount = $monthBase * $monthAttend / max(1, (float)($histData['req_att'] ?? 1));
                $total += $monthAmount;
                $months[] = [
                    'ym' => $m, 'perf_att' => $monthAttend,
                    'base' => $monthBase, 'amount' => round($monthAmount, 2),
                ];
            }
        }
        return [$total, $months];
    }

    /** 查周期系数；未录入返回 null（区别于合法录入的 0.0） */
    private function periodCoef(int $legacyId, string $type, string $key): ?float
    {
        $row = DB::table('payroll_period_coefs')
            ->where('staff_legacy_id', $legacyId)
            ->where('period_type', $type)
            ->where('period_key', $key)
            ->first();
        return $row ? (float)$row->coef : null;
    }
}
