<?php

namespace App\Modules\Certificates\Infrastructure\SelfService;

use App\Shared\SelfService\HomeCard;
use Illuminate\Support\Facades\DB;

/** ESS-4 · Tarjeta del inicio del colaborador: constancias en curso y la última emitida. */
final class CertificatesHomeCard implements HomeCard
{
    public function key(): string
    {
        return 'certificates';
    }

    public function permission(): string
    {
        return 'people.certificates.request';
    }

    public function order(): int
    {
        return 40;
    }

    public function card(string $userId, string $employmentId): array
    {
        $pending = DB::table('certificate_requests')->where('employment_id', $employmentId)->whereIn('status', ['requested', 'in_progress'])->count();
        $last = DB::table('certificate_requests')->where('employment_id', $employmentId)->where('status', 'issued')
            ->orderByDesc('issued_at')->first(['id', 'kind', 'issued_at']);

        return [
            'href' => '/mi/solicitudes',
            'pending' => $pending,
            'data' => [
                'pending_requests' => $pending,
                'last_issued' => $last === null ? null : [
                    'id' => (string) $last->id, 'kind' => (string) $last->kind, 'kind_name' => (string) trans('certificates.kinds.'.$last->kind),
                    'issued_at' => $last->issued_at === null ? null : (string) $last->issued_at,
                ],
            ],
        ];
    }
}
