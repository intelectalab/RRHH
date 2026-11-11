<?php

namespace App\Shared\Imports;

use ZipArchive;

/**
 * IMP-1 · Lee un archivo CSV o XLSX y devuelve cabeceras y filas como texto.
 *
 * - CSV: detecta la codificación (UTF-8 con o sin BOM; si no es UTF-8 válido se toma como
 *   Windows-1252, que es lo que exporta Excel en español) y el separador (coma, punto y coma,
 *   tabulación o barra).
 * - XLSX: primera hoja del libro, con textos compartidos, textos en línea, números y booleanos.
 *   Se lee con ZipArchive y XMLReader, sin librerías externas.
 * Las filas vacías se omiten; el número de fila conserva la posición en el archivo.
 */
final class SpreadsheetReader
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    /**
     * @return array{format: string, delimiter: string|null, headers: array<int, string>, rows: array<int, array<int, string>>}
     *                                                                                                                          rows: número de fila del archivo => celdas
     *
     * @throws SpreadsheetException
     */
    public function read(string $contents, string $fileName, int $maxRows): array
    {
        $format = self::formatOf($contents, $fileName);
        $table = $format === self::FORMAT_XLSX ? $this->xlsx($contents) : $this->csv($contents);
        $delimiter = $table['delimiter'];
        $rows = $table['rows'];

        $headerLine = array_key_first($rows);
        if ($headerLine === null) {
            throw new SpreadsheetException('empty');
        }
        $headers = array_map(fn (string $h) => trim($h), $rows[$headerLine]);
        unset($rows[$headerLine]);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        if ($headers === [] || count(array_filter($headers, fn ($h) => $h !== '')) === 0) {
            throw new SpreadsheetException('no_headers');
        }
        if (count(array_unique(array_filter($headers, fn ($h) => $h !== ''))) !== count(array_filter($headers, fn ($h) => $h !== ''))) {
            throw new SpreadsheetException('duplicate_headers');
        }
        if (count($rows) > $maxRows) {
            throw new SpreadsheetException('too_many_rows', ['max' => $maxRows]);
        }
        if ($rows === []) {
            throw new SpreadsheetException('no_rows');
        }

        $width = count($headers);
        $out = [];
        foreach ($rows as $line => $cells) {
            $cells = array_slice(array_pad($cells, $width, ''), 0, $width);
            $out[$line] = array_map(fn (string $c) => trim($c), $cells);
        }

        return ['format' => $format, 'delimiter' => $delimiter, 'headers' => $headers, 'rows' => $out];
    }

    public static function formatOf(string $contents, string $fileName): string
    {
        $isZip = str_starts_with($contents, "PK\x03\x04");
        if ($isZip || str_ends_with(strtolower($fileName), '.xlsx')) {
            return self::FORMAT_XLSX;
        }

        return self::FORMAT_CSV;
    }

    /**
     * @return array{delimiter: string, rows: array<int, array<int, string>>}
     */
    private function csv(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        } elseif (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $contents);
            if ($converted === false) {
                throw new SpreadsheetException('encoding');
            }
            $contents = $converted;
        }
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $delimiter = $this->delimiter($contents);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new SpreadsheetException('unreadable');
        }
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(fn ($c) => (string) $c, $cells);
            if (count(array_filter($cells, fn (string $c) => trim($c) !== '')) === 0) {
                continue;
            }
            $rows[$line] = $cells;
        }
        fclose($stream);

        return ['delimiter' => $delimiter, 'rows' => $rows];
    }

    /** El separador que más se repite, igual, en las primeras líneas (fuera de comillas). */
    private function delimiter(string $contents): string
    {
        $sample = array_slice(array_filter(explode("\n", substr($contents, 0, 65536)), fn ($l) => trim($l) !== ''), 0, 10);
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(function (string $line) use ($candidate): int {
                return substr_count((string) preg_replace('/"[^"]*"/', '', $line), $candidate);
            }, $sample);
            if ($counts === [] || min($counts) === 0) {
                continue;
            }
            // Preferimos el que aparece igual en todas las líneas y, entre esos, el más frecuente.
            $score = (count(array_unique($counts)) === 1 ? 1000 : 0) + min($counts);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return array{delimiter: null, rows: array<int, array<int, string>>}
     */
    private function xlsx(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        if ($path === false) {
            throw new SpreadsheetException('unreadable');
        }
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        try {
            if ($zip->open($path) !== true) {
                throw new SpreadsheetException('unreadable');
            }
            $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $sheet = $zip->getFromName($this->firstSheetPath($zip));
            if ($sheet === false) {
                throw new SpreadsheetException('unreadable');
            }

            return ['delimiter' => null, 'rows' => $this->sheetRows($sheet, $shared)];
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false
            && preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m) === 1
            && preg_match('/<Relationship\b[^>]*Id="'.preg_quote($m[1], '/').'"[^>]*Target="([^"]+)"/', $rels, $t) === 1) {
            $target = ltrim($t[1], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);
        $strings = [];
        $current = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  array<int, string>  $shared
     * @return array<int, array<int, string>>
     */
    private function sheetRows(string $xml, array $shared): array
    {
        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET);
        $rows = [];
        $rowNumber = 0;
        $cells = [];
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                $rowNumber = (int) ($reader->getAttribute('r') ?? ($rowNumber + 1));
                $cells = [];
                if ($reader->isEmptyElement) {
                    continue;
                }
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                $ref = (string) $reader->getAttribute('r');
                $type = (string) $reader->getAttribute('t');
                $column = $ref !== '' ? self::columnIndex($ref) : count($cells);
                $value = '';
                if (! $reader->isEmptyElement) {
                    $cell = $reader->readOuterXml();
                    if ($type === 'inlineStr') {
                        preg_match_all('/<(?:\w+:)?t\b[^>]*>(.*?)<\/(?:\w+:)?t>/s', $cell, $m);
                        $value = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } elseif (preg_match('/<(?:\w+:)?v>(.*?)<\/(?:\w+:)?v>/s', $cell, $m) === 1) {
                        $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $value = match ($type) {
                            's' => $shared[(int) $raw] ?? '',
                            'b' => $raw === '1' ? 'true' : 'false',
                            default => $raw,
                        };
                    }
                }
                $cells[$column] = $value;
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                $columns = array_keys($cells);
                if ($columns !== [] && count(array_filter($cells, fn (string $c) => trim($c) !== '')) > 0) {
                    $max = max($columns);
                    $row = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $row[] = $cells[$i] ?? '';
                    }
                    $rows[$rowNumber] = $row;
                }
            }
        }
        $reader->close();

        return $rows;
    }

    /** «C7» → 2 (columna base cero). */
    private static function columnIndex(string $ref): int
    {
        $letters = (string) preg_replace('/\d+/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return max(0, $index - 1);
    }
}
