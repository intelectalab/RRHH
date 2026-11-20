<?php

namespace App\Modules\Platform\Application\LegalUpdates;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PAR-4 · ADR 0023 · Actualización normativa preparada en la consola por el equipo del producto y publicada por
 * una segunda persona (doble revisión). Publicar inserta cada vigencia nueva y cierra la anterior (RN-PAR-02),
 * deja la novedad en legal_parameter_changes (PAR-7) y avisa a cada organización del país por el outbox
 * (RN-PAR-06). Estados: draft → in_review → published | rejected (rechazada vuelve a borrador al editarla).
 */
final class LegalParameterPublications
{
    private const UNITS = ['amount', 'percent', 'days', 'business_days', 'months', 'years', 'hours', 'count', 'multiple', 'text', 'scale'];

    public function __construct(private readonly LegalParameters $legal, private readonly ConsoleAudit $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $status = null): array
    {
        return DB::table('legal_parameter_publications')->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get()->map(fn ($r): array => $this->present($r))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id));
    }

    /**
     * @param  array<string, mixed>  $input  country, title, summary?, legal_source, changes[]
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor): array
    {
        $data = $this->normalize($input);
        $id = (string) Str::uuid();
        DB::table('legal_parameter_publications')->insert($data + ['id' => $id, 'status' => 'draft', 'prepared_by_platform_user_id' => $actor->id]);
        $after = $this->show($id);
        $this->audit->record('legal_update.created', $actor, 'legal_parameter_publication', $id, null, $after);

        return $after;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $row = $this->find($id);
        if (! in_array($row->status, ['draft', 'rejected'], true)) {
            throw new InvalidTransitionException((string) $row->status, $this->allowed((string) $row->status));
        }
        $before = $this->present($row);
        $data = $this->normalize($input + ['country' => $row->country_code, 'title' => $row->title, 'legal_source' => $row->legal_source,
            'summary' => $row->summary, 'changes' => json_decode((string) $row->changes, true)]);
        DB::table('legal_parameter_publications')->where('id', $id)->update($data + ['status' => 'draft', 'prepared_by_platform_user_id' => $actor->id, 'review_note' => null]);
        $after = $this->show($id);
        $this->audit->record('legal_update.updated', $actor, 'legal_parameter_publication', $id, $before, $after);

        return $after;
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(string $id, PlatformUser $actor): array
    {
        return $this->transition($id, 'submit', $actor, function (\stdClass $row): array {
            return ['status' => 'in_review', 'submitted_at' => now()];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $id, string $note, PlatformUser $actor): array
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => trans('platform.legal_updates.note_required')]);
        }

        return $this->transition($id, 'reject', $actor, fn (): array => ['status' => 'rejected', 'review_note' => trim($note), 'reviewed_by_platform_user_id' => $actor->id]);
    }

    /**
     * Publicar: solo otra persona distinta de quien la preparó (409 same_reviewer). Aplica todos los cambios en una
     * transacción: si uno se superpone con la historia, no se publica nada.
     *
     * @return array<string, mixed>
     */
    public function publish(string $id, ?string $note, PlatformUser $actor): array
    {
        $row = $this->find($id);
        if ($row->status === 'in_review' && (string) $row->prepared_by_platform_user_id === (string) $actor->id) {
            throw new AyniException('same_reviewer', 409, (string) trans('platform.legal_updates.same_reviewer'));
        }

        return $this->transition($id, 'publish', $actor, function (\stdClass $row) use ($actor, $note): array {
            /** @var list<array<string, mixed>> $changes */
            $changes = json_decode((string) $row->changes, true);
            $applied = [];
            foreach ($changes as $i => $c) {
                try {
                    $applied[] = $this->legal->apply((string) $row->country_code, [
                        'code' => (string) $c['code'], 'regime' => $c['regime'] ?? null, 'name' => $c['name'] ?? null, 'unit' => $c['unit'] ?? null,
                        'value' => $c['value'] ?? null, 'value_json' => $c['value_json'] ?? null, 'valid_from' => (string) $c['valid_from'],
                        'valid_to' => $c['valid_to'] ?? null, 'legal_source' => $c['legal_source'] ?? $row->legal_source,
                        'verified' => (bool) ($c['verified'] ?? true), 'notes' => $c['notes'] ?? null,
                    ], 'publication', (string) $row->id);
                } catch (\DomainException) {
                    throw ValidationException::withMessages(["changes.$i.valid_from" => trans('platform.legal_updates.overlap', ['code' => (string) $c['code']])]);
                }
            }
            // RN-PAR-06 · Aviso a cada organización del país, con la norma y la fecha (en la misma transacción).
            $dates = array_map(fn ($c) => (string) $c['valid_from'], $changes);
            $first = $dates === [] ? now()->toDateString() : min($dates);
            $tenants = DB::table('tenants')->where('country_code', $row->country_code)->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled', 'deleted'])->pluck('id');
            $events = [];
            foreach ($tenants as $tenantId) {
                $events[] = TenancyScope::runAs((string) $tenantId, fn () => $this->outbox->record(DomainEventTypes::LEGAL_PARAMETERS_PUBLISHED, 'legal_parameter_publication', (string) $row->id, [
                    'publication_id' => (string) $row->id, 'title' => (string) $row->title, 'legal_source' => (string) $row->legal_source,
                    'valid_from' => $first, 'codes' => array_values(array_unique(array_map(fn ($c) => (string) $c['code'], $changes))),
                ]));
            }
            DB::afterCommit(function () use ($events): void {
                foreach ($events as $e) {
                    $this->outbox->deliverSoon($e);
                }
            });

            return ['status' => 'published', 'published_at' => now(), 'reviewed_by_platform_user_id' => $actor->id,
                'review_note' => $note === null || trim($note) === '' ? null : trim($note)];
        });
    }

    /**
     * @param  callable(\stdClass): array<string, mixed>  $apply
     * @return array<string, mixed>
     */
    private function transition(string $id, string $action, PlatformUser $actor, callable $apply): array
    {
        return DB::transaction(function () use ($id, $action, $actor, $apply): array {
            $row = DB::table('legal_parameter_publications')->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                throw new AyniException('not_found', 404, (string) trans('platform.legal_updates.not_found'));
            }
            if (! in_array($action, $this->allowed((string) $row->status), true)) {
                throw new InvalidTransitionException((string) $row->status, $this->allowed((string) $row->status));
            }
            $before = $this->present($row);
            DB::table('legal_parameter_publications')->where('id', $id)->update($apply($row));
            $after = $this->show($id);
            $this->audit->record('legal_update.'.$action, $actor, 'legal_parameter_publication', $id, $before, $after);

            return $after;
        });
    }

    /**
     * @return list<string>
     */
    private function allowed(string $status): array
    {
        return match ($status) {
            'draft' => ['submit'],
            'in_review' => ['publish', 'reject'],
            'rejected' => [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $country = strtoupper((string) ($input['country'] ?? ''));
        if (! DB::table('countries')->where('code', $country)->exists()) {
            throw ValidationException::withMessages(['country' => trans('platform.legal_updates.unknown_country')]);
        }
        foreach (['title', 'legal_source'] as $f) {
            if (trim((string) ($input[$f] ?? '')) === '') {
                throw ValidationException::withMessages([$f => trans('platform.legal_updates.required', ['field' => $f])]);
            }
        }
        $changes = $input['changes'] ?? null;
        if (! is_array($changes) || $changes === []) {
            throw ValidationException::withMessages(['changes' => trans('platform.legal_updates.changes_required')]);
        }
        $clean = [];
        foreach (array_values($changes) as $i => $c) {
            if (! is_array($c)) {
                throw ValidationException::withMessages(["changes.$i" => trans('platform.legal_updates.invalid_change')]);
            }
            $code = trim((string) ($c['code'] ?? ''));
            $regime = isset($c['regime']) && $c['regime'] !== '' ? (string) $c['regime'] : null;
            $from = (string) ($c['valid_from'] ?? '');
            if ($code === '' || preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
                throw ValidationException::withMessages(["changes.$i.code" => trans('platform.legal_updates.invalid_code')]);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
                throw ValidationException::withMessages(["changes.$i.valid_from" => trans('platform.legal_updates.invalid_date')]);
            }
            if ($regime !== null && ! DB::table('labor_regimes')->where('code', $regime)->exists()) {
                throw ValidationException::withMessages(["changes.$i.regime" => trans('platform.legal_updates.unknown_regime')]);
            }
            $exists = DB::table('legal_parameters')->where('country_code', $country)->where('code', $code)->exists();
            $unit = isset($c['unit']) ? (string) $c['unit'] : null;
            if (! $exists && ($unit === null || trim((string) ($c['name'] ?? '')) === '')) {
                // Un parámetro nuevo necesita nombre y unidad.
                throw ValidationException::withMessages(["changes.$i.name" => trans('platform.legal_updates.new_parameter')]);
            }
            if ($unit !== null && ! in_array($unit, self::UNITS, true)) {
                throw ValidationException::withMessages(["changes.$i.unit" => trans('platform.legal_updates.invalid_unit')]);
            }
            $hasValue = isset($c['value']) && $c['value'] !== '';
            if ($hasValue && ! is_numeric($c['value'])) {
                throw ValidationException::withMessages(["changes.$i.value" => trans('platform.legal_updates.invalid_value')]);
            }
            if (! $hasValue && ! isset($c['value_json'])) {
                throw ValidationException::withMessages(["changes.$i.value" => trans('platform.legal_updates.value_required')]);
            }
            $to = isset($c['valid_to']) && $c['valid_to'] !== '' ? (string) $c['valid_to'] : null;
            if ($to !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from)) {
                throw ValidationException::withMessages(["changes.$i.valid_to" => trans('platform.legal_updates.invalid_date')]);
            }
            $clean[] = array_filter([
                'code' => $code, 'regime' => $regime, 'name' => isset($c['name']) ? trim((string) $c['name']) : null, 'unit' => $unit,
                'value' => $hasValue ? (string) $c['value'] : null, 'value_json' => $c['value_json'] ?? null, 'valid_from' => $from, 'valid_to' => $to,
                'legal_source' => trim((string) ($c['legal_source'] ?? '')) !== '' ? trim((string) $c['legal_source']) : null,
                'verified' => (bool) ($c['verified'] ?? true), 'notes' => isset($c['notes']) ? trim((string) $c['notes']) : null,
            ], fn ($v) => $v !== null);
        }

        return ['country_code' => $country, 'title' => trim((string) $input['title']), 'summary' => isset($input['summary']) ? trim((string) $input['summary']) : null,
            'legal_source' => trim((string) $input['legal_source']), 'changes' => json_encode($clean)];
    }

    private function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('legal_parameter_publications')->where('id', $id)->first() : null;
        if ($row === null) {
            throw new AyniException('not_found', 404, (string) trans('platform.legal_updates.not_found'));
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r): array
    {
        $changes = json_decode((string) $r->changes, true);
        $iso = fn ($v): ?string => $v === null ? null : CarbonImmutable::parse((string) $v)->toIso8601String();

        return [
            'id' => (string) $r->id, 'country' => (string) $r->country_code, 'title' => (string) $r->title, 'summary' => $r->summary === null ? null : (string) $r->summary,
            'legal_source' => (string) $r->legal_source, 'changes' => $changes, 'status' => (string) $r->status,
            'prepared_by_platform_user_id' => $r->prepared_by_platform_user_id === null ? null : (string) $r->prepared_by_platform_user_id,
            'reviewed_by_platform_user_id' => $r->reviewed_by_platform_user_id === null ? null : (string) $r->reviewed_by_platform_user_id,
            'review_note' => $r->review_note === null ? null : (string) $r->review_note,
            'submitted_at' => $iso($r->submitted_at), 'published_at' => $iso($r->published_at), 'created_at' => $iso($r->created_at),
            'allowed_actions' => $this->allowed((string) $r->status),
        ];
    }
}
