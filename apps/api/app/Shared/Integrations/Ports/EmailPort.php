<?php

namespace App\Shared\Integrations\Ports;

use App\Shared\Integrations\Email\EmailFeedbackEvent;
use App\Shared\Integrations\Email\InvalidWebhookSignature;

interface EmailPort
{
    /**
     * @param  array<string, mixed>  $attachments
     * @param  string|null  $from  remitente propio verificado de la organización; null = el de la plataforma (NOT-3)
     * @return string Message reference ID
     */
    public function send(string $to, string $subject, string $body, array $attachments = [], ?string $from = null): string;

    /**
     * NOT-3 · Estado de verificación de un remitente propio en el proveedor (dominio con SPF/DKIM o
     * dirección confirmada): verified, pending o failed.
     */
    public function verifySender(string $address): string;

    /**
     * NOT-3 · Avisos del proveedor (rebotes, quejas, entregas) recibidos por webhook, ya normalizados.
     * Comprueba la firma del proveedor antes de leer nada.
     *
     * @param  array<string, string>  $headers  cabeceras en minúsculas
     * @return list<EmailFeedbackEvent>
     *
     * @throws InvalidWebhookSignature
     */
    public function parseFeedback(string $payload, array $headers): array;

    public function providerName(): string;

    public function isFake(): bool;
}
