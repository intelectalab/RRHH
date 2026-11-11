<?php

namespace App\Shared\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * SEG-11 · Cifrado de campos sensibles (AES-256-GCM) con anillo de claves fuera de la base.
 *
 * Formato guardado: «fe1:<id de clave>:<base64(nonce · cifrado · etiqueta)>». El id de clave permite
 * rotar: lo nuevo se cifra con la clave activa y lo anterior se sigue leyendo con su clave hasta
 * que `security:rotate-field-keys` lo vuelve a cifrar. Los valores anteriores a SEG-11 (formato de
 * Crypt de Laravel con APP_KEY) se leen y se migran con el mismo comando.
 */
final class FieldCipher
{
    private const PREFIX = 'fe1';

    private const CIPHER = 'aes-256-gcm';

    /** @var array<string, string>|null */
    private static ?array $ring = null;

    private static ?string $ringSource = null;

    public static function encrypt(string $plain): string
    {
        $kid = self::activeKeyId();
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key($kid), OPENSSL_RAW_DATA, $nonce, $tag, self::aad($kid));
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el campo.');
        }

        return self::PREFIX.':'.$kid.':'.base64_encode($nonce.$cipher.$tag);
    }

    public static function decrypt(string $stored): string
    {
        $parts = explode(':', $stored, 3);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            // Formato anterior a SEG-11: Crypt de Laravel con APP_KEY.
            return Crypt::decryptString($stored);
        }
        [, $kid, $payload] = $parts;
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new DecryptException('Valor cifrado inválido.');
        }
        $plain = openssl_decrypt(substr($raw, 12, -16), self::CIPHER, self::key($kid), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), self::aad($kid));
        if ($plain === false) {
            throw new DecryptException('No se pudo descifrar el campo (clave o valor alterado).');
        }

        return $plain;
    }

    /** ¿El valor está cifrado con una clave distinta de la activa (o en el formato anterior)? */
    public static function needsRotation(string $stored): bool
    {
        $parts = explode(':', $stored, 3);

        return count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] !== self::activeKeyId();
    }

    public static function keyIdOf(string $stored): ?string
    {
        $parts = explode(':', $stored, 3);

        return count($parts) === 3 && $parts[0] === self::PREFIX ? $parts[1] : null;
    }

    public static function activeKeyId(): string
    {
        $ring = self::ring();
        $active = config('security.field_active_key');
        if (is_string($active) && $active !== '') {
            if (! isset($ring[$active])) {
                throw new RuntimeException("La clave activa de cifrado «{$active}» no está en FIELD_ENCRYPTION_KEYS.");
            }

            return $active;
        }

        return (string) array_key_first($ring);
    }

    private static function key(string $kid): string
    {
        $ring = self::ring();
        if (! isset($ring[$kid])) {
            throw new DecryptException("La clave de cifrado «{$kid}» no está disponible.");
        }

        return $ring[$kid];
    }

    private static function aad(string $kid): string
    {
        return self::PREFIX.':'.$kid;
    }

    /**
     * @return array<string, string> id => 32 bytes
     */
    private static function ring(): array
    {
        $source = (string) config('security.field_keys', '');
        $appKey = (string) config('app.key');
        if (self::$ring !== null && self::$ringSource === $source.'|'.$appKey) {
            return self::$ring;
        }
        $ring = [];
        foreach (array_filter(array_map('trim', explode(',', $source))) as $entry) {
            [$kid, $value] = array_pad(explode(':', $entry, 2), 2, '');
            $bytes = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
            if ($kid === '' || ! is_string($bytes) || strlen($bytes) !== 32 || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $kid) !== 1) {
                throw new RuntimeException('FIELD_ENCRYPTION_KEYS mal formada: se espera «id:base64:<32 bytes>».');
            }
            $ring[$kid] = $bytes;
        }
        // La clave derivada de APP_KEY (id «app») queda siempre para leer lo cifrado sin anillo propio;
        // solo es la activa cuando no se configuró ninguna otra.
        if (! isset($ring['app'])) {
            $app = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;
            $ring['app'] = hash_hkdf('sha256', $app, 32, 'gestiahr.field-encryption.v1');
        }
        self::$ring = $ring;
        self::$ringSource = $source.'|'.$appKey;

        return $ring;
    }
}
