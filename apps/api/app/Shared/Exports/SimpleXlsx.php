<?php

namespace App\Shared\Exports;

/**
 * UI-11 · Hoja de cálculo .xlsx mínima sin librerías: una hoja, encabezado en negrita y celdas de texto
 * (los valores ya vienen formateados por cada fuente). Usa ZipArchive, que la imagen de la API ya trae.
 */
final class SimpleXlsx
{
    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     */
    public static function render(array $header, array $rows, ?string $note = null, string $sheet = 'Datos'): string
    {
        $lines = [];
        $r = 1;
        if ($note !== null) {
            $lines[] = self::row($r++, [$note], false);
        }
        $lines[] = self::row($r++, $header, true);
        foreach ($rows as $row) {
            $lines[] = self::row($r++, $row, false);
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $lines).'</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::esc(mb_substr($sheet, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border/></borders>'
                .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('No se pudo generar el archivo.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo generar el archivo.');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $data = (string) file_get_contents($path);
        @unlink($path);

        return $data;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private static function row(int $r, array $cells, bool $bold): string
    {
        $out = '<row r="'.$r.'">';
        foreach (array_values($cells) as $i => $value) {
            $out .= '<c r="'.self::col($i).$r.'" t="inlineStr"'.($bold ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::esc($value).'</t></is></c>';
        }

        return $out.'</row>';
    }

    private static function col(int $i): string
    {
        $name = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    private static function esc(string $v): string
    {
        // Quita caracteres de control que XML no admite.
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
