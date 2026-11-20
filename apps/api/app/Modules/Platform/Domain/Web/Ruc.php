<?php

namespace App\Modules\Platform\Domain\Web;

/**
 * WEB-5 · RN-WEB-06 · RUC peruano: 11 dígitos, prefijo de contribuyente válido y dígito verificador (módulo 11).
 * Solo valida la forma; si ya está en uso lo decide la plataforma.
 */
final class Ruc
{
    private const PREFIXES = ['10', '15', '16', '17', '20'];

    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $ruc): bool
    {
        if (preg_match('/^\d{11}$/', $ruc) !== 1 || ! in_array(substr($ruc, 0, 2), self::PREFIXES, true)) {
            return false;
        }
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $ruc[$i] * $w;
        }
        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $check === (int) $ruc[10];
    }
}
