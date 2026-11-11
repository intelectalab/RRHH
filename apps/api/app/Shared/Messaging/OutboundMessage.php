<?php

namespace App\Shared\Messaging;

/**
 * NOT-5 · Mensaje ya redactado que el adaptador entrega al proveedor. `reference` es la clave
 * de idempotencia de la entrega: un proveedor que la admite no envía dos veces el mismo mensaje.
 */
final class OutboundMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly string $body,
        public readonly string $reference,
        public readonly ?string $providerTemplateRef = null,
    ) {}
}
