<?php

namespace App\Shared\Signatures;

/**
 * DOC-6 · Contrato para que el módulo dueño de un documento (contrato, adenda, acuerdo de teletrabajo…) reaccione
 * al flujo de firma común. El flujo es el mismo para todos; solo cambia lo que pasa con el registro al completarse
 * o rechazarse. El módulo de documentos no conoce a los dueños: los busca por el subject_type del documento.
 */
interface SignatureSubject
{
    /** Valor de documents.subject_type que atiende (p. ej. contract, contract_addendum). */
    public function subjectType(): string;

    /** Lanza una excepción de dominio si el registro no está listo para enviarse a firma (p. ej. no está «En firma»). */
    public function assertCanSend(string $subjectId): void;

    /**
     * Todos firmaron: el dueño registra la firma electrónica con el ejemplar sellado (constancia de firma) como
     * versión firmada del documento.
     */
    public function completed(string $subjectId, string $signedPdf, string $fileName, string $signedOn, ?string $userId): void;

    /** Un firmante rechazó con motivo: el dueño decide qué hacer con su registro. */
    public function declined(string $subjectId, string $reason, ?string $userId): void;
}
