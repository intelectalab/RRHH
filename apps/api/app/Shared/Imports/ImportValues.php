<?php

namespace App\Shared\Imports;

/**
 * IMP-1 · Interpretación de valores comunes que los importadores reutilizan (fechas en formatos
 * mixtos, sí/no, decimales con coma o punto). Devuelven null si el valor no se puede interpretar.
 */
final class ImportValues
{
    /**
     * Fecha laboral (sin hora) en ISO: acepta AAAA-MM-DD, DD/MM/AAAA, DD-MM-AAAA, DD.MM.AAAA y el
     * número de serie de Excel (días desde 1899-12-30).
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^\d{4,5}(\.0+)?$/', $value) === 1) {
            $base = new \DateTimeImmutable('1899-12-30');

            return $base->modify('+'.(int) $value.' days')->format('Y-m-d');
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    public static function bool(string $value): ?bool
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            in_array($v, ['1', 'true', 'si', 'sí', 'yes', 'sim', 'x', 'verdadero'], true) => true,
            in_array($v, ['0', 'false', 'no', 'não', 'nao', 'falso'], true) => false,
            default => null,
        };
    }

    /**
     * Decimal exacto como texto (nunca float): «1.234,50», «1,234.50» y «1234.5» → «1234.50» / «1234.5».
     */
    public static function decimal(string $value): ?string
    {
        $v = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($v === '') {
            return null;
        }
        $lastComma = strrpos($v, ',');
        $lastDot = strrpos($v, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);
        }

        return preg_match('/^-?\d+(\.\d+)?$/', $v) === 1 ? $v : null;
    }

    /** Normaliza una cabecera para compararla: minúsculas, sin tildes ni signos. */
    public static function normalizeHeader(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ã' => 'a', 'õ' => 'o', 'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ç' => 'c', 'à' => 'a']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $v), '_');
    }
}
