<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\EmergencyContact;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\PersonAddress;
use App\Shared\Audit\AuditLogger;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-4 · Datos personales de la persona (no de la relación laboral): foto, direcciones, contactos de
 * emergencia y datos sensibles con consentimiento. Pertenecen a la persona y se comparten entre todas sus
 * relaciones laborales (C-01).
 *
 * - La dirección y el teléfono de un contacto se guardan cifrados (FieldCipher). Cambiar la dirección no la
 *   sobrescribe: la anterior queda como histórica (is_current = false) y se crea otra.
 * - RN-COL-08 · La condición de discapacidad solo se registra con consentimiento expreso: se deja un
 *   consentimiento (finalidad «registro de discapacidad», base legal consentimiento) y el dato cifrado.
 */
final class PersonalData
{
    /** Tipos de imagen aceptados para la foto. */
    public const PHOTO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Finalidad de tratamiento para la condición de discapacidad (RN-COL-08). */
    public const DISABILITY_PURPOSE = 'registro_discapacidad';

    /** Versión del texto de consentimiento (lang people.disability_consent_text). */
    public const CONSENT_VERSION = '2026-11';

    public function __construct(
        private readonly FileService $files,
        private readonly AuditLogger $audit,
    ) {}

    // ── Foto ─────────────────────────────────────────────────────────────────────────────────────────

