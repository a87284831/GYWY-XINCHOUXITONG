# 将线下工资工作簿用 Excel 计算后导出为值版 JSON
# 用法: powershell -File export_wb_values.ps1 <xlsx路径> <输出json路径>
param([string]$Xlsx, [string]$OutJson)
$ErrorActionPreference = 'Stop'
$excel = New-Object -ComObject Excel.Application
$excel.DisplayAlerts = $false
$excel.AskToUpdateLinks = $false
try {
    $wb = $excel.Workbooks.Open($Xlsx, 0, $true)   # UpdateLinks=0 只读打开，外部引用取缓存值
    $excel.CalculateFullRebuild()
    $out = [ordered]@{}
    foreach ($ws in $wb.Worksheets) {
        if ($ws.Name -like '*汇总*') { continue }
        $vals = $ws.UsedRange.Value2
        $rows = $vals.GetLength(0); $cols = $vals.GetLength(1)
        $arr = New-Object 'object[][]' $rows, $cols
        for ($r = 0; $r -lt $rows; $r++) {
            $rowArr = New-Object object[] $cols
            for ($c = 0; $c -lt $cols; $c++) {
                $v = $vals[$r + 1, $c + 1]
                if ($v -is [int] -and $v -lt -2000000000) { $rowArr[$c] = '#ERR' } else { $rowArr[$c] = $v }
            }
            $arr[$r] = $rowArr
        }
        $out[$ws.Name] = $arr
        Write-Output "  sheet [$($ws.Name)] ${rows}x${cols}"
    }
    $json = $out | ConvertTo-Json -Depth 5 -Compress
    [IO.File]::WriteAllText($OutJson, $json, [Text.Encoding]::UTF8)
    Write-Output "DONE -> $OutJson"
} finally {
    if ($wb) { $wb.Close($false) }
    $excel.Quit()
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) | Out-Null
}
