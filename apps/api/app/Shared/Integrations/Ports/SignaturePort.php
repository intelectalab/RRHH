<?php

namespace App\Shared\Integrations\Ports;

interface SignaturePort
{
    /**
     * @param  array<int, array{name: string, email: string, order?: int}>  $signers
     * @return string Signature envelope reference ID
     */
    public function createSignatureRequest(string $documentId, array $signers): string;

    public function checkStatus(string $signatureRequestId): string;

    public function providerName(): string;

    public function isFake(): bool;
}
