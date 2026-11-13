<?php

namespace App\Modules\CoreHR\Application;

use Illuminate\Validation\ValidationException;

/**
 * COL-7 · COL-8 · Formato de las cuentas de pago y del código de afiliado según el paquete de país
 * (packages/country-xx/data/cuentas_bancarias.php). Lo que el paquete marca «por validar» se valida con el
 * formato general; los dígitos de control del CCI solo bloquean si el paquete lo exige.
 */
final class PaymentFormats
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $formats = [];

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($value)));
    }

    /** Muestra solo los últimos cuatro caracteres: ••••6789. */
    public static function mask(string $value): string
    {
        return IdentityDocuments::mask($value, 4);
    }

    public function accountNumber(string $value, string $country, string $field = 'account_number'): string
    {
        return $this->assertPattern('account_number', $value, $country, $field);
    }

    public function affiliateCode(string $value, string $country, string $field = 'affiliate_code'): string
    {
        return $this->assertPattern('affiliate_code', $value, $country, $field);
    }

    /**
     * Valida el código interbancario. Devuelve el valor normalizado y los avisos (dígitos de control que no
     * cuadran cuando el paquete todavía no exige rechazarlos).
     *
     * @return array{value: string, warnings: list<string>}
     */
    public function interbankCode(string $value, string $country, string $field = 'interbank_code'): array
    {
        $normalized = $this->assertPattern('interbank_code', $value, $country, $field);
        $format = $this->formats($country)['interbank_code'] ?? [];
        $algorithm = $format['check_digits'] ?? null;
        if ($algorithm !== 'pe_cci' || self::peCciCheckDigitsMatch($normalized)) {
            return ['value' => $normalized, 'warnings' => []];
        }
        if (($format['enforce_check_digits'] ?? false) === true) {
            throw ValidationException::withMessages([$field => [(string) trans('people.payments.interbank_check_digits')]]);
        }

        return ['value' => $normalized, 'warnings' => ['interbank_check_digits']];
    }

    /**
     * Dígitos de control del CCI peruano: uno sobre entidad y oficina, otro sobre el número de cuenta. Cada
     * dígito se multiplica alternando 1 y 2, se suman los dígitos de los productos y el control es lo que
     * falta para la decena siguiente.
     */
    public static function peCciCheckDigitsMatch(string $cci): bool
    {
        if (preg_match('/^\d{20}$/', $cci) !== 1) {
            return false;
        }

        return self::luhnLike(substr($cci, 0, 6)) === (int) $cci[18] && self::luhnLike(substr($cci, 6, 12)) === (int) $cci[19];
    }

    private static function luhnLike(string $digits): int
    {
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $p = (int) $d * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($p, 10) + $p % 10;
        }

        return (10 - $sum % 10) % 10;
    }

    private function assertPattern(string $kind, string $value, string $country, string $field): string
    {
        $normalized = self::normalize($value);
        $format = $this->formats($country)[$kind] ?? null;
        $pattern = is_array($format) && is_string($format['pattern'] ?? null) ? $format['pattern'] : '/^[0-9A-Z]{4,34}$/';
        if ($normalized === '' || preg_match($pattern, $normalized) !== 1) {
            throw ValidationException::withMessages([$field => [(string) trans('people.payments.invalid_'.$kind, [
                'example' => is_array($format) ? (string) ($format['example'] ?? '') : '',
            ])]]);
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formats(string $country): array
    {
        $country = strtolower($country);
        if (! isset($this->formats[$country])) {
            $file = base_path('packages/country-'.$country.'/data/cuentas_bancarias.php');
            /** @var array<string, array<string, mixed>> $data */
            $data = is_file($file) ? require $file : [];
            $this->formats[$country] = $data;
        }

        return $this->formats[$country];
    }
}
