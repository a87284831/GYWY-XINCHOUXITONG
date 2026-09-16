<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintenanceController extends ApiController
{
    public function uploadPdf(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        if (!$request->hasFile('file')) return response()->json(['ok' => false, 'error' => '未收到 PDF 文件'], 400);
        $request->validate(['file' => ['file', 'mimes:pdf', 'max:51200']]);
        $name = preg_replace('/[^A-Za-z0-9_.-]+/u', '_', $row->project_name . '_' . $row->type . '_' . $id . '.pdf');
        $path = $request->file('file')->storeAs('maintenance', $name, 'local');
        DB::table('maintenance_contracts')->where('legacy_id', $id)->update(['pdf_name' => basename($path), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'pdf_name' => basename($path)]);
    }

    public function contracts(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $query = DB::table('maintenance_contracts');
        if ($request->filled('type')) $query->where('type', $request->string('type'));
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        return response()->json(['ok' => true, 'contracts' => $query->orderByDesc('end_date')->get()->map(fn ($row) => $this->contractArray($row))]);
    }

    public function saveContract(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $project = trim((string) $request->input('project_name'));
        if ($project === '') return response()->json(['ok' => false, 'error' => '项目不能为空'], 400);
        if ($this->isProjectScope($account) && $project !== (string) $account->project_name) return response()->json(['ok' => false, 'error' => '无权操作其他项目合同'], 403);
        $type = $request->input('type') === 'elevator' ? 'elevator' : 'fire';
        $data = ['type' => $type, 'project_name' => $project, 'party' => trim((string) $request->input('party')),
            'amount' => (float) $request->input('amount', 0), 'sign_date' => $request->input('sign_date') ?: null,
            'start_date' => $request->input('start_date') ?: null, 'end_date' => $request->input('end_date') ?: null,
            'elevator_count' => (int) $request->input('elevator_count', 0), 'building_area_sqm' => (float) $request->input('building_area_sqm', 0),
            'price_per_sqm' => (float) $request->input('price_per_sqm', 0), 'price_per_unit' => (float) $request->input('price_per_unit', 0),
            'remark' => trim((string) $request->input('remark', '')), 'updated_at' => now()];
        if ($id) {
            $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
            if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
            DB::table('maintenance_contracts')->where('legacy_id', $id)->update($data);
        } else {
            $id = (int) (DB::table('maintenance_contracts')->max('legacy_id') ?? 0) + 1;
            $data['legacy_id'] = $id; $data['created_at'] = now();
            DB::table('maintenance_contracts')->insert($data);
        }
        $saved = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        return response()->json(['ok' => true, 'contract' => $this->contractArray($saved)]);
    }

    public function deleteContract(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'error' => '合同不存在'], 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权删除其他项目合同'], 403);
        if ($row->pdf_name) Storage::disk('local')->delete('maintenance/' . $row->pdf_name);
        DB::table('maintenance_contracts')->where('legacy_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function partners(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        return response()->json(['ok' => true, 'partners' => DB::table('maintenance_partners')->orderBy('name')->get()]);
    }

    public function savePartner(Request $request, ?int $id = null): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => '签约方名称不能为空'], 400);
        $data = ['name' => $name, 'type' => $request->input('type', 'both'), 'contact' => trim((string) $request->input('contact', '')),
            'phone' => trim((string) $request->input('phone', '')), 'remark' => trim((string) $request->input('remark', '')), 'updated_at' => now()];
        if ($id) DB::table('maintenance_partners')->where('legacy_id', $id)->update($data);
        else { $id = (int) (DB::table('maintenance_partners')->max('legacy_id') ?? 0) + 1; $data['legacy_id'] = $id; $data['created_at'] = now(); DB::table('maintenance_partners')->insert($data); }
        return response()->json(['ok' => true, 'partner' => DB::table('maintenance_partners')->where('legacy_id', $id)->first()]);
    }

    public function deletePartner(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        if ($this->isProjectScope($account)) return response()->json(['ok' => false, 'error' => '项目账号无权管理签约方'], 403);
        DB::table('maintenance_partners')->where('legacy_id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function pdf(Request $request, int $id)
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $row = DB::table('maintenance_contracts')->where('legacy_id', $id)->first();
        if (!$row || !$row->pdf_name) return response('file not found', 404);
        if ($this->isProjectScope($account) && $row->project_name !== $account->project_name) return response()->json(['ok' => false, 'error' => '无权访问其他项目合同'], 403);
        $path = 'maintenance/' . $row->pdf_name;
        if (!Storage::disk('local')->exists($path)) return response('file not found', 404);
        return Storage::disk('local')->download($path, $row->pdf_name, ['Content-Type' => 'application/pdf']);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        // 年度覆盖：合同周期与该年度有交集即计入
        $query = DB::table('maintenance_contracts')->whereYear('start_date', '<=', $year)->whereYear('end_date', '>=', $year);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        $today = now()->startOfDay();
        $soonLine = $today->copy()->addDays(30);

        $fire = ['normal' => 0, 'soon' => 0, 'expired' => 0];
        $elev = ['normal' => 0, 'soon' => 0, 'expired' => 0];
        $risk = [];
        // 合作方聚合：金额（分类型）、项目数、电梯台数
        $partnerAgg = [];   // party => ['name','fireAmount','elevAmount','projects'=>set,'projectCount','elevatorCount']
        // 项目聚合
        $projAgg = [];      // project => ['name','fireCount','fireAmount','fireTotalArea','elevCount','elevAmount','elevTotalUnits']

        foreach ($rows as $row) {
            $end = $row->end_date ? now()->parse($row->end_date) : null;
            $state = (!$end || $end->isAfter($soonLine)) ? 'normal' : ($end->lt($today) ? 'expired' : 'soon');
            $isFire = $row->type === 'fire';
            if ($isFire) $fire[$state]++; else $elev[$state]++;
            if ($state !== 'normal') $risk[] = $this->contractArray($row) + ['status' => $state];

            $party = trim((string) $row->party) !== '' ? trim((string) $row->party) : '未填签约方';
            $project = (string) $row->project_name;
            if (!isset($partnerAgg[$party])) $partnerAgg[$party] = ['name' => $party, 'fireAmount' => 0.0, 'elevAmount' => 0.0, 'projects' => [], 'elevatorCount' => 0];
            if ($isFire) $partnerAgg[$party]['fireAmount'] += (float) $row->amount;
            else { $partnerAgg[$party]['elevAmount'] += (float) $row->amount; $partnerAgg[$party]['elevatorCount'] += (int) $row->elevator_count; }
            $partnerAgg[$party]['projects'][$project] = true;

            if (!isset($projAgg[$project])) $projAgg[$project] = ['name' => $project, 'fireCount' => 0, 'fireAmount' => 0.0, 'fireTotalArea' => 0.0,
                'elevCount' => 0, 'elevAmount' => 0.0, 'elevTotalUnits' => 0];
            if ($isFire) { $projAgg[$project]['fireCount']++; $projAgg[$project]['fireAmount'] += (float) $row->amount; $projAgg[$project]['fireTotalArea'] += (float) $row->building_area_sqm; }
            else { $projAgg[$project]['elevCount']++; $projAgg[$project]['elevAmount'] += (float) $row->amount; $projAgg[$project]['elevTotalUnits'] += (int) $row->elevator_count; }
        }

        $partners = array_map(function ($a) {
            return ['name' => $a['name'], 'projectCount' => count($a['projects']),
                'elevatorCount' => $a['elevatorCount'], 'totalAmount' => round($a['fireAmount'] + $a['elevAmount'], 2)];
        }, array_values($partnerAgg));
        $firePartners = array_values(array_map(fn ($a) => ['name' => $a['name'], 'totalAmount' => round($a['fireAmount'], 2)],
            array_filter($partnerAgg, fn ($a) => $a['fireAmount'] > 0)));
        $elevPartners = array_values(array_map(fn ($a) => ['name' => $a['name'], 'totalAmount' => round($a['elevAmount'], 2)],
            array_filter($partnerAgg, fn ($a) => $a['elevAmount'] > 0)));
        $projectAmounts = array_map(function ($p) {
            $p['fireAmount'] = round($p['fireAmount'], 2); $p['elevAmount'] = round($p['elevAmount'], 2); return $p;
        }, array_values($projAgg));
        usort($projectAmounts, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $fireRows = $rows->where('type', 'fire'); $elevRows = $rows->where('type', 'elevator');
        $overview = [
            'projectCount' => $rows->pluck('project_name')->unique()->count(),
            'fireCount' => $fireRows->count(), 'elevatorCount' => $elevRows->count(),
            'totalAmount' => round((float) $rows->sum('amount'), 2),
            'fireAmount' => round((float) $fireRows->sum('amount'), 2),
            'elevAmount' => round((float) $elevRows->sum('amount'), 2),
            'fireProjectCount' => $fireRows->pluck('project_name')->unique()->count(),
            'elevProjectCount' => $elevRows->pluck('project_name')->unique()->count(),
        ];
        // 风险按到期紧迫度排序（已过期优先，其次到期日近的优先）
        usort($risk, fn ($a, $b) => strcmp((string) $a['end_date'], (string) $b['end_date']));

        return response()->json(['ok' => true, 'year' => $year, 'riskTotal' => count($risk),
            'fire' => $fire, 'elev' => $elev, 'riskList' => $risk,
            'partners' => $partners, 'firePartners' => $firePartners, 'elevPartners' => $elevPartners,
            'projectAmounts' => $projectAmounts, 'overview' => $overview]);
    }

    private function contractArray(object $row): array
    {
        $result = (array) $row; $result['id'] = $row->legacy_id; return $result;
    }

    /** 消防维保报表 */
    public function fireReport(Request $request): JsonResponse
    {
        return $this->maintReport($request, 'fire');
    }

    /** 电梯维保报表 */
    public function elevReport(Request $request): JsonResponse
    {
        return $this->maintReport($request, 'elevator');
    }

    /** 维保报表统一聚合（按类型拆分；含状态分布/合作方金额/项目金额/面积或台数分布/风险清单） */
    private function maintReport(Request $request, string $type): JsonResponse
    {
        $account = $this->requireAccount($request); if ($account instanceof JsonResponse) return $account;
        $year = (int) $request->input('year', now()->year);
        $query = DB::table('maintenance_contracts')->where('type', $type)
            ->whereYear('start_date', '<=', $year)->whereYear('end_date', '>=', $year);
        if ($this->isProjectScope($account)) $query->where('project_name', $account->project_name);
        $rows = $query->get();
        $today = now()->startOfDay();
        $soonLine = $today->copy()->addDays(30);

        $stateCount = ['normal' => 0, 'soon' => 0, 'expired' => 0];
        $risk = [];
        $partnerAmount = [];   // party => 金额
        $projectAmount = [];   // project => ['name','amount','count']
        $partnerArea = [];     // party => 面积合计（消防）
        $partnerUnits = [];    // party => 台数合计（电梯）
        foreach ($rows as $row) {
            $end = $row->end_date ? now()->parse($row->end_date) : null;
            $state = (!$end || $end->isAfter($soonLine)) ? 'normal' : ($end->lt($today) ? 'expired' : 'soon');
            $stateCount[$state]++;
            if ($state !== 'normal') $risk[] = $this->contractArray($row) + ['status' => $state, 'project' => (string) $row->project_name];
            $party = trim((string) $row->party) !== '' ? trim((string) $row->party) : '未填签约方';
            $project = (string) $row->project_name;
            $partnerAmount[$party] = round(($partnerAmount[$party] ?? 0) + (float) $row->amount, 2);
            $projectAmount[$project] = ['name' => $project,
                'amount' => round(($projectAmount[$project]['amount'] ?? 0) + (float) $row->amount, 2),
                'count' => ($projectAmount[$project]['count'] ?? 0) + 1];
            if ($type === 'fire') $partnerArea[$party] = round(($partnerArea[$party] ?? 0) + (float) $row->building_area_sqm, 2);
            else $partnerUnits[$party] = ($partnerUnits[$party] ?? 0) + (int) $row->elevator_count;
        }
        arsort($partnerAmount); arsort($partnerArea); arsort($partnerUnits);
        usort($risk, fn ($a, $b) => strcmp((string) $a['end_date'], (string) $b['end_date']));
        $toChart = fn (array $m) => array_map(fn ($v, $k) => ['label' => $k, 'value' => $v], array_values($m), array_keys($m));
        $projectList = array_values($projectAmount);
        usort($projectList, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return response()->json(['ok' => true, 'year' => $year, 'type' => $type,
            'state_dist' => $toChart($stateCount), 'partner_amount' => $toChart($partnerAmount),
            'project_amount' => $projectList, 'partner_scale' => $toChart($type === 'fire' ? $partnerArea : $partnerUnits),
            'risk_list' => $risk, 'risk_total' => count($risk),
            'total_amount' => round($rows->sum('amount'), 2), 'contract_count' => $rows->count(),
            'project_count' => $rows->pluck('project_name')->unique()->count()]);
    }
}
