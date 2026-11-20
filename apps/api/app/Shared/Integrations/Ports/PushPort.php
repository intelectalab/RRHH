<?php

namespace App\Shared\Integrations\Ports;

/**
 * NOT-4 · Puerto de notificaciones push web (Web Push con claves VAPID). Ningún módulo lo llama
 * directamente: pasa por el motor de notificaciones, que nunca envía datos sensibles (RN-NOT-02).
 */
interface PushPort
{
    public const SENT = 'sent';

    /** La suscripción caducó o el usuario la quitó en el navegador: se da de baja. */
    public const GONE = 'gone';

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     * @return string self::SENT o self::GONE
     */
    public function send(array $subscription, array $payload): string;

    /** Clave pública VAPID con la que el navegador se suscribe (null: push no disponible en este entorno). */
    public function publicKey(): ?string;

    public function providerName(): string;

    public function isFake(): bool;
}
