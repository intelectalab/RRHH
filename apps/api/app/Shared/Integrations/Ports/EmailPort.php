<?php

namespace App\Shared\Integrations\Ports;

interface EmailPort
{
    /**
     * @param  array<string, mixed>  $attachments
     * @return string Message reference ID
     */
    public function send(string $to, string $subject, string $body, array $attachments = []): string;

    public function providerName(): string;

    public function isFake(): bool;
}
