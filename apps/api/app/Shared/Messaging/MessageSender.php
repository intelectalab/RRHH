<?php

namespace App\Shared\Messaging;

/**
 * NOT-5 · Capa única de mensajería saliente por SMS y WhatsApp. Los módulos piden el envío con un
 * código de plantilla y sus variables; el módulo de notificaciones elige el proveedor
 * (message_channels), aplica la plantilla de la organización, registra la entrega y la envía por el
 * outbox (regla 8). Cambiar de proveedor no toca a quien envía.
 *
 * Debe llamarse dentro de una transacción: la entrega y el evento se guardan juntos.
 */
interface MessageSender
{
    /**
     * @param  string  $channel  sms o whatsapp (WhatsApp sin plantilla aprobada sale por SMS)
     * @param  string  $to  número en formato E.164 (+51…)
     * @param  array<string, string|int|null>  $variables  pueden incluir secretos (códigos): viajan cifradas
     * @param  string  $idempotencyKey  la misma clave devuelve la misma entrega sin enviar otra vez
     * @return string id de la entrega (notification_deliveries)
     */
    public function send(string $channel, string $to, string $templateCode, array $variables, string $locale, string $purpose, string $idempotencyKey): string;
}
