<?php

namespace App\Shared\SelfService;

use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;

/**
 * ESS · Relaciones laborales propias del usuario (users.person_id → employments.person_id).
 *
 * - RN-ESS-01: el portal solo opera sobre estas relaciones; un identificador ajeno responde 404.
 * - RN-ESS-04: con varias relaciones activas, el colaborador elige con cuál opera; por defecto la activa
 *   más reciente.
 * - RN-ESS-05: una relación cesada sigue en la lista (solo lectura); las anuladas no.
 */
final class OwnEmployments
{
    /** Estados en los que el colaborador opera con normalidad. */
    public const OPERATIVE = ['active', 'suspended', 'terminating', 'pending'];

    /**
     * @return list<array{id: string, person_id: string, legal_entity_id: string, legal_entity_name: string|null, employee_code: string, status: string, hire_date: string, termination_date: string|null, read_only: bool}>
     */
    public function list(string $userId): array
    {
        $rows = DB::table('employments as e')
            ->join('users as u', 'u.person_id', '=', 'e.person_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'e.legal_entity_id')
            ->where('u.id', $userId)
            ->whereNull('e.deleted_at')
            ->where('e.status', '<>', 'cancelled')
            ->orderByRaw("CASE WHEN e.status IN ('active','suspended','terminating') THEN 0 WHEN e.status = 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('e.hire_date')
            ->get(['e.id', 'e.person_id', 'e.legal_entity_id', DB::raw('COALESCE(le.trade_name, le.legal_name) as legal_entity_name'), 'e.employee_code', 'e.status', 'e.hire_date', 'e.termination_date']);

        return array_values($rows->map(fn (object $r): array => [
            'id' => (string) $r->id,
            'person_id' => (string) $r->person_id,
            'legal_entity_id' => (string) $r->legal_entity_id,
            'legal_entity_name' => $r->legal_entity_name === null ? null : (string) $r->legal_entity_name,
            'employee_code' => (string) $r->employee_code,
            'status' => (string) $r->status,
            'hire_date' => substr((string) $r->hire_date, 0, 10),
            'termination_date' => $r->termination_date === null ? null : substr((string) $r->termination_date, 0, 10),
            'read_only' => ! in_array((string) $r->status, self::OPERATIVE, true),
        ])->all());
    }

    /**
     * La relación elegida (o la de por defecto). Null si el usuario no tiene ninguna.
     *
     * @return array{id: string, person_id: string, legal_entity_id: string, legal_entity_name: string|null, employee_code: string, status: string, hire_date: string, termination_date: string|null, read_only: bool}|null
     *
     * @throws AyniException 404 si el identificador pedido no es propio (RN-ESS-01)
     */
    public function pick(string $userId, mixed $requested): ?array
    {
        $own = $this->list($userId);
        if ($requested === null || $requested === '') {
            return $own[0] ?? null;
        }
        foreach ($own as $employment) {
            if (is_string($requested) && $employment['id'] === $requested) {
                return $employment;
            }
        }

        throw new AyniException('not_found', 404, (string) trans('errors.not_found'));
    }
}
