<?php

namespace App\Modules\Contracts\Infrastructure\Signature;

use App\Modules\Contracts\Application\AmendmentService;
use App\Modules\Contracts\Application\ContractService;
use App\Modules\Contracts\Application\RemoteWorkService;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use App\Modules\Contracts\Infrastructure\Models\RemoteWorkAgreement;
use App\Shared\Exceptions\AyniException;
use App\Shared\Signatures\SignatureSubject;

/**
 * DOC-6 · CON-5 · El contrato, la adenda y el acuerdo de teletrabajo se firman con el flujo común: solo se envían
 * a firma cuando están «En firma», y al completarse registran la firma electrónica con la constancia sellada como
 * ejemplar firmado (mismo efecto que subir el escaneo: RN-CON-06 y RN-CON-08).
 */
final class ContractSignatureSubjects implements SignatureSubject
{
    private function __construct(private readonly string $type) {}

    public static function contract(): self
    {
        return new self('contract');
    }

    public static function addendum(): self
    {
        return new self('contract_addendum');
    }

    public static function remoteWork(): self
    {
        return new self('remote_work_agreement');
    }

    public function subjectType(): string
    {
        return $this->type;
    }

    public function assertCanSend(string $subjectId): void
    {
        $status = match ($this->type) {
            'contract' => Contract::query()->whereKey($subjectId)->value('status'),
            'contract_addendum' => ContractAddendum::query()->whereKey($subjectId)->value('status'),
            default => RemoteWorkAgreement::query()->whereKey($subjectId)->value('status'),
        };
        if ($status !== 'pending_signature') {
            throw new AyniException('invalid_transition', 409, (string) trans('contracts.contracts.not_pending_signature'));
        }
    }

    public function completed(string $subjectId, string $signedPdf, string $fileName, string $signedOn, ?string $userId): void
    {
        match ($this->type) {
            'contract' => app(ContractService::class)->registerSignature(Contract::query()->findOrFail($subjectId), $signedPdf, $fileName, 'application/pdf', 'electronic', $signedOn, $userId),
            'contract_addendum' => $this->registerAddendum($subjectId, $signedPdf, $fileName, $signedOn, $userId),
            default => app(RemoteWorkService::class)->registerSignature(RemoteWorkAgreement::query()->findOrFail($subjectId), $signedPdf, $fileName, 'application/pdf', 'electronic', $signedOn, $userId),
        };
    }

    public function declined(string $subjectId, string $reason, ?string $userId): void
    {
        // El contrato vuelve a Borrador para corregirlo (ME-11 «rechazar firma»); la adenda y el acuerdo siguen
        // «En firma» para reenviarlos o anularlos.
        if ($this->type === 'contract') {
            app(ContractService::class)->declineSignature(Contract::query()->findOrFail($subjectId), $reason, $userId);
        }
    }

    private function registerAddendum(string $id, string $pdf, string $name, string $signedOn, ?string $userId): void
    {
        /** @var ContractAddendum $a */
        $a = ContractAddendum::query()->findOrFail($id);
        /** @var Contract $contract */
        $contract = Contract::query()->findOrFail($a->contract_id);
        app(AmendmentService::class)->registerSignature($a, $contract, $pdf, $name, 'application/pdf', 'electronic', $signedOn, $userId);
    }
}
