<?php

namespace App\Shared\Integrations\Ports;

use App\Shared\Messaging\InboundMessage;
use App\Shared\Messaging\OutboundMessage;
use App\Shared\Messaging\TemplateDecision;

/**
 * NOT-5 · Puerto de mensajería SMS/WhatsApp. Cada proveedor es un adaptador; ningún módulo lo
 * llama directamente: se pasa por App\Shared\Messaging\MessageSender.
 */
interface MessagingPort
{
    /**
     * @return string id del mensaje en el proveedor
     */
    public function send(OutboundMessage $message): string;

    /**
     * Envía una plantilla de WhatsApp a aprobación del proveedor.
     */
    public function submitTemplate(string $code, string $locale, string $body): TemplateDecision;

    /**
     * Normaliza un mensaje entrante del proveedor (la firma ya la verificó quien lo recibe).
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseInbound(array $payload): ?InboundMessage;

    public function providerName(): string;

    public function isFake(): bool;
}
