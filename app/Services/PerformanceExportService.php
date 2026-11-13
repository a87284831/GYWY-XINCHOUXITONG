<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 绩效导出（独立模块）数据服务：只读 payroll_results / payroll_staff / calc_rules，
 * 按「人员类别 + 发放法」组装 Sheet、列定义、行值与提示清单。
 *
 * 口径：
 *   - 季度按结算月归属（4→Q1、7→Q2、10→Q3、1→上年 Q4）；
 *   - 季度数据优先 row_data.perf_quarter（新口径），回退 perf_detail（旧口径，含 half_year）；
 *   - 月度数据取 row_data.coef / perf_att / perf_req / perf_base_month / perf_pay；
 *   - 系数 0 与 1.0 都是合法值，只有 null（未录入）才标异常。
 */
class PerformanceExportService
{
    public const CATS = [
        'staff'   => '基层员工',
        'manager' => '管理人员',
        'case'    => '案场人员',
        'hq'      => '总部人员',
    ];

    public const METHOD_LABELS = [
        'follow'      => '跟随人员配置（pay_rules）',
        'monthly'     => '统一按月度绩效法',
        'quarter'     => '统一按季度绩效法',
        'installment' => '统一按分期兑现绩效法',
    ];

    private const METHOD_SHORT = [
        'monthly'     => '月度绩效法',
        'quarter'     => '季度绩效法（季度型）',
        'installment' => '分期兑现绩效法',
    ];

    private const METHOD_SUFFIX = [
        'monthly'     => '月度',
        'quarter'     => '季度',
        'installment' => '分期兑现',
    ];

    private const PERF_ERROR_LABELS = [
        'missing_coef'      => '缺周期系数，未发放（请录入系数后重新核算）',
        'missing_pay_grade' => '缺薪酬档位',
        'invalid_pay_grade' => '薪酬档位非法',
        'missing_pay_rule'  => '该档位未配置绩效规则',
    ];

    private const CAT_ORDER = ['staff' => 0, 'manager' => 1, 'case' => 2, 'hq' => 3];
    private const METHOD_ORDER = ['quarter' => 0, 'monthly' => 1, 'installment' => 2];

    public function __construct(private readonly CalcRules $calcRules = new CalcRules()) {}

    /** 结算月是否季度末月（4/7/10/1） */
    public function isQuarterEnd(string $ym): bool
    {
        return $this->quarterPeriod($ym) !== null;
    }

