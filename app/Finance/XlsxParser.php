<?php
/** 财务管理 - xlsx 解析器（纯XML，兼容 WPS DISPIMG 自定义公式，不依赖 PhpSpreadsheet） */

namespace App\Finance;

class XlsxParser
{
    private string $path;
    private array $shared = [];
    private array $sheets = []; // name => target path

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    private function load(): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new \RuntimeException('无法打开 xlsx 文件（不是有效的压缩包）');
        }
        try {
            // sharedStrings
            $sst = $zip->getFromName('xl/sharedStrings.xml');
            if ($sst !== false) {
                if (preg_match_all('/<si>(.*?)<\/si>/s', $sst, $m)) {
                    foreach ($m[1] as $si) {
                        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                            $this->shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        } else {
                            $this->shared[] = '';
                        }
                    }
                }
            }
            // workbook + rels
            $wb = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($wb === false || $rels === false) {
                throw new \RuntimeException('xlsx 缺少 workbook.xml / rels');
            }
            $nameMap = [];
            if (preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"/', $wb, $m)) {
                foreach ($m[2] as $i => $rid) {
                    $nameMap[$rid] = html_entity_decode($m[1][$i], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            $relMap = [];
            if (preg_match_all('/<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels, $m)) {
                foreach ($m[1] as $i => $rid) {
                    $relMap[$rid] = $m[2][$i];
                }
            }
            foreach ($nameMap as $rid => $name) {
                $target = $relMap[$rid] ?? '';
                if ($target === '') continue;
                $path = str_starts_with($target, '/') ? 'xl' . $target : 'xl/' . $target;
                $this->sheets[$name] = $path;
            }
        } finally {
            $zip->close();
        }
    }

    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /** 读一个 sheet 为 [rowNumber => [colLetter => value]] */
    public function readSheet(string $sheetName): array
    {
        if (!isset($this->sheets[$sheetName])) {
            throw new \RuntimeException('sheet 不存在: ' . $sheetName);
        }
        $zip = new \ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new \RuntimeException('无法重新打开 xlsx');
        }
        try {
            $xml = $zip->getFromName($this->sheets[$sheetName]);
            if ($xml === false) {
                throw new \RuntimeException('无法读取 sheet: ' . $sheetName);
            }
        } finally {
            $zip->close();
        }

        $rows = [];
        if (preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $m)) {
            foreach ($m[1] as $i => $rnum) {
                $cells = [];
                $rowXml = $m[2][$i];
                if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm)) {
                    foreach ($cm[1] as $j => $attrs) {
                        if (!preg_match('/r="([A-Z]+)\d+"/', $attrs, $am)) continue;
                        $col = $am[1];
                        $body = $cm[2][$j] ?? '';
                        $cells[$col] = $this->cellValue($attrs, $body);
                    }
                }
                $rows[(int) $rnum] = $cells;
            }
        }
        return $rows;
    }

    private function cellValue(string $attrs, string $body): mixed
    {
        preg_match('/t="(\w+)"/', $attrs, $tm);
        $t = $tm[1] ?? '';
        if (preg_match('/<v>(.*?)<\/v>/s', $body, $vm)) {
            $raw = $vm[1];
            if ($t === 's') {
                $idx = (int) $raw;
                return $this->shared[$idx] ?? '';
            }
            if ($t === 'b') return $raw === '1' || strtolower($raw) === 'true';
            if ($t === 'inlineStr') return '';
            if (is_numeric($raw)) return (float) $raw;
            return $raw;
        }
        if (preg_match('/<is>.*?<t[^>]*>(.*?)<\/t>/s', $body, $im)) {
            return html_entity_decode($im[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (preg_match('/<f>(.*?)<\/f>/s', $body, $fm)) {
            // 公式无缓存值（如 DISPIMG）→ 返回公式文本
            return trim($fm[1]);
        }
        return null;
    }
}
