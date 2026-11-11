<?php

namespace App\Modules\Absences\Domain;

/**
 * AUS-1 · Reglas de un tipo de ausencia vigentes a una fecha (valor inmutable). Lo usarán la solicitud
 * (AUS-2), asistencia y planilla para decidir sin repetir la lógica.
 */
final class AbsenceTypeRules
{
    /** Quién puede solicitar, de menor a mayor: el colaborador (autoservicio), su jefe, solo RRHH. */
    public const REQUESTERS = ['employee', 'manager', 'hr'];

    /**
     * @param  list<string>|null  $legalEntityIds  null o vacío = todas las empresas
     * @param  list<string>|null  $laborRegimeCodes  null o vacío = todos los regímenes
     */
    public function __construct(
        public readonly bool $isPaid,
        public readonly string $requestableBy,
        public readonly ?array $legalEntityIds,
        public readonly ?array $laborRegimeCodes,
    ) {}

    /**
     * ¿Puede pedirla quien actúa como $requester? RRHH puede todo; el jefe, lo que admite jefe o
     * colaborador; el colaborador, solo lo de autoservicio.
     */
    public function canBeRequestedBy(string $requester): bool
    {
        $need = array_search($this->requestableBy, self::REQUESTERS, true);
        $have = array_search($requester, self::REQUESTERS, true);

        return $need !== false && $have !== false && $have >= $need;
    }

    /** ¿Aplica a un colaborador de esa empresa y régimen? Sin límite configurado, aplica a todos. */
    public function appliesTo(?string $legalEntityId, ?string $laborRegimeCode): bool
    {
        if ($this->legalEntityIds !== null && $this->legalEntityIds !== [] && ! in_array($legalEntityId, $this->legalEntityIds, true)) {
            return false;
        }

        return $this->laborRegimeCodes === null || $this->laborRegimeCodes === [] || in_array($laborRegimeCode, $this->laborRegimeCodes, true);
    }

    /** RN-AUS-02: sin goce descuenta remuneración en planilla. */
    public function deductsPay(): bool
    {
        return ! $this->isPaid;
    }
}