    public function setPhoto(Person $person, string $contents, string $name, string $mime, ?string $userId): Person
    {
        if (! in_array($mime, self::PHOTO_MIMES, true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('people.photo_type')]]);
        }
        if (strlen($contents) > (int) config('files.logo_max_bytes', 1048576)) {
            throw ValidationException::withMessages(['file' => [(string) trans('people.photo_size')]]);
        }

        return DB::transaction(function () use ($person, $contents, $name, $mime, $userId): Person {
            $before = $person->photo_file_id;
            $file = $this->files->upload($contents, $name, $mime, $person->tenant_id, $userId, StoredFile::CLASSIFICATION_INTERNAL);
            $person->forceFill(['photo_file_id' => $file->id])->save();
            $this->audit->record('person.photo_changed', 'person', $person->id, ['photo_file_id' => $before], ['photo_file_id' => $file->id]);

            return $person->refresh();
        });
    }

    public function removePhoto(Person $person): Person
    {
        if ($person->photo_file_id === null) {
            return $person;
        }

        return DB::transaction(function () use ($person): Person {
            $before = $person->photo_file_id;
            $person->forceFill(['photo_file_id' => null])->save();
            $this->audit->record('person.photo_removed', 'person', $person->id, ['photo_file_id' => $before], ['photo_file_id' => null]);

            return $person->refresh();
        });
    }

    /**
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function photo(Person $person, ?string $userId): ?array
    {
        return $person->photo_file_id === null ? null : $this->files->contentsForDownload($person->photo_file_id, $person->tenant_id, $userId);
    }

    // ── Direcciones ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addresses(Person $person, bool $withHistory = false): array
    {
        $query = PersonAddress::query()->where('person_id', $person->id)->orderByDesc('is_current')->orderByDesc('valid_from')->orderByDesc('created_at');
        if (! $withHistory) {
            $query->where('is_current', true);
        }

        return $query->get()->map(fn (PersonAddress $a) => $this->presentAddress($a))->all();
    }

    /**
     * Registra una dirección vigente: la anterior del mismo tipo pasa a histórica (no se sobrescribe).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addAddress(Person $person, array $input): array
    {
        return DB::transaction(function () use ($person, $input): array {
            $kind = (string) ($input['kind'] ?? 'home');
            $previous = PersonAddress::query()->where('person_id', $person->id)->where('kind', $kind)->where('is_current', true)->lockForUpdate()->get();
            foreach ($previous as $old) {
                $old->forceFill(['is_current' => false])->save();
            }
            $address = PersonAddress::create([
                'person_id' => $person->id,
                'kind' => $kind,
                'address_enc' => FieldCipher::encrypt(trim((string) $input['address'])),
                'district_code' => $input['district_code'] ?? null,
                'country_code' => isset($input['country_code']) ? strtoupper((string) $input['country_code']) : 'PE',
                'valid_from' => $input['valid_from'] ?? now()->toDateString(),
                'is_current' => true,
            ]);
            $first = $previous->first();
            $this->audit->record('person.address_changed', 'person_address', $address->id,
                $first === null ? null : $this->auditableAddress($first), $this->auditableAddress($address));

            return $this->presentAddress($address);
        });
    }

    /**
     * Corrige una dirección (error de digitación): sí se edita, con auditoría del antes y el después.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateAddress(PersonAddress $address, array $input): array
    {
        $before = $this->auditableAddress($address);
        $values = [];
        if (array_key_exists('address', $input)) {
            $values['address_enc'] = FieldCipher::encrypt(trim((string) $input['address']));
        }
        foreach (['district_code', 'valid_from'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }
        if (array_key_exists('country_code', $input)) {
            $values['country_code'] = $input['country_code'] === null ? null : strtoupper((string) $input['country_code']);
        }

        return DB::transaction(function () use ($address, $values, $before): array {
            $address->forceFill($values)->save();
            $this->audit->record('person.address_corrected', 'person_address', $address->id, $before, $this->auditableAddress($address));

            return $this->presentAddress($address->refresh());
        });
    }

    public function deleteAddress(PersonAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $before = $this->auditableAddress($address);
            $address->delete();
            $this->audit->record('person.address_deleted', 'person_address', $address->id, $before, null);
        });
    }

    // ── Contactos de emergencia ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contacts(Person $person): array
    {
        return EmergencyContact::query()->where('person_id', $person->id)->orderBy('sort_order')->orderBy('created_at')->get()
            ->map(fn (EmergencyContact $c) => $this->presentContact($c))->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addContact(Person $person, array $input): array
    {
        return DB::transaction(function () use ($person, $input): array {
            $order = (int) (EmergencyContact::query()->where('person_id', $person->id)->max('sort_order') ?? 0) + 1;
            $contact = EmergencyContact::create([
                'person_id' => $person->id,
                'full_name' => trim((string) $input['full_name']),
                'relationship' => $input['relationship'] ?? null,
                'phone_enc' => FieldCipher::encrypt(trim((string) $input['phone'])),
                'sort_order' => $input['sort_order'] ?? $order,
            ]);
            $this->audit->record('person.emergency_contact_added', 'emergency_contact', $contact->id, null, $this->auditableContact($contact));

            return $this->presentContact($contact);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateContact(EmergencyContact $contact, array $input): array
    {
        $before = $this->auditableContact($contact);
        $values = [];
        if (array_key_exists('full_name', $input)) {
            $values['full_name'] = trim((string) $input['full_name']);
        }
        if (array_key_exists('relationship', $input)) {
            $values['relationship'] = $input['relationship'];
        }
        if (array_key_exists('phone', $input)) {
            $values['phone_enc'] = FieldCipher::encrypt(trim((string) $input['phone']));
        }
        if (array_key_exists('sort_order', $input)) {
            $values['sort_order'] = $input['sort_order'];
        }

        return DB::transaction(function () use ($contact, $values, $before): array {
            $contact->forceFill($values)->save();
            $this->audit->record('person.emergency_contact_updated', 'emergency_contact', $contact->id, $before, $this->auditableContact($contact));

            return $this->presentContact($contact->refresh());
        });
    }

    public function deleteContact(EmergencyContact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $before = $this->auditableContact($contact);
            $contact->delete();
            $this->audit->record('person.emergency_contact_deleted', 'emergency_contact', $contact->id, $before, null);
        });
    }

    // ── Datos sensibles con consentimiento (RN-COL-08) ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function sensitive(Person $person): array
    {
        $row = DB::table('person_sensitive_data')->where('person_id', $person->id)->first();
        $consent = $row?->consent_id === null ? null : DB::table('consents')->where('id', $row->consent_id)->first();

        $data = [
            'id' => $row === null ? null : (string) $row->id,
            'person_id' => $person->id,
            'consent' => $consent === null ? null : [
                'id' => (string) $consent->id, 'status' => (string) $consent->status, 'version' => (string) $consent->version,
                'channel' => (string) $consent->channel, 'granted_at' => Carbon::parse((string) $consent->granted_at)->toIso8601String(),
            ],
            'version' => $row === null ? 0 : (int) $row->lock_version,
        ];
        // Un dato vacío no viaja: así no se registra la lectura de algo que no existe.
        if ($row?->disability_enc !== null) {
            $data['disability'] = FieldCipher::decrypt((string) $row->disability_enc);
        }
        if ($row?->blood_type_enc !== null) {
            $data['blood_type'] = FieldCipher::decrypt((string) $row->blood_type_enc);
        }

        return $data;
    }

    /**
     * Registra (o borra, con null) la condición de discapacidad y el grupo sanguíneo. La discapacidad exige
     * un consentimiento expreso vigente: si no lo hay, el pedido debe traer consent.granted = true.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveSensitive(Person $person, array $input): array
    {
        $tenant = TenancyScope::requireTenant();

        return DB::transaction(function () use ($person, $input, $tenant): array {
            $row = DB::table('person_sensitive_data')->where('person_id', $person->id)->lockForUpdate()->first();
            $consentId = $row?->consent_id;
            $consentValid = $consentId !== null && DB::table('consents')->where('id', $consentId)->where('status', 'granted')->exists();
            $wantsDisability = array_key_exists('disability', $input) && $input['disability'] !== null && trim((string) $input['disability']) !== '';

            if ($wantsDisability && ! $consentValid) {
                $consent = $input['consent'] ?? null;
                if (! is_array($consent) || ($consent['granted'] ?? false) !== true) {
                    throw ValidationException::withMessages(['consent' => [(string) trans('people.disability_consent_required')]]);
                }
                $consentId = $this->grantConsent($person, $tenant, (string) ($consent['channel'] ?? 'paper'), isset($consent['evidence']) && is_string($consent['evidence']) ? $consent['evidence'] : null);
            }

            $values = ['consent_id' => $consentId];
            if (array_key_exists('disability', $input)) {
                $values['disability_enc'] = $wantsDisability ? FieldCipher::encrypt(trim((string) $input['disability'])) : null;
            }
            if (array_key_exists('blood_type', $input)) {
                $values['blood_type_enc'] = $input['blood_type'] === null || trim((string) $input['blood_type']) === '' ? null : FieldCipher::encrypt(trim((string) $input['blood_type']));
            }
            $before = $row === null ? null : ['disability' => $row->disability_enc === null ? null : '•', 'blood_type' => $row->blood_type_enc === null ? null : '•'];
            if ($row === null) {
                DB::table('person_sensitive_data')->insert($values + ['tenant_id' => $tenant, 'person_id' => $person->id]);
            } else {
                DB::table('person_sensitive_data')->where('id', $row->id)->update($values + ['lock_version' => (int) $row->lock_version + 1]);
            }
            $saved = DB::table('person_sensitive_data')->where('person_id', $person->id)->first();
            // La auditoría registra que cambió, nunca el valor de salud.
            $this->audit->record('person.sensitive_data_changed', 'person', $person->id, $before, [
                'disability' => $saved?->disability_enc === null ? null : '•', 'blood_type' => $saved?->blood_type_enc === null ? null : '•',
                'consent_id' => $consentId,
            ]);

            return $this->sensitive($person);
        });
    }

    private function grantConsent(Person $person, string $tenant, string $channel, ?string $evidence): string
    {
        $purpose = DB::table('processing_purposes')->where('code', self::DISABILITY_PURPOSE)->whereNull('deleted_at')->value('id');
        if ($purpose === null) {
            $purpose = DB::table('processing_purposes')->insertGetId([
                'tenant_id' => $tenant, 'code' => self::DISABILITY_PURPOSE, 'name' => (string) trans('people.disability_purpose_name'),
                'legal_basis' => 'consent', 'description' => (string) trans('people.disability_purpose_description'),
                'data_categories' => '{health}', 'requires_consent' => true,
            ]);
        }
        $text = (string) trans('people.disability_consent_text');

        return (string) DB::table('consents')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person->id, 'processing_purpose_id' => $purpose, 'version' => self::CONSENT_VERSION,
            'text_hash' => hash('sha256', $text), 'status' => 'granted', 'granted_at' => now(),
            'channel' => in_array($channel, ['portal', 'paper', 'kiosk'], true) ? $channel : 'paper',
            'evidence' => json_encode(['note' => $evidence, 'recorded_by_user_id' => request()->user()?->getAuthIdentifier()], JSON_THROW_ON_ERROR),
        ]);
    }

    // ── Presentación ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function presentAddress(PersonAddress $a): array
    {
        return [
            'id' => $a->id,
            'person_id' => $a->person_id,
            'kind' => $a->kind,
            'address' => FieldCipher::decrypt($a->address_enc),
            'district_code' => $a->district_code,
            'country_code' => $a->country_code,
            'valid_from' => $a->valid_from?->toDateString(),
            'is_current' => $a->is_current,
            'created_at' => $a->created_at->toIso8601String(),
            'updated_at' => $a->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentContact(EmergencyContact $c): array
    {
        return [
            'id' => $c->id,
            'person_id' => $c->person_id,
            'full_name' => $c->full_name,
            'relationship' => $c->relationship,
            'phone' => FieldCipher::decrypt($c->phone_enc),
            'sort_order' => $c->sort_order,
            'created_at' => $c->created_at->toIso8601String(),
            'updated_at' => $c->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableAddress(PersonAddress $a): array
    {
        return ['kind' => $a->kind, 'address' => FieldCipher::decrypt($a->address_enc), 'district_code' => $a->district_code,
            'country_code' => $a->country_code, 'valid_from' => $a->valid_from?->toDateString(), 'is_current' => $a->is_current];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableContact(EmergencyContact $c): array
    {
        return ['full_name' => $c->full_name, 'relationship' => $c->relationship, 'phone' => FieldCipher::decrypt($c->phone_enc), 'sort_order' => $c->sort_order];
    }
}
