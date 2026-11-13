<?php

namespace App\Shared\Documents;

/**
 * PDF de texto sin librerías: título y párrafos en Helvetica (WinAnsi, con tildes y eñes), A4, varias
 * páginas y numeración. Sirve para los documentos que el sistema genera desde una plantilla de texto
 * (contratos, adendas) mientras el servicio documental (M-DOC) no tenga su propio motor de plantillas.
 */
final class SimplePdf
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const MARGIN = 56;

    private const BODY_SIZE = 10.5;

    private const LINE_HEIGHT = 15;

    /** Caracteres por línea del cuerpo (ancho medio de Helvetica en el tamaño del cuerpo). */
    private const WRAP = 92;

    /**
     * @param  string  $title  título en negrita de la primera página
     * @param  string  $body  texto con saltos de línea; una línea en blanco separa párrafos
     * @param  string  $footer  texto al pie de cada página (se le agrega «· página n de m»)
     */
    public static function render(string $title, string $body, string $footer = ''): string
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $body)) as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';

                continue;
            }
            foreach (explode("\n", wordwrap($paragraph, self::WRAP, "\n", true)) as $line) {
                $lines[] = $line;
            }
        }

        $perPage = (int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN - 40) / self::LINE_HEIGHT);
        $pages = array_chunk($lines, max(1, $perPage));

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        $total = count($pages);
        foreach ($pages as $i => $pageLines) {
            $y = self::PAGE_HEIGHT - self::MARGIN;
            $stream = "BT\n";
            if ($i === 0) {
                $stream .= '/F2 14 Tf 1 0 0 1 '.self::MARGIN.' '.$y.' Tm ('.self::text($title).") Tj\n";
                $y -= 30;
            }
            $stream .= '/F1 '.self::BODY_SIZE." Tf\n";
            foreach ($pageLines as $line) {
                $stream .= '1 0 0 1 '.self::MARGIN.' '.$y.' Tm ('.self::text($line).") Tj\n";
                $y -= self::LINE_HEIGHT;
            }
            $foot = trim($footer.' · '.($i + 1).' / '.$total, ' ·');
            $stream .= '/F1 8 Tf 1 0 0 1 '.self::MARGIN.' '.(self::MARGIN / 2).' Tm ('.self::text($foot).") Tj\nET";
            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $kids[] = $pageId.' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 65535 f \n";
        }

        return $pdf.'trailer << /Size '.$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** Texto UTF-8 a cadena literal de PDF en WinAnsi (lo que no existe en WinAnsi se aproxima). */
    private static function text(string $s): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($encoded === false) {
            $encoded = (string) preg_replace('/[^\x20-\x7E]/', '?', $s);
        }

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
