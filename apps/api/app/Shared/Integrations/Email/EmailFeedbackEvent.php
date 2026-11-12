<?php

namespace App\Shared\Integrations\Email;

/**
 * NOT-3 · Aviso del proveedor de correo sobre un mensaje enviado, independiente del proveedor.
 *  - bounce: rebote permanente (la dirección no existe o rechaza): se marca inválida.
 *  - soft_bounce: rebote temporal (buzón lleno): solo se anota en la entrega.
 *  - complaint: el destinatario lo marcó como no deseado: se trata como rebote.
 *  - delivered: el proveedor confirma la entrega.
 */
final class EmailFeedbackEvent
{
    public const KINDS = ['bounce', 'soft_bounce', 'complaint', 'delivered'];

    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $kind,
        public readonly ?string $reason = null,
    ) {}

    public function invalidatesAddress(): bool
    {
        return in_array($this->kind, ['bounce', 'complaint'], true);
    }
}
