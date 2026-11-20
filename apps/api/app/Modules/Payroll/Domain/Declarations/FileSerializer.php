<?php

namespace App\Modules\Payroll\Domain\Declarations;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * DEC-2 · DEC-3 · DEC-4 · DEC-10 · Escribe un archivo oficial a partir de su estructura (statutory_file_layouts,
 * datos versionados del paquete de país) y de filas ya armadas. Cada campo de la estructura dice de qué clave de la
 * fila sale (source), su tipo, su largo y si es obligatorio. Puro y determinista: la misma estructura y las mismas
 * filas producen los mismos bytes (RN-DEC-12). Lo que no cumple la estructura se devuelve como error por fila (no se
 * escribe en silencio), para que la validación previa lo liste por trabajador antes de presentar (RN-DEC-02).
 *
 * Tipos: text (se recorta solo si el campo lo permite), number (decimal con punto y los decimales del campo), date
 * (dd/mm/aaaa) y fixed_number (ancho fijo, ceros a la izquierda y decimales implícitos, sin punto).
 */
final class FileSerializer
{
    /**
     * @param  array{field_separator: string|null, trailing_separator: bool, line_ending: string, encoding: string, fields: list<array<string, mixed>>}  $layout
     * @param  list<array<string, mixed>>  $rows  cada fila: claves source => valor, y opcionalmente _ref (a quién pertenece, para el error)
     * @return array{content: string, lines: int, errors: list<array{row: int, ref: string|null, field: string, rule: string}>}
     */
    public static function serialize(array $layout, array $rows): array
    {
        $sep = $layout['field_separator'];
        $eol = $layout['line_ending'] === 'LF' ? "\n" : "\r\n";
        $lines = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $ref = isset($row['_ref']) ? (string) $row['_ref'] : null;
            $cells = [];
            foreach ($layout['fields'] as $f) {
                $source = (string) ($f['source'] ?? $f['name']);
                [$value, $rule] = self::cell($f, $row[$source] ?? null, $sep === null);
                if ($rule !== null) {
                    $errors[] = ['row' => $i + 1, 'ref' => $ref, 'field' => (string) $f['name'], 'rule' => $rule];
                }
                $cells[] = $value;
            }
            $lines[] = $sep === null ? implode('', $cells) : implode($sep, $cells).($layout['trailing_separator'] ? $sep : '');
        }
        $content = $lines === [] ? '' : implode($eol, $lines).$eol;
        $encoding = strtoupper($layout['encoding']);
        if ($encoding !== 'UTF-8' && $content !== '') {
            $converted = @iconv('UTF-8', $encoding.'//TRANSLIT', $content);
            $content = $converted === false ? $content : $converted;
        }

        return ['content' => $content, 'lines' => count($lines), 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{0: string, 1: string|null}
     */
    private static function cell(array $f, mixed $raw, bool $fixed): array
    {
        $type = (string) ($f['type'] ?? 'text');
        $width = isset($f['width']) ? (int) $f['width'] : null;
        $max = isset($f['max_len']) ? (int) $f['max_len'] : $width;
        $decimals = isset($f['decimals']) ? (int) $f['decimals'] : 0;
        $value = $raw === null ? '' : (is_bool($raw) ? ($raw ? '1' : '0') : trim((string) $raw));
        $rule = null;
        if ($value === '' && ($f['required'] ?? true) === true) {
            $rule = 'required';
        }
        if ($value !== '' && isset($f['values']) && is_array($f['values']) && ! in_array($value, array_map('strval', $f['values']), true)) {
            $rule = 'in';
        }
        if ($value !== '' && in_array($type, ['number', 'fixed_number'], true)) {
            if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
                return [$fixed && $width !== null ? str_repeat('0', $width) : '', 'numeric'];
            }
            $n = BigDecimal::of($value)->toScale($decimals, RoundingMode::HALF_UP);
            if ($type === 'fixed_number') {
                if ($n->isNegative()) {
                    $rule ??= 'min';
                    $n = BigDecimal::zero()->toScale($decimals);
                }
                $digits = (string) $n->getUnscaledValue();
                $value = $width !== null ? str_pad($digits, $width, '0', STR_PAD_LEFT) : $digits;
            } else {
                $value = (string) $n;
            }
        } elseif ($value !== '' && $type === 'date') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
                return ['', 'date'];
            }
            $value = $m[3].'/'.$m[2].'/'.$m[1];
        } elseif ($type === 'fixed_number') {
            $value = $width !== null ? str_repeat('0', $width) : '';
        }
        if ($max !== null && mb_strlen($value) > $max) {
            if (($f['truncate'] ?? false) === true) {
                $value = mb_substr($value, 0, $max);
            } else {
                $rule ??= 'max_len';
                $value = mb_substr($value, 0, $max);
            }
        }
        if ($fixed && $width !== null && $type !== 'fixed_number') {
            $value = str_pad($value, $width + (strlen($value) - mb_strlen($value)), ' ', STR_PAD_RIGHT);
        }

        return [$value, $rule];
    }

    /** Nombre del archivo según el patrón de la estructura ({ruc}, {yyyy}, {mm}, {ext}). */
    public static function fileName(string $pattern, string $ruc, string $periodCode, string $extension): string
    {
        $year = substr($periodCode, 0, 4);
        $month = substr($periodCode, -2);

        return strtr($pattern, ['{ruc}' => $ruc, '{yyyy}' => $year, '{mm}' => $month, '{ext}' => $extension]);
    }
}