    /** 结算月归属的刚结束季度：[periodKey, [ym,...]]；非季度末月返回 null */
    public function quarterPeriod(string $ym): ?array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) return null;
        $year = (int) substr($ym, 0, 4);
        $month = (int) substr($ym, 5, 2);
        return match ($month) {
            4  => ["{$year}-Q1", ["{$year}-01", "{$year}-02", "{$year}-03"]],
            7  => ["{$year}-Q2", ["{$year}-04", "{$year}-05", "{$year}-06"]],
            10 => ["{$year}-Q3", ["{$year}-07", "{$year}-08", "{$year}-09"]],
            1  => [($year - 1) . '-Q4', [($year - 1) . '-10', ($year - 1) . '-11', ($year - 1) . '-12']],
            default => null,
        };
    }

    /** 发放周期配置（页面右侧展示） */
    public function configRows(): array
    {
        $rows = [];
        foreach (self::CATS as $cat => $label) {
            $rows[] = ['label' => $label, 'value' => $this->cycleLabel($cat)];
        }
        $quarterlyCats = [];
        foreach (array_keys(self::CATS) as $cat) {
            if (($this->calcRules->raw('pay_rules.' . $cat . '.cycle', 'monthly') ?: 'monthly') === 'quarterly') {
                $quarterlyCats[] = self::CATS[$cat];
            }
        }
        $rows[] = [
            'label' => '分期兑现法',
            'value' => $quarterlyCats ? '已启用（' . implode('、', $quarterlyCats) . '）' : '当前未启用',
        ];
        return $rows;
    }

    private function cycleLabel(string $cat): string
    {
        $cycle = (string) ($this->calcRules->raw('pay_rules.' . $cat . '.cycle', 'monthly') ?: 'monthly');
        if ($cycle === 'monthly') return '月度绩效法';
        if ($cycle === 'quarterly') return '分期兑现绩效法（按档位比例）';
        if ($cycle === 'quarter_grade') {
            $levels = $this->calcRules->raw('pay_rules.' . $cat . '.levels', []);
            $levels = is_array($levels) ? $levels : [];
            $parts = [];
            foreach (CalcRules::PAY_GRADES as $grade) {
                $mode = (string) ($levels[$grade]['mode'] ?? 'monthly');
                $parts[] = $grade . '=' . ($mode === 'quarter' ? '季度型' : '月度型');
            }
            return '季度绩效法：' . implode('、', $parts);
        }
        return '月度绩效法';
    }

    /**
     * 组装导出数据集（不生成文件）。
     *
     * @param string[] $cats 已校验的类别键
     * @return array{ym:string,quarter:?string,quarter_months:array,is_quarter_end:bool,method:string,method_label:string,
     *   sheets:array,categories:array,warnings:array,ratio_over_one:array,config:array,filename:string}
     */
    public function dataset(
        string $ym,
        array $cats,
        string $project,
        string $method,
        bool $projectScope = false,
        ?string $scopeProject = null
    ): array {
        $cats = array_values(array_filter(array_unique($cats), fn ($c) => isset(self::CATS[$c])));
        if (!$cats) throw new RuntimeException('未选择人员类别');

        $period = $this->quarterPeriod($ym);
        $quarterKey = $period[0] ?? null;
        $quarterMonths = $period[1] ?? [];

        $query = DB::table('payroll_results')->where('year_month', $ym);
        if ($projectScope) {
            $query->where('project_name', (string) $scopeProject);
        } elseif ($project !== '') {
            $query->where('project_name', $project);
        }
        $records = $query->orderBy('id')->get();
        if ($records->isEmpty()) throw new RuntimeException('无核算数据');

        $staffIds = $records->pluck('staff_legacy_id')->filter()->unique()->values()->all();
        $staffMap = $staffIds
            ? DB::table('payroll_staff')->whereIn('legacy_id', $staffIds)->get()->keyBy('legacy_id')
            : collect();

        $catStat = [];
        foreach ($cats as $cat) $catStat[$cat] = ['count' => 0, 'archived' => 0, 'methods' => []];
        $entries = [];

        foreach ($records as $rec) {
            $row = $this->jsonValue($rec->row_data) ?: [];
            if (!$row) continue;
            $cat = $this->rowCategory($rec);
            if (!isset(self::CATS[$cat]) || !in_array($cat, $cats, true)) continue;
            // 项目账号不可见管理人员/总部人员（与现有绩效专项表权限一致）
            if ($projectScope && in_array($cat, ['manager', 'hq'], true)) continue;

            $catStat[$cat]['count']++;
            if ((bool) $rec->archived) $catStat[$cat]['archived']++;

            $staffId = (int) ($rec->staff_legacy_id ?: ($row['staff_id'] ?? 0));
            $staff = $staffMap[$staffId] ?? null;
            $entry = $this->buildEntry($row, $cat, $ym, $method, $quarterMonths, $staff);
            $catStat[$cat]['methods'][$entry['method']] = true;
            $entries[] = $entry;
        }

        // 稳定排序：类别 → 项目 → 姓名
        usort($entries, function ($a, $b) {
            return (self::CAT_ORDER[$a['cat']] <=> self::CAT_ORDER[$b['cat']])
                ?: strcmp((string) $a['project'], (string) $b['project'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        // 按「类别 + 发放法」分组
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry['cat'] . '|' . $entry['method']][] = $entry;
        }
        uksort($grouped, function ($a, $b) {
            [$ca, $ma] = explode('|', $a, 2);
            [$cb, $mb] = explode('|', $b, 2);
            return (self::CAT_ORDER[$ca] <=> self::CAT_ORDER[$cb])
                ?: (self::METHOD_ORDER[$ma] <=> self::METHOD_ORDER[$mb]);
        });

        $sheets = [];
        $usedTitles = [];
        foreach ($grouped as $key => $groupEntries) {
            [$cat, $groupMethod] = explode('|', $key, 2);
            $title = $this->sheetTitle($cat, $groupMethod, array_keys($catStat[$cat]['methods']), $method === 'follow');
            $title = $this->uniqueTitle($title, $usedTitles);
            $columns = $this->columnsFor($groupMethod, $quarterMonths, $groupEntries);
            foreach ($groupEntries as $i => $entry) {
                $entry['seq'] = $i + 1;
                $entry['values'] = $this->valuesFor($entry, $groupMethod, $quarterMonths);
                $groupEntries[$i] = $entry;
            }
            $sheets[] = [
                'title' => $title,
                'cat' => $cat,
                'cat_label' => self::CATS[$cat],
                'method' => $groupMethod,
                'method_label' => self::METHOD_SHORT[$groupMethod],
                'columns' => $columns,
                'entries' => $groupEntries,
                'sample' => array_slice(array_map(fn ($e) => $e['values'], $groupEntries), 0, 3),
            ];
        }

        $ratioOverOne = [];
        $anomalies = [];
        foreach ($entries as $entry) {
            if ($entry['ratio'] !== null && $entry['ratio'] > 1.0001) {
                $ratioOverOne[] = [
                    'cat' => $entry['cat_label'], 'project' => $entry['project'],
                    'name' => $entry['name'], 'ratio' => round($entry['ratio'], 2),
                ];
            }
            if (!empty($entry['is_anomaly'])) {
                $anomalies[] = $entry;
            }
        }

        $categories = [];
        foreach ($cats as $cat) {
            $stat = $catStat[$cat];
            $methodKeys = array_keys($stat['methods']);
            $methodLabel = $methodKeys
                ? implode('、', array_map(fn ($m) => self::METHOD_SHORT[$m], $methodKeys))
                : $this->defaultMethodLabel($cat);
            if ($stat['count'] === 0) {
                $status = '无核算行'; $level = 'danger';
            } elseif ($stat['archived'] < $stat['count']) {
                $status = $stat['count'] . ' 条，' . ($stat['count'] - $stat['archived']) . ' 条未归档';
                $level = 'warn';
            } else {
                $status = $stat['count'] . ' 条，已归档'; $level = 'ok';
            }
            $categories[] = [
                'key' => $cat, 'label' => self::CATS[$cat], 'count' => $stat['count'],
                'archived_count' => $stat['archived'], 'status' => $status, 'level' => $level,
                'method_label' => $methodLabel,
            ];
        }

        $warnings = $this->warnings($ym, $quarterKey, $quarterMonths, $categories, $anomalies, $ratioOverOne, $project, $method);

        $methodPart = $method === 'follow' ? '跟随发放' : self::METHOD_LABELS[$method];
        $filename = '绩效导出_' . $ym . '_' . $methodPart . '.xlsx';

        return [
            'ym' => $ym,
            'quarter' => $quarterKey,
            'quarter_months' => $quarterMonths,
            'is_quarter_end' => $period !== null,
            'method' => $method,
            'method_label' => self::METHOD_LABELS[$method] ?? $method,
            'sheets' => $sheets,
            'categories' => $categories,
            'warnings' => $warnings,
            'ratio_over_one' => $ratioOverOne,
            'config' => $this->configRows(),
            'filename' => $filename,
        ];
    }

    // ---------------- 行解析 ----------------

    private function rowCategory(object $rec): string
    {
        if ((bool) $rec->is_hq_row) return 'hq';
        if ((bool) $rec->is_manager_row) return 'manager';
        if ((bool) $rec->is_case_row) return 'case';
        return 'staff';
    }

    /** 按 pay_rules + 薪酬档位解析实际发放法 */
    private function resolveMethod(string $cat, string $override, string $grade): string
    {
        if ($override !== 'follow') return $override;
        $rule = $this->calcRules->getPayRule($cat, $grade);
        return match ($rule['cycle'] ?? 'monthly') {
            'quarter_grade' => (($rule['mode'] ?? 'monthly') === 'quarter') ? 'quarter' : 'monthly',
            'quarterly' => 'installment',
            default => 'monthly',
        };
    }

    private function defaultMethodLabel(string $cat): string
    {
        $cycle = (string) ($this->calcRules->raw('pay_rules.' . $cat . '.cycle', 'monthly') ?: 'monthly');
        return match ($cycle) {
            'quarter_grade' => '季度绩效法（按档位）',
            'quarterly' => '分期兑现绩效法',
            default => '月度绩效法',
        };
    }

    private function buildEntry(array $row, string $cat, string $ym, string $override, array $quarterMonths, ?object $staff): array
    {
        $staffData = $staff ? ($this->jsonValue($staff->data ?? null) ?: []) : [];
        $fixed = round((float) ($row['fixed'] ?? 0), 2);
        $base = round((float) ($row['base'] ?? 0), 2);
        $coef = array_key_exists('coef', $row) && is_numeric($row['coef']) ? (float) $row['coef'] : null;
        $reqAtt = (float) ($row['req_att'] ?? 0);
        $perfAtt = (float) ($row['perf_att'] ?? ($row['act_att'] ?? 0));
        $perfReq = array_key_exists('perf_req', $row) && is_numeric($row['perf_req'])
            ? (float) $row['perf_req'] : $reqAtt;
        $perfBaseMonth = array_key_exists('perf_base_month', $row) && is_numeric($row['perf_base_month'])
            ? (float) $row['perf_base_month'] : max(0.0, $fixed - $base);

        $q = is_array($row['perf_quarter'] ?? null) ? $row['perf_quarter'] : null;
        $d = is_array($row['perf_detail'] ?? null) ? $row['perf_detail'] : null;
        $qErr = is_array($q) ? ($q['error'] ?? null) : null;
        $dErr = is_array($d) ? ($d['error'] ?? null) : null;

        $grade = trim((string) ($q['pay_grade'] ?? $d['pay_grade'] ?? $staffData['pay_grade'] ?? ''));
        $hireDate = (string) ($row['hire_date'] ?? ($staff->hire_date ?? ''));
        $regularDate = (string) (($staff->regular_date ?? '') ?: ($staffData['regular_date'] ?? ''));

        $method = $this->resolveMethod($cat, $override, $grade);
        $originalMethod = $override === 'follow' ? $method : $this->resolveMethod($cat, 'follow', $grade);

        $quarter = $this->quarterBlock($q, $d);
        $installment = $this->installmentBlock($d);

        $notes = [];
        $isAnomaly = false;
        $ratio = null;
        $standard = null;
        $amount = null;
        $quarterAmount = 0.0;
        $monthlyAmount = 0.0;
        $halfAmount = 0.0;

        if ($method === 'quarter') {
            if ($qErr || $dErr) {
                $code = $qErr ?: $dErr;
                $notes[] = '季度：' . (self::PERF_ERROR_LABELS[$code] ?? $code);
                $isAnomaly = true;
            }
            if ($quarter === null) {
                if (!($qErr || $dErr)) $notes[] = '本期无季度兑现数据（可能未核算）';
                $isAnomaly = true;
            } else {
                $standard = $quarter['standard'];
                $qCoef = $quarter['coef'];
                $ratio = ($quarter['req'] !== null && $quarter['req'] > 0)
                    ? round($quarter['att'] / $quarter['req'], 4)
                    : $quarter['ratio'];
                if ($qCoef === null) {
                    $notes[] = '季度系数缺失，未发放';
                    $isAnomaly = true;
                } else {
                    $amount = $quarter['amount'];
                    if ($amount === null) {
                        $amount = ($ratio !== null)
                            ? round((float) $quarter['base'] * $ratio * $qCoef, 2)
                            : 0.0;
                    }
                }
                if (empty($quarter['months'])) {
                    $notes[] = '季内无有效月度数据，季度金额按 0 计';
                }
            }
            $quarterAmount = (float) ($amount ?? 0);
        } elseif ($method === 'monthly') {
            $standard = round($perfBaseMonth, 2);
            $exactRatio = $perfReq > 0 ? $perfAtt / $perfReq : null;
            $ratio = $exactRatio !== null ? round($exactRatio, 4) : null;
            if ($coef === null) {
                $notes[] = '绩效系数缺失';
                $isAnomaly = true;
            } elseif ($originalMethod !== 'monthly') {
                $amount = $exactRatio !== null ? round($standard * $exactRatio * $coef, 2) : null;
                $notes[] = '季度型人员按月度口径折算（口径视图）';
            } else {
                $amount = round((float) ($row['perf_pay'] ?? 0), 2);
                if ($amount == 0.0) $notes[] = '本月绩效为 0';
            }
            $monthlyAmount = (float) ($amount ?? 0);
        } else { // installment
            if ($installment['quarter_amount'] === null) {
                $notes[] = '该人员发放法非分期兑现，本期无分期应发数据';
                if (!empty($installment['error'])) {
                    $notes[] = '分期兑现：' . (self::PERF_ERROR_LABELS[$installment['error']] ?? $installment['error']);
                    $isAnomaly = true;
                }
            } else {
                $standard = $installment['quarter_base'];
                $ratio = ($quarter && $quarter['req'] !== null && $quarter['req'] > 0)
                    ? round($quarter['att'] / $quarter['req'], 4) : null;
                $quarterAmount = (float) $installment['quarter_amount'];
                $halfAmount = (float) ($installment['half_amount'] ?? 0);
                $amount = round($quarterAmount + $halfAmount, 2);
            }
        }

        // 缺档位：仅在季度/分期兑现口径下视为异常（月度人员允许无档位）
        if ($grade === '' && in_array($method, ['quarter', 'installment'], true) && !$notes) {
            $notes[] = '薪酬档位缺失';
            $isAnomaly = true;
        }

        if ($ratio !== null && $ratio > 1.0001) {
            $notes[] = '出勤系数 ' . number_format($ratio, 2) . ' > 1.00（出勤天数大于应出勤）';
        }
        if ($regularDate === '' && $staff && array_key_exists('planned_regular_date', $staffData)) {
            $regularDate = (string) ($staffData['planned_regular_date'] ?? '');
        }

        return [
            'cat' => $cat,
            'cat_label' => self::CATS[$cat],
            'method' => $method,
            'project' => (string) ($row['project'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'position' => (string) ($row['position'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'grade' => $grade,
            'hire_date' => $hireDate,
            'regular_date' => $regularDate,
            'fixed' => $fixed,
            'base' => $base,
            'coef' => $coef,
            'req_att' => $reqAtt,
            'perf_att' => $perfAtt,
            'perf_req' => $perfReq,
            'perf_base_month' => round($perfBaseMonth, 2),
            'ratio' => $ratio,
            'standard' => $standard,
            'amount' => $amount,
            'period_amount' => $amount,
            'quarter_amount' => $quarterAmount,
            'monthly_amount' => $monthlyAmount,
            'half_amount' => $halfAmount,
            'quarter' => $quarter,
            'installment' => $installment,
            'notes' => array_values(array_unique($notes)),
            'status' => $notes ? implode('；', array_values(array_unique($notes))) : '正常',
            'is_anomaly' => $isAnomaly,
        ];
    }

    /** 新 perf_quarter 优先，旧 perf_detail 回退；返回统一季度块 */
    private function quarterBlock(?array $q, ?array $d): ?array
    {
        if ($q && empty($q['error'])) {
            $months = [];
            $sumBase = 0.0; $sumAtt = 0.0; $sumReq = 0.0; $reqOk = true;
            foreach ((array) ($q['months'] ?? []) as $m) {
                $mYm = (string) ($m['ym'] ?? '');
                if ($mYm === '') continue;
                $mb = round((float) ($m['base'] ?? 0), 2);
                $ma = round((float) ($m['perf_att'] ?? 0), 2);
                $mr = array_key_exists('perf_req', $m) && is_numeric($m['perf_req']) ? (float) $m['perf_req'] : null;
                $months[$mYm] = ['base' => $mb, 'att' => $ma, 'req' => $mr];
                $sumBase += $mb; $sumAtt += $ma;
                if ($mr === null) $reqOk = false; else $sumReq += $mr;
            }
            $base = isset($q['base']) && is_numeric($q['base']) ? round((float) $q['base'], 2) : round($sumBase, 2);
            $att = isset($q['att']) && is_numeric($q['att']) ? round((float) $q['att'], 2) : round($sumAtt, 2);
            $req = isset($q['req']) && is_numeric($q['req']) ? round((float) $q['req'], 2) : ($reqOk ? round($sumReq, 2) : null);
            return [
                'period' => (string) ($q['period'] ?? ''),
                'months' => $months,
                'base' => $base,
                'att' => $att,
                'req' => $req,
                'ratio' => isset($q['ratio']) && is_numeric($q['ratio']) ? (float) $q['ratio'] : null,
                'coef' => array_key_exists('coef', $q) && is_numeric($q['coef']) ? (float) $q['coef'] : null,
                'amount' => array_key_exists('amount', $q) && is_numeric($q['amount']) ? (float) $q['amount'] : null,
                'standard' => $base,
            ];
        }

        if ($d && empty($d['error']) && is_array($d['months'] ?? null) && $d['months']) {
            $hasRatio = array_key_exists('ratio', $d);
            $months = [];
            $sumBase = 0.0; $sumProrated = 0.0; $sumAtt = 0.0; $sumReq = 0.0; $reqOk = true;
            foreach ($d['months'] as $m) {
                $mYm = (string) ($m['ym'] ?? '');
                if ($mYm === '') continue;
                $mb = round((float) ($m['base'] ?? 0), 2);
                $ma = round((float) ($m['perf_att'] ?? 0), 2);
                $mr = array_key_exists('perf_req', $m) && is_numeric($m['perf_req']) ? (float) $m['perf_req'] : null;
                $prorated = array_key_exists('amount', $m) && is_numeric($m['amount'])
                    ? (float) $m['amount']
                    : (($mr !== null && $mr > 0) ? round($mb * $ma / $mr, 2) : $mb);
                $months[$mYm] = ['base' => $mb, 'att' => $ma, 'req' => $mr];
                $sumBase += $mb; $sumProrated += $prorated; $sumAtt += $ma;
                if ($mr === null) $reqOk = false; else $sumReq += $mr;
            }
            $coef = array_key_exists('coef', $d) && is_numeric($d['coef']) ? (float) $d['coef'] : null;
            $ratioValue = $hasRatio ? (float) $d['ratio'] : 1.0;
            $amount = $hasRatio
                ? ($coef === null ? null : round($sumProrated * $coef * $ratioValue, 2))
                : round((float) ($d['perf_pay'] ?? 0), 2);
            return [
                'period' => (string) ($d['period'] ?? ''),
                'months' => $months,
                'base' => round($sumBase, 2),
                'att' => round($sumAtt, 2),
                'req' => $reqOk ? round($sumReq, 2) : null,
                'ratio' => $reqOk && $sumReq > 0 ? round($sumAtt / $sumReq, 4) : null,
                'coef' => $coef,
                'amount' => $amount,
                'standard' => $hasRatio ? round($sumBase, 2) : round($sumProrated, 2),
            ];
        }
        return null;
    }

    /** 分期兑现块：仅 quarterly 法（perf_detail.ratio 存在）可算 */
    private function installmentBlock(?array $d): array
    {
        $out = [
            'error' => null, 'quarter_ratio' => null, 'quarter_base' => null, 'quarter_amount' => null,
            'half_coef' => null, 'half_ratio' => null, 'half_base' => null, 'half_amount' => null,
        ];
        if (!$d) return $out;
        if (!empty($d['error'])) {
            $out['error'] = (string) $d['error'];
            return $out;
        }
        if (!array_key_exists('ratio', $d)) return $out; // quarter_grade 无比例，不适用
        $coef = array_key_exists('coef', $d) && is_numeric($d['coef']) ? (float) $d['coef'] : null;
        $base = 0.0;
        foreach ((array) ($d['months'] ?? []) as $m) {
            if (array_key_exists('amount', $m) && is_numeric($m['amount'])) {
                $base += (float) $m['amount'];
            } else {
                $mb = (float) ($m['base'] ?? 0);
                $ma = (float) ($m['perf_att'] ?? 0);
                $mr = (float) ($m['perf_req'] ?? 0);
                $base += $mr > 0 ? round($mb * $ma / $mr, 2) : $mb;
            }
        }
        $base = round($base, 2);
        $out['quarter_ratio'] = (float) $d['ratio'];
        $out['quarter_base'] = $base;
        $out['quarter_amount'] = $coef === null ? null : round($base * $coef * (float) $d['ratio'], 2);

        $h = is_array($d['half_year'] ?? null) ? $d['half_year'] : null;
        if ($h && empty($h['error']) && is_array($h['months'] ?? null)) {
            $hCoef = array_key_exists('coef', $h) && is_numeric($h['coef']) ? (float) $h['coef'] : null;
            $hRatio = (float) ($h['ratio'] ?? 0);
            $hBase = 0.0;
            foreach ($h['months'] as $m) {
                if (array_key_exists('amount', $m) && is_numeric($m['amount'])) {
                    $hBase += (float) $m['amount'];
                } else {
                    $mb = (float) ($m['base'] ?? 0);
                    $ma = (float) ($m['perf_att'] ?? 0);
                    $mr = (float) ($m['perf_req'] ?? 0);
                    $hBase += $mr > 0 ? round($mb * $ma / $mr, 2) : $mb;
                }
            }
            $out['half_coef'] = $hCoef;
            $out['half_ratio'] = $hRatio;
            $out['half_base'] = round($hBase, 2);
            $out['half_amount'] = $hCoef === null ? null : round($hBase * $hCoef * $hRatio, 2);
        }
        return $out;
    }

    // ---------------- 列与行值 ----------------

    private function columnsFor(string $method, array $quarterMonths, array $entries): array
    {
        $base = [
            ['label' => '序号', 'type' => 'text'],
            ['label' => '项目', 'type' => 'text'],
            ['label' => '部门', 'type' => 'text'],
            ['label' => '姓名', 'type' => 'text'],
            ['label' => '岗位', 'type' => 'text'],
            ['label' => '薪酬档位', 'type' => 'text'],
        ];
        $tail = [
            ['label' => '入职日期', 'type' => 'text'],
            ['label' => '转正日期', 'type' => 'text'],
            ['label' => '备注', 'type' => 'text'],
        ];
        $months = $quarterMonths;
        if (!$months) {
            foreach ($entries as $e) {
                if (!empty($e['quarter']['months'])) { $months = array_keys($e['quarter']['months']); break; }
            }
        }

        if ($method === 'monthly') {
            return array_merge($base, [
                ['label' => '固定月薪', 'type' => 'money'],
                ['label' => '基本工资', 'type' => 'money'],
                ['label' => '月系数', 'type' => 'ratio'],
                ['label' => '应出勤天数', 'type' => 'count'],
                ['label' => '绩效出勤天数', 'type' => 'count'],
                ['label' => '出勤比例', 'type' => 'ratio'],
                ['label' => '标准绩效工资（月）', 'type' => 'money'],
                ['label' => '应发绩效工资（月）', 'type' => 'money'],
            ], $tail);
        }

        $periodCols = [['label' => '固定月薪', 'type' => 'money']];
        foreach ($months as $m) {
            $label = ((int) substr($m, 5, 2)) . '月';
            $periodCols[] = ['label' => $label . '应发', 'type' => 'count'];
            $periodCols[] = ['label' => $label . '转正', 'type' => 'count'];
        }
        $periodCols[] = ['label' => '季度合计应发', 'type' => 'count'];
        $periodCols[] = ['label' => '季度合计转正', 'type' => 'count'];
        $periodCols[] = ['label' => '出勤系数', 'type' => 'ratio'];
        $periodCols[] = ['label' => '季度系数', 'type' => 'ratio'];

        if ($method === 'quarter') {
            $periodCols[] = ['label' => '标准绩效工资', 'type' => 'money'];
            $periodCols[] = ['label' => '应发绩效工资', 'type' => 'money'];
        } else {
            $periodCols[] = ['label' => '职级季度比例', 'type' => 'ratio'];
            $periodCols[] = ['label' => '季度计提基数', 'type' => 'money'];
            $periodCols[] = ['label' => '季度应发', 'type' => 'money'];
            $periodCols[] = ['label' => '半年度系数', 'type' => 'ratio'];
            $periodCols[] = ['label' => '职级半年度比例', 'type' => 'ratio'];
            $periodCols[] = ['label' => '半年度应发', 'type' => 'money'];
            $periodCols[] = ['label' => '本期应发', 'type' => 'money'];
        }
        return array_merge($base, $periodCols, $tail);
    }

    private function valuesFor(array $e, string $method, array $quarterMonths): array
    {
        $text = static fn ($v) => ($v === null || $v === '') ? '—' : (string) $v;
        $ratio = static fn ($v) => $v === null ? null : round((float) $v, 4);
        $money = static fn ($v) => $v === null ? null : round((float) $v, 2);

        $values = [
            $e['seq'] ?? 0, $text($e['project']), $text($e['department']), $text($e['name']),
            $text($e['position']), $text($e['grade']),
        ];
        $notes = $e['notes'] ? implode('；', $e['notes']) : '';

        if ($method === 'monthly') {
            $values[] = $money($e['fixed']);
            $values[] = $money($e['base']);
            $values[] = $ratio($e['coef']);
            $values[] = round((float) $e['req_att'], 2);
            $values[] = round((float) $e['perf_att'], 2);
            $values[] = $ratio($e['ratio']);
            $values[] = $money($e['standard']);
            $values[] = $money($e['amount']);
            $values[] = $text($e['hire_date']);
            $values[] = $text($e['regular_date']);
            $values[] = $notes;
            return $values;
        }

        $q = $e['quarter'];
        $months = $quarterMonths ?: ($q ? array_keys($q['months']) : []);
        $values[] = $money($e['fixed']);
        foreach ($months as $m) {
            $mrow = $q['months'][$m] ?? null;
            $values[] = $mrow && $mrow['req'] !== null ? round((float) $mrow['req'], 2) : '—';
            $values[] = $mrow ? round((float) $mrow['att'], 2) : '—';
        }
        $values[] = $q && $q['req'] !== null ? round((float) $q['req'], 2) : '—';
        $values[] = $q ? round((float) $q['att'], 2) : '—';
        $values[] = $ratio($e['ratio']);
        $values[] = $ratio($q['coef'] ?? $e['coef']);

        if ($method === 'quarter') {
            $values[] = $money($e['standard']);
            $values[] = $money($e['amount']);
        } else {
            $inst = $e['installment'] ?? [];
            $values[] = $ratio($inst['quarter_ratio'] ?? null);
            $values[] = $money($inst['quarter_base'] ?? null);
            $values[] = $money($inst['quarter_amount'] ?? null);
            $values[] = $ratio($inst['half_coef'] ?? null);
            $values[] = $ratio($inst['half_ratio'] ?? null);
            $values[] = $money($inst['half_amount'] ?? null);
            $values[] = $money($e['amount']);
        }
        $values[] = $text($e['hire_date'] ?? '');
        $values[] = $text($e['regular_date'] ?? '');
        $values[] = $notes;
        return $values;
    }

    private function sheetTitle(string $cat, string $method, array $methodsInCat, bool $follow): string
    {
        $base = self::CATS[$cat];
        if (($cat === 'manager' && $follow) || count($methodsInCat) > 1) {
            return $base . '-' . (self::METHOD_SUFFIX[$method] ?? $method);
        }
        return $base;
    }

    private function uniqueTitle(string $title, array &$used): string
    {
        $candidate = mb_substr($title, 0, 31);
        $i = 2;
        while (in_array($candidate, $used, true)) {
            $suffix = '(' . $i++ . ')';
            $candidate = mb_substr($title, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        $used[] = $candidate;
        return $candidate;
    }

    // ---------------- 提示 ----------------

    private function warnings(
        string $ym,
        ?string $quarterKey,
        array $quarterMonths,
        array $categories,
        array $anomalies,
        array $ratioOverOne,
        string $project,
        string $method
    ): array {
        $warnings = [];
        if ($quarterKey) {
            $first = $quarterMonths ? ((int) substr($quarterMonths[0], 5, 2)) : '';
            $last = $quarterMonths ? ((int) substr($quarterMonths[count($quarterMonths) - 1], 5, 2)) : '';
            $warnings[] = [
                'level' => 'info',
                'text' => "核算月份 {$ym}，季度口径 {$quarterKey}（{$first}~{$last} 月）；季度数据取季度末月工资行。",
            ];
        } else {
            $warnings[] = ['level' => 'info', 'text' => "核算月份 {$ym}，按月度口径导出。"];
        }
        $warnings[] = [
            'level' => 'info',
            'text' => '项目范围：' . ($project === '' ? '全部项目' : $project) . '；发放法：' . (self::METHOD_LABELS[$method] ?? $method) . '。',
        ];

        foreach ($categories as $cat) {
            if ($cat['count'] === 0) {
                $warnings[] = [
                    'level' => 'warn',
                    'text' => "{$cat['label']} {$ym} 无核算行，该 Sheet 将只有表头。",
                ];
            } elseif ($cat['archived_count'] < $cat['count']) {
                $warnings[] = [
                    'level' => 'warn',
                    'text' => "{$cat['label']} 共 {$cat['count']} 条，其中 " . ($cat['count'] - $cat['archived_count'])
                        . " 条尚未归档；项目账号需总部核定后才能导出。",
                ];
            } else {
                $warnings[] = [
                    'level' => 'ok',
                    'text' => "{$cat['label']} 共 {$cat['count']} 条，已归档。",
                ];
            }
        }

        if ($anomalies) {
            $names = array_slice(array_map(fn ($e) => $e['name'], $anomalies), 0, 5);
            $warnings[] = [
                'level' => 'danger',
                'text' => '绩效数据异常 ' . count($anomalies) . ' 人（' . implode('、', $names)
                    . (count($anomalies) > 5 ? ' 等' : '') . '），已在 Sheet 备注列标注，请补录后重算。',
            ];
        }
        if ($ratioOverOne) {
            $names = array_slice(array_map(fn ($r) => $r['name'], $ratioOverOne), 0, 5);
            $warnings[] = [
                'level' => 'warn',
                'text' => '出勤系数大于 1.00 共 ' . count($ratioOverOne) . ' 人（' . implode('、', $names)
                    . (count($ratioOverOne) > 5 ? ' 等' : '') . '）；按口径不封顶，已在备注列列出。',
            ];
        }
        return $warnings;
    }

    private function jsonValue(?string $value): array
    {
        if ($value === null || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
