<?php

namespace App\Modules\Certificates\Infrastructure\Inbox;

use App\Shared\Inbox\InboxSource;
use App\Shared\Permissions\DataScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** ESS-4 · Constancias que esperan a RRHH (sin flujo de aprobación) en la bandeja de quien las emite. */
final class CertificateInboxSource implements InboxSource
{
    private const PERMISSION = 'people.certificates.issue';

    public function __construct(private readonly DataScope $scope) {}

    public function kind(): string
    {
        return 'certificate';
    }

    public function permission(): string
    {
        return self::PERMISSION;
    }

    public function items(string $userId, int $limit): array
    {
        return $this->query($userId)->orderBy('c.created_at')->limit($limit)
            ->get(['c.id', 'c.kind', 'c.created_at', 'p.first_names', 'p.preferred_name', 'p.paternal_surname'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'type' => (string) $r->kind,
                'title' => (string) trans('certificates.inbox_title', ['kind' => trans('certificates.kinds.'.$r->kind)]),
                'subtitle' => trim(($r->preferred_name ?: $r->first_names).' '.$r->paternal_surname),
                'due_at' => null,
                'overdue' => false,
                'created_at' => (string) $r->created_at,
                'href' => '/personas/constancias?id='.$r->id,
            ])->values()->all();
    }

    public function count(string $userId): int
    {
        return $this->query($userId)->count();
    }

    private function query(string $userId): Builder
    {
        $q = DB::table('certificate_requests as c')->join('employments as e', 'e.id', '=', 'c.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')->where('c.status', 'requested');
        $scope = $this->scope->employmentScope($userId, self::PERMISSION);
        if ($scope !== null) {
            $q->whereRaw('c.employment_id IN ('.$scope[0].')', $scope[1]);
        }

        return $q;
    }
}
