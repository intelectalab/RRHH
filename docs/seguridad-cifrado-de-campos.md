# Cifrado de campos sensibles y rotación de claves (SEG-11)

## Qué se cifra

Los campos marcados «SENSIBLE (cifrado en la aplicación)» en `kit/database/schema.sql` se guardan cifrados por la aplicación antes de llegar a la base, de modo que un volcado de PostgreSQL no los deja legibles:

| Tabla | Columna | Contenido |
|---|---|---|
| `user_identities` | `identifier_enc` | correo, teléfono o documento con que se ingresa |
| `user_mfa_factors` | `secret_enc` | secreto TOTP |
| `notification_deliveries` | `recipient_address_enc` | dirección de destino de cada envío |

La lista vive en `apps/api/config/security.php` (`encrypted_columns`), que es lo que recorre la rotación. Para buscar sin descifrar se usa el índice ciego (`BlindIndex`, HMAC), nunca el valor en claro.

Los campos sensibles de negocio (sueldo, salud, sanciones…) están además protegidos por permiso por campo (`config/sensitive_fields.php`) y cada lectura deja una fila en `data_access_logs`, consultable en `GET /sensitive-access-logs` con el permiso `audit.sensitive_access.view`.

## Formato y claves

`App\Shared\Security\FieldCipher` cifra con AES-256-GCM (autenticado) y guarda `fe1:<id de clave>:<base64(nonce · cifrado · etiqueta)>`. El id de clave va también como dato autenticado, por lo que un valor alterado o movido de clave no se descifra.

Las claves **no se guardan en la base ni en el repositorio**: llegan por variables de entorno desde el gestor de secretos.

```
FIELD_ENCRYPTION_KEYS=k2026a:base64:<32 bytes>,k2026b:base64:<32 bytes>
FIELD_ENCRYPTION_ACTIVE_KEY=k2026b
```

- Se genera una clave con `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`.
- Sin `FIELD_ENCRYPTION_KEYS` se usa una clave derivada de `APP_KEY` (id `app`). Sirve en desarrollo; en producción se configura un anillo propio para poder rotar sin tocar `APP_KEY`. La clave `app` se sigue pudiendo leer mientras exista `APP_KEY`, para migrar lo cifrado antes de configurar el anillo; sin `FIELD_ENCRYPTION_ACTIVE_KEY`, la activa es la primera del anillo.
- Los valores anteriores a SEG-11 (formato `Crypt` de Laravel con `APP_KEY`) se siguen leyendo y la rotación los pasa al formato nuevo.

## Rotación

1. Generar la clave nueva y **agregarla** al anillo sin quitar las anteriores: `FIELD_ENCRYPTION_KEYS=k2026a:…,k2026b:…`.
2. Marcarla activa: `FIELD_ENCRYPTION_ACTIVE_KEY=k2026b`. Desplegar. Desde ese momento todo lo nuevo se cifra con `k2026b` y lo anterior se sigue leyendo con `k2026a`.
3. Ver lo pendiente: `php artisan security:rotate-field-keys --dry-run`.
4. Volver a cifrar: `php artisan security:rotate-field-keys`. Es idempotente, recorre cada organización bajo su contexto (RLS) y solo reemplaza un valor si no cambió mientras tanto. Se puede repetir.
5. Repetir el paso 3 hasta que muestre `Pendientes: 0`. Recién entonces quitar `k2026a` del anillo y desplegar.

Si una clave se compromete, el procedimiento es el mismo, sin esperar: los pasos 1 a 5 seguidos.

La prueba `tests/Feature/Security/FieldEncryptionTest.php` cubre: el valor en la base no contiene el texto en claro, un valor alterado no se descifra, y la rotación de `k1` a `k2` (y desde el formato anterior) deja todo legible con la clave nueva sin la anterior.

## Excepciones que siguen con `APP_KEY`

- **Auditoría** (`audit_events`, valores `_enc`): es de solo inserción (regla 5), no se reescribe. Al rotar `APP_KEY` se usa `APP_PREVIOUS_KEYS` de Laravel para seguir leyendo los valores anteriores.
- **Cargas transitorias del outbox** (`token_enc`, `code_enc`): viven minutos; no se rotan.
- **Índice ciego** (`BlindIndex`): es un HMAC, no un cifrado. Cambiar su clave obliga a recalcular los `identifier_hash`, lo que se haría con una migración aparte.

`APP_KEY` no se pierde ni se reemplaza sin conservar la anterior en `APP_PREVIOUS_KEYS`.
