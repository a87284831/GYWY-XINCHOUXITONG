<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayslipController extends ApiController
{
    public function query(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name')); $idLast6 = strtoupper(trim((string) $request->input('id_last6')));
        if ($name === '' || !preg_match('/^[A-Z0-9]{6}$/', $idLast6)) return response()->json(['ok' => false, 'error' => '请输入正确的姓名和身份证后六位'], 400);
        $config = $this->configData();
        if (($config['enabled'] ?? true) !== true) return response()->json(['ok' => false, 'error' => '工资条查询功能暂未开放'], 403);
        $start = (int) ($config['open_day_start'] ?? 5); $end = (int) ($config['open_day_end'] ?? 10); $day = now()->day;
        if ($start <= $end ? ($day < $start || $day > $end) : ($day < $start && $day > $end)) return response()->json(['ok' => false, 'error' => '当前不在工资条查询开放时间'], 403);
        $staff = DB::table('payroll_staff')->where('name', $name)->where('deleted', false)->get()->first(function ($row) use ($idLast6) {
            $data = $this->jsonValue($row->data) ?: []; return substr(strtoupper((string) ($data['id_card'] ?? '')), -6) === $idLast6;
        });
        if (!$staff) return response()->json(['ok' => false, 'error' => '员工信息验证失败'], 400);
        $month = now()->subMonth()->format('Y-m'); if (($config['query_month'] ?? 'prev') === 'current') $month = now()->format('Y-m');
        $result = DB::table('payroll_results')->where('year_month', $month)->where('staff_legacy_id', $staff->legacy_id)->first();
        if (!$result) return response()->json(['ok' => false, 'error' => $month . ' 工资数据尚未核算'], 404);
        $row = $this->jsonValue($result->row_data) ?: []; unset($row['bank_card']);
        return response()->json(['ok' => true, 'month' => $month, 'staff' => ['name' => $staff->name, 'project' => $staff->project_name, 'position' => $staff->position ?: ''],
            'payslip' => $row, 'config' => ['title' => $config['title'] ?? '工资查询系统', 'open_start' => $start, 'open_end' => $end]]);
    }

    private function configData(): array
    {
        $snapshot = DB::table('legacy_json_snapshots')->where('file_name', 'payslip_config.json')->first();
        return $snapshot ? ($this->jsonValue($snapshot->payload) ?: []) : [];
    }
}
