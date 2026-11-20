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

    /**
     * DOC-8 · Firma con certificado digital de un firmante: el proveedor sella la huella del documento y devuelve
     * su evidencia (número de serie y emisor del certificado, fecha y firma). El flujo no cambia con el proveedor.
     *
     * @param  array{name: string, email?: string|null, user_id?: string|null}  $signer
     * @return array{provider: string, certificate_serial: string, certificate_issuer: string, signed_at: string, signature: string}
     */
    public function sign(string $envelopeId, array $signer, string $documentHash): array;

    public function providerName(): string;

    public function isFake(): bool;
}
