<?php

namespace App\Shared\Signatures;

/**
 * DOC-6 · Puerto para que otros módulos (renovación masiva de contratos) envíen un documento a firma y lean su
 * avance sin conocer el módulo de documentos.
 */
interface SignatureRequests
{
    /**
     * Crea y envía la solicitud de firma de un documento: firma primero el colaborador del documento y luego
     * quien la envía (representante de la empresa).
     *
     * @return string id de la solicitud de firma
     */
    public function sendForEmployeeAndRepresentative(string $documentId, string $title, string $representativeUserId): string;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{status: string, signed: int, total: int}>
     */
    public function progress(array $ids): array;
}
