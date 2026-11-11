<?php

namespace App\Shared\Messaging;

/**
 * NOT-5 · Respuesta del proveedor a una plantilla enviada a aprobación: approved, pending o rejected.
 */
final class TemplateDecision
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerTemplateRef = null,
        public readonly ?string $reason = null,
    ) {}
}
