<?php

namespace App\Modules\Platform\Application\Web;

use App\Modules\Platform\Application\PlatformSettings;
use App\Shared\Exceptions\AyniException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WEB-6 · Agendar demo desde el sitio. Se ofrecen solo horarios libres dentro del horario configurado en la consola
 * y con la anticipación mínima (web.demo_*). Cada demo deja un lead «demo» con origen, campaña y consentimiento,
 * confirma por correo con un enlace de un solo propietario (token cuyo hash vive en base) para reprogramar o
 * cancelar, y recibe un recordatorio el día anterior. Dos personas no pueden tomar el mismo horario: la reserva se
 * hace con un bloqueo de la agenda en la transacción.
 */
final class DemoScheduling
{
    private const ACTIVE = ['scheduled', 'rescheduled'];

    public function __construct(
        private readonly PublicFormGuard $guard,
        private readonly WebMessages $messages,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public function slots(?string $from = null, ?string $exceptBookingId = null): array
    {
        $tz = (string) ($this->settings->value('web.demo_timezone') ?? 'America/Lima');
        $minutes = (int) $this->settings->value('web.demo_slot_minutes');
        $earliest = CarbonImmutable::now()->addHours((int) $this->settings->value('web.demo_min_notice_hours'));
        $days = (int) $this->settings->value('web.demo_days_ahead');
        $weekdays = array_map('intval', (array) $this->settings->value('web.demo_weekdays'));
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->settings->value('web.demo_day_start')));
        [$eh, $em] = array_map('intval', explode(':', (string) $this->settings->value('web.demo_day_end')));

