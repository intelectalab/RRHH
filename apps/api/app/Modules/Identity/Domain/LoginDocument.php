<?php

namespace App\Modules\Identity\Domain;

/**
 * SEG-2 · RN-SEG-09: documento de identidad como identificador de ingreso (C-09).
 * Se guarda como «tipo:número» normalizado (p. ej. DNI12345678) en user_identities.kind = national_id.
 */
final class LoginDocument
{
    /** Tipos admitidos para ingresar (mismos códigos que person_documents.doc_type). */
    public const TYPES = ['dni', 'ce', 'passport', 'ptp', 'cpp', 'other'];

    public static function identifier(string $type, string $number): string
    {
        return strtolower($type).':'.$number;
    }

    /**
     * Separa el identificador normalizado («DNI12345678») en tipo y número.
     *
     * @return array{type: string, number: string}|null
     */
    public static function parse(string $normalized): ?array
    {
        $types = self::TYPES;
        usort($types, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($types as $type) {
            $prefix = strtoupper($type);
            if (str_starts_with($normalized, $prefix) && strlen($normalized) > strlen($prefix)) {
                return ['type' => $type, 'number' => substr($normalized, strlen($prefix))];
            }
        }

        return null;
    }

    /** Muestra solo los últimos dígitos: ****5678. */
    public static function mask(string $value, int $visible = 4): string
    {
        $len = strlen($value);

        return $len <= $visible ? $value : str_repeat('•', $len - $visible).substr($value, -$visible);
    }
}
