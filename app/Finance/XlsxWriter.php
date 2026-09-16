<?php

namespace App\Finance;

/**
 * 最小 XLSX 写入器（纯 ZipArchive，无第三方依赖）
 * 用法：$w = new XlsxWriter(); $w->addSheet('表1', [['a','b'],[1,2]]); $w->save($path);
 * 单元格数字以数值写入；字符串 inlineStr；第一行自动加粗灰底。
 */
class XlsxWriter
{
    private array $sheets = [];   // [ ['name'=>.., 'rows'=>[[]]] ]

    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = ['name' => $name, 'rows' => $rows];
    }

    public function save(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // 生成每张 sheet 的 xml
        $sheetFiles = [];
        foreach ($this->sheets as $i => $s) {
            $f = 'sheet' . ($i + 1) . '.xml';
            $zip->addFromString('xl/worksheets/' . $f, $this->sheetXml($s['rows']));
            $sheetFiles[] = $f;
        }

        // workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $wb .= '<sheet name="' . htmlspecialchars($s['name'], ENT_XML1) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $wb .= '</sheets></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // 根 rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // workbook rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheetFiles as $i => $f) {
            $wbRels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . $f . '"/>';
        }
        $wbRels .= '<Relationship Id="rId' . (count($sheetFiles) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // content types
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $ct .= '<Default Extension="xml" ContentType="application/xml"/>';
        $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($sheetFiles as $f) {
            $ct .= '<Override PartName="/xl/worksheets/' . $f . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        // styles：加粗表头样式 s=1，金额格式 s=2
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="宋体"/></font><font><b/><sz val="11"/><name val="宋体"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F4FA"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf/><xf fontId="1" fillId="1" applyFont="1" applyFill="1"/><xf applyNumberFormat="1" numFmtId="4"/></cellXfs>'
            . '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        return $zip->close();
    }

    /** 导出为下载字符串（不落盘） */
    public function bytes(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'finx') . '.xlsx';
        $this->save($tmp);
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $r = 1;
        foreach ($rows as $row) {
            $xml .= '<row r="' . $r . '">';
            $c = 1;
            foreach ($row as $v) {
                if ($v === '' || $v === null) { $c++; continue; }
                $ref = $this->col($c) . $r;
                if (is_int($v) || is_float($v)) {
                    $xml .= '<c r="' . $ref . '"' . ($r === 1 ? ' s="1"' : ' s="2"') . '><v>' . $v . '</v></c>';
                } else {
                    $s = htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $xml .= '<c r="' . $ref . '"' . ($r === 1 ? ' s="1"' : '') . ' t="inlineStr"><is><t xml:space="preserve">' . $s . '</t></is></c>';
                }
                $c++;
            }
            $xml .= '</row>';
            $r++;
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function col(int $n): string
    {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + ($n % 26)) . $s; $n = intdiv($n, 26); }
        return $s;
    }
}