        $start = CarbonImmutable::now($tz)->startOfDay();
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $start = max($start, CarbonImmutable::parse($from, $tz)->startOfDay());
        }
        $last = CarbonImmutable::now($tz)->startOfDay()->addDays($days);
        $taken = DB::table('web_demo_bookings')->whereIn('status', self::ACTIVE)
            ->when($exceptBookingId !== null, fn ($q) => $q->where('id', '!=', $exceptBookingId))
            ->where('ends_at', '>', $start->utc())->get(['starts_at', 'ends_at'])
            ->map(fn ($b): array => [CarbonImmutable::parse((string) $b->starts_at), CarbonImmutable::parse((string) $b->ends_at)])->all();

        $out = [];
        for ($day = $start; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }
            $end = $day->setTime($eh, $em);
            for ($slot = $day->setTime($sh, $sm); $slot->addMinutes($minutes)->lessThanOrEqualTo($end); $slot = $slot->addMinutes($minutes)) {
                $slotEnd = $slot->addMinutes($minutes);
                if ($slot->lessThan($earliest)) {
                    continue;
                }
                foreach ($taken as [$bs, $be]) {
                    if ($slot->lessThan($be) && $slotEnd->greaterThan($bs)) {
                        continue 2;
                    }
                }
                $out[] = ['starts_at' => $slot->toIso8601String(), 'ends_at' => $slotEnd->toIso8601String()];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function book(array $input, ?string $ip, ?string $idempotencyKey): array
    {
        $this->guard->check($input, $ip);
        if ($idempotencyKey !== null) {
            $lead = DB::table('web_leads')->where('idempotency_key', $idempotencyKey)->where('kind', 'demo')->first();
            $existing = $lead === null ? null : DB::table('web_demo_bookings')->where('web_lead_id', $lead->id)->first();
            if ($existing !== null) {
                return $this->present($existing);
            }
        }
        $token = Str::random(48);
        $id = (string) Str::uuid();
        $email = mb_strtolower(trim((string) $input['email']));
        $productId = empty($input['product_code']) ? null : DB::table('web_products')->where('code', (string) $input['product_code'])->value('id');

        DB::transaction(function () use ($id, $input, $email, $token, $productId, $idempotencyKey): void {
            [$starts, $ends] = $this->reserve((string) $input['starts_at'], null);
            $leadId = (string) Str::uuid();
            DB::table('web_leads')->insert([
                'id' => $leadId, 'kind' => 'demo', 'full_name' => (string) $input['full_name'], 'email' => $email,
                'phone' => $input['phone'] ?? null, 'company_name' => $input['company_name'] ?? null, 'employees_range' => $input['employees_range'] ?? null,
                'web_product_id' => $productId, 'source_page' => $input['source_page'] ?? null,
                'utm' => isset($input['utm']) ? json_encode($input['utm'], JSON_THROW_ON_ERROR) : null,
                'consent_purpose' => (string) ($input['consent_purpose'] ?? 'demo'), 'consent_at' => now(),
                'message' => $input['message'] ?? null, 'idempotency_key' => $idempotencyKey,
            ]);
            DB::table('web_demo_bookings')->insert([
                'id' => $id, 'web_lead_id' => $leadId, 'starts_at' => $starts, 'ends_at' => $ends,
                'channel' => $input['channel'] ?? 'video', 'manage_token_hash' => $this->hash($token), 'status' => 'scheduled',
            ]);
            $this->confirm($id, $email, (string) $input['full_name'], $input['locale'] ?? 'es', $starts, $token);
        });

        return $this->present($this->find($id)) + ['manage_token' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    public function showByToken(string $token): array
    {
        return $this->present($this->byToken($token));
    }

    /**
     * Cancelar o reprogramar desde el enlace del correo.
     *
     * @return array<string, mixed>
     */
    public function manage(string $token, string $action, ?string $startsAt, ?string $locale): array
    {
        $booking = $this->byToken($token);
        if (! in_array($booking->status, self::ACTIVE, true) || CarbonImmutable::parse((string) $booking->starts_at)->isPast()) {
            throw new AyniException('booking_closed', 409, (string) trans('platform.web.booking_closed'));
        }
        $lead = DB::table('web_leads')->where('id', $booking->web_lead_id)->first(['email', 'full_name']);
        if (! $lead instanceof \stdClass) {
            throw new AyniException('not_found', 404, (string) trans('platform.web.booking_not_found'));
        }
        DB::transaction(function () use ($booking, $action, $startsAt, $locale, $lead, $token): void {
            if ($action === 'cancel') {
                DB::table('web_demo_bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);
                $this->messages->queue('demo_cancelled', (string) $lead->email, $locale, 'platform.web.mail.demo_cancelled_subject', 'platform.web.mail.demo_cancelled_body', [
                    'name' => (string) ($lead->full_name ?? ''), 'when' => $this->when((string) $booking->starts_at),
                    'link' => rtrim((string) config('platform.site_url'), '/').'/demo',
                ], 'web_demo_booking', (string) $booking->id);

                return;
            }
            [$starts, $ends] = $this->reserve((string) $startsAt, (string) $booking->id);
            DB::table('web_demo_bookings')->where('id', $booking->id)->update([
                'starts_at' => $starts, 'ends_at' => $ends, 'status' => 'rescheduled', 'reminded_at' => null,
            ]);
            $this->confirm((string) $booking->id, (string) $lead->email, (string) ($lead->full_name ?? ''), $locale, $starts, $token);
        });

        return $this->present($this->find((string) $booking->id));
    }

    /**
     * Recordatorio el día anterior, una sola vez por horario (se borra al reprogramar).
     */
    public function remind(): int
    {
        $count = 0;
        $rows = DB::table('web_demo_bookings AS b')->join('web_leads AS l', 'l.id', '=', 'b.web_lead_id')
            ->whereIn('b.status', self::ACTIVE)->whereNull('b.reminded_at')
            ->where('b.starts_at', '>', now())->where('b.starts_at', '<=', now()->addDay())
            ->get(['b.id', 'b.starts_at', 'l.email', 'l.full_name']);
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, &$count): void {
                if (DB::table('web_demo_bookings')->where('id', $row->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 0) {
                    return;
                }
                $this->messages->queue('demo_reminder', (string) $row->email, 'es', 'platform.web.mail.demo_reminder_subject', 'platform.web.mail.demo_body', [
                    'name' => (string) ($row->full_name ?? ''), 'when' => $this->when((string) $row->starts_at),
                    'link' => rtrim((string) config('platform.site_url'), '/').'/demo',
                ], 'web_demo_booking', (string) $row->id);
                $count++;
            });
        }

        return $count;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?string $status): array
    {
        $rows = DB::table('web_demo_bookings AS b')->join('web_leads AS l', 'l.id', '=', 'b.web_lead_id')
            ->when($status !== null && $status !== '', fn ($q) => $q->where('b.status', $status))
            ->orderBy('b.starts_at')->limit(500)->get(['b.*', 'l.full_name', 'l.email', 'l.company_name', 'l.source_page']);

        $data = array_values($rows->map(fn ($r): array => $this->present($r) + [
            'full_name' => $r->full_name, 'email' => (string) $r->email, 'company_name' => $r->company_name, 'source_page' => $r->source_page,
        ])->all());

        return ['data' => $data, 'meta' => ['page' => 1, 'per_page' => 500, 'total' => count($data)]];
    }

    /**
     * Toma el horario: tiene que ser uno de los ofrecidos y seguir libre con la agenda bloqueada.
     *
     * @return array{0: string, 1: string}
     */
    private function reserve(string $startsAt, ?string $exceptId): array
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('web_demo_bookings'))");
        try {
            $wanted = CarbonImmutable::parse($startsAt);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => [(string) trans('platform.web.slot_unavailable')]]);
        }
        foreach ($this->slots($wanted->setTimezone((string) ($this->settings->value('web.demo_timezone') ?? 'America/Lima'))->toDateString(), $exceptId) as $slot) {
            if (CarbonImmutable::parse($slot['starts_at'])->equalTo($wanted)) {
                return [CarbonImmutable::parse($slot['starts_at'])->utc()->toIso8601String(), CarbonImmutable::parse($slot['ends_at'])->utc()->toIso8601String()];
            }
        }
        throw new AyniException('slot_unavailable', 409, (string) trans('platform.web.slot_unavailable'));
    }

    private function confirm(string $bookingId, string $email, string $name, ?string $locale, string $startsAt, string $token): void
    {
        $this->messages->queue('demo_booked', $email, $locale, 'platform.web.mail.demo_subject', 'platform.web.mail.demo_body', [
            'name' => $name, 'when' => $this->when($startsAt),
            'link' => rtrim((string) config('platform.site_url'), '/').'/demo/gestionar?t='.$token,
        ], 'web_demo_booking', $bookingId);
    }

    private function when(string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone((string) ($this->settings->value('web.demo_timezone') ?? 'America/Lima'))->format('d/m/Y H:i');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'starts_at' => CarbonImmutable::parse((string) $row->starts_at)->toIso8601String(),
            'ends_at' => CarbonImmutable::parse((string) $row->ends_at)->toIso8601String(),
            'channel' => (string) $row->channel,
            'status' => (string) $row->status,
            'meeting_url' => $row->meeting_url,
            'web_lead_id' => (string) $row->web_lead_id,
        ];
    }

    private function find(string $id): \stdClass
    {
        $row = DB::table('web_demo_bookings')->where('id', $id)->first();
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404, (string) trans('platform.web.booking_not_found'));
        }

        return $row;
    }

    private function byToken(string $token): \stdClass
    {
        $row = DB::table('web_demo_bookings')->where('manage_token_hash', $this->hash($token))->first();
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404, (string) trans('platform.web.booking_not_found'));
        }

        return $row;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
