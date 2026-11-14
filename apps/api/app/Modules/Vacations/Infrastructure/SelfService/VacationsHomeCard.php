<?php

namespace App\Modules\Vacations\Infrastructure\SelfService;

use App\Modules\Vacations\Domain\VacationRequestStateMachine;
use App\Shared\SelfService\HomeCard;
use App\Shared\Time\VacationBalances;
use Illuminate\Support\Facades\DB;

/**
 * ESS-1 · Tarjeta «Mis vacaciones» del inicio: días disponibles (suma del libro, RN-VAC-02; el navegador no
 * calcula saldos, regla 11), solicitudes en aprobación y el próximo goce aprobado.
 */
final class VacationsHomeCard implements HomeCard
{
    public function __construct(private readonly VacationBalances $balances) {}

    public function key(): string
    {
        return 'vacations';
    }

    public function permission(): string
    {
        return 'time.vacation_balances.view';
    }

    public function order(): int
    {
        return 10;
    }

    public function card(string $userId, string $employmentId): array
    {
        $balance = $this->balances->balance($employmentId);
        $today = now()->toDateString();

        $pending = DB::table('vacation_requests')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->whereIn('status', VacationRequestStateMachine::PENDING_STATES)->count();
        $next = DB::table('vacation_requests')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'in_progress'])->where('ends_on', '>=', $today)
            ->orderBy('starts_on')->first(['id', 'starts_on', 'ends_on', 'days', 'status']);

        return [
            'href' => '/tiempo/vacaciones',
            'pending' => $pending,
            'data' => [
                'available_days' => $balance['totals']['available'],
                'scheduled_days' => $balance['totals']['scheduled'],
                'overdue_days' => $balance['overdue_days'],
                'advance_available' => $balance['advance_available'],
                'pending_validation' => $balance['pending_validation'],
                'pending_requests' => $pending,
                'next' => $next === null ? null : [
                    'id' => (string) $next->id,
                    'starts_on' => substr((string) $next->starts_on, 0, 10),
                    'ends_on' => substr((string) $next->ends_on, 0, 10),
                    'days' => (string) $next->days,
                    'status' => (string) $next->status,
                ],
            ],
        ];
    }
}
