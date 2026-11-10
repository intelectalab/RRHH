<?php

namespace App\Shared\Integrations\Ports;

interface MessagingPort
{
    /**
     * @return string Message reference ID
     */
    public function sendMessage(string $phoneNumber, string $message): string;

    public function providerName(): string;

    public function isFake(): bool;
}
