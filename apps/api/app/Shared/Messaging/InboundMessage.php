<?php

namespace App\Shared\Messaging;

/**
 * NOT-5 · Mensaje entrante normalizado (respuesta de un colaborador por SMS o WhatsApp).
 * Lo consume más adelante el autoservicio por WhatsApp (M-WHA), que expone el webhook.
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $from,
        public readonly string $body,
        public readonly string $providerMessageId,
    ) {}
}
