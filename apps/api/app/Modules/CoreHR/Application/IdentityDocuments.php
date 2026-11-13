<?php

namespace App\Modules\CoreHR\Application;

use App\Shared\Security\BlindIndex;
use Illuminate\Validation\ValidationException;

/**
 * COL-1 · Documento de identidad: normaliza y valida el número según su tipo con el formato del paquete
 * de país (packages/country-xx/data/documentos_identidad.php) y calcula la huella para buscarlo sin
 * guardarlo en claro. La huella usa la misma forma que el ingreso con documento (SEG-2), así una
 * persona y su usuario se reconocen por el mismo documento.
 */
final class IdentityDocuments
{
    public const TYPES = ['dni', 'ce', 'passport', 'ptp', 'cpp', 'other'];

    /** @var array<string, array<string, array{pattern: string, example: string, to_validate: bool}>> */
    private array $formats = [];

    public static function normalize(string $number): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($number)));
    }

    /**
     * Devuelve el número normalizado o responde 422 en `$field` si no tiene el formato de su tipo.
     */
    public function assertValid(string $type, string $number, string $country = 'PE', string $field = 'document_number'): string
    {
        $normalized = self::normalize($number);
        $format = $this->formats($country)[$type] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['document_type' => [(string) trans('people.document_type_invalid')]]);
        }
        if ($normalized === '' || ($format !== null && preg_match($format['pattern'], $normalized) !== 1)) {
            throw ValidationException::withMessages([$field => [(string) trans('people.document_number_invalid', [
                'type' => (string) trans('people.document_types.'.$type),
                'example' => $format['example'] ?? '',
            ])]]);
        }

        return $normalized;
    }

    public static function hash(string $type, string $normalized): string
    {
        return BlindIndex::for('national_id', $type.':'.$normalized);
    }

    /** Muestra solo los últimos dígitos: ••••5678. */
    public static function mask(string $value, int $visible = 4): string
    {
        $len = strlen($value);

        return $len <= $visible ? $value : str_repeat('•', $len - $visible).substr($value, -$visible);
    }

    /**
     * @return array<string, array{pattern: string, example: string, to_validate: bool}>
     */
    private function formats(string $country): array
    {
        $country = strtolower($country);
        if (! isset($this->formats[$country])) {
            $file = base_path('packages/country-'.$country.'/data/documentos_identidad.php');
            /** @var array<string, array{pattern: string, example: string, to_validate: bool}> $data */
            $data = is_file($file) ? require $file : [];
            $this->formats[$country] = $data;
        }

        return $this->formats[$country];
    }
}
