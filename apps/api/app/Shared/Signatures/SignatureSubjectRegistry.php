<?php

namespace App\Shared\Signatures;

/** DOC-6 · Registro de los dueños de documentos que se firman con el flujo común (singleton). */
final class SignatureSubjectRegistry
{
    /** @var array<string, SignatureSubject> */
    private array $subjects = [];

    public function register(SignatureSubject $subject): void
    {
        $this->subjects[$subject->subjectType()] = $subject;
    }

    public function for(?string $subjectType): ?SignatureSubject
    {
        return $subjectType === null ? null : ($this->subjects[$subjectType] ?? null);
    }
}
