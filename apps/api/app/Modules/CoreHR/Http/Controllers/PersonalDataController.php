<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\PersonalData;
use App\Modules\CoreHR\Http\Controllers\Concerns\ScopedAccess;
use App\Modules\CoreHR\Infrastructure\Models\EmergencyContact;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\PersonAddress;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * COL-4 · Datos personales de la persona: foto, direcciones, contactos de emergencia y datos sensibles.
 *
 * - Direcciones y contactos de emergencia son datos personales: verlos exige
 *   people.people.personal_data.view y editarlos people.people.personal_data.edit; cada lectura queda en el
 *   registro de accesos (regla 7).
 * - La condición de discapacidad y el grupo sanguíneo exigen people.people.health_data.view para leerlos y,
 *   además, people.people.personal_data.edit para registrarlos, siempre con consentimiento (RN-COL-08).
 */
final class PersonalDataController
{
    use ScopedAccess;

    private const VIEW = 'people.people.personal_data.view';

    private const EDIT = 'people.people.personal_data.edit';

    private const HEALTH = 'people.people.health_data.view';

    public function __construct(
        private readonly PersonalData $personal,
        private readonly SensitiveFields $sensitive,
        private readonly DataScope $scope,
    ) {}

    // ── Foto ─────────────────────────────────────────────────────────────────────────────────────────

    public function photo(Request $request, string $id): Response
    {
        $person = $this->findPerson($request, $id, 'people.people.view');
        $photo = $this->personal->photo($person, $this->userId($request));
        abort_if($photo === null, 404);

        return response($photo['contents'], 200, [
            'Content-Type' => $photo['mime'],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $person = $this->personal->setPhoto($person, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(), $this->userId($request));

        return $this->photoState($person);
    }

    public function removePhoto(Request $request, string $id): JsonResponse
    {
        return $this->photoState($this->personal->removePhoto($this->findPerson($request, $id, 'people.people.update')));
    }

    // ── Direcciones ──────────────────────────────────────────────────────────────────────────────────

    public function addresses(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.view');
        $this->require($request, self::VIEW);
        $rows = $this->personal->addresses($person, $request->boolean('history'));

        return response()->json(['data' => $this->sensitive->presentMany('PersonAddress', $rows, $this->permissions($request), 'api', array_fill(0, count($rows), $person->id))]);
    }

    public function storeAddress(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $input = $request->validate($this->addressRules(true));

        return response()->json(['data' => $this->present('PersonAddress', $this->personal->addAddress($person, $input), $request, $person)], 201);
    }

    public function updateAddress(Request $request, string $id, string $addressId): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $address = $this->address($person, $addressId);
        $input = $request->validate($this->addressRules(false));

        return response()->json(['data' => $this->present('PersonAddress', $this->personal->updateAddress($address, $input), $request, $person)]);
    }

    public function deleteAddress(Request $request, string $id, string $addressId): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $address = $this->address($person, $addressId);
        $this->personal->deleteAddress($address);

        return response()->json(['data' => ['id' => $address->id, 'deleted' => true]]);
    }

    // ── Contactos de emergencia ──────────────────────────────────────────────────────────────────────

    public function contacts(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.view');
        $this->require($request, self::VIEW);
        $rows = $this->personal->contacts($person);

        return response()->json(['data' => $this->sensitive->presentMany('EmergencyContact', $rows, $this->permissions($request), 'api', array_fill(0, count($rows), $person->id))]);
    }

    public function storeContact(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $input = $request->validate($this->contactRules(true));

        return response()->json(['data' => $this->present('EmergencyContact', $this->personal->addContact($person, $input), $request, $person)], 201);
    }

    public function updateContact(Request $request, string $id, string $contactId): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $contact = $this->contact($person, $contactId);
        $input = $request->validate($this->contactRules(false));

        return response()->json(['data' => $this->present('EmergencyContact', $this->personal->updateContact($contact, $input), $request, $person)]);
    }

    public function deleteContact(Request $request, string $id, string $contactId): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::EDIT);
        $contact = $this->contact($person, $contactId);
        $this->personal->deleteContact($contact);

        return response()->json(['data' => ['id' => $contact->id, 'deleted' => true]]);
    }

    // ── Datos sensibles (RN-COL-08) ──────────────────────────────────────────────────────────────────

    public function sensitiveData(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.view');
        $this->require($request, self::HEALTH);

        return response()->json(['data' => $this->present('PersonSensitiveData', $this->personal->sensitive($person), $request, $person)]);
    }

    public function saveSensitiveData(Request $request, string $id): JsonResponse
    {
        $person = $this->findPerson($request, $id, 'people.people.update');
        $this->require($request, self::HEALTH);
        $this->require($request, self::EDIT);
        $input = $request->validate([
            'disability' => ['sometimes', 'nullable', 'string', 'max:500'],
            'blood_type' => ['sometimes', 'nullable', Rule::in(['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'])],
            'consent' => ['sometimes', 'array'],
            'consent.granted' => ['sometimes', 'boolean'],
            'consent.channel' => ['sometimes', Rule::in(['portal', 'paper', 'kiosk'])],
            'consent.evidence' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->present('PersonSensitiveData', $this->personal->saveSensitive($person, $input), $request, $person)]);
    }

    // ── Apoyo ────────────────────────────────────────────────────────────────────────────────────────

    /** Lo que la interfaz oculta, el backend lo valida (regla 10): sin el permiso de campo, 403. */
    private function require(Request $request, string $permission): void
    {
        if (! $this->permissions($request)->has($permission)) {
            throw new AyniException('forbidden', 403, (string) trans('people.field_permission_required'));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(string $schema, array $row, Request $request, Person $person): array
    {
        return $this->sensitive->present($schema, $row, $this->permissions($request), 'api', $person->id);
    }

    private function photoState(Person $person): JsonResponse
    {
        return response()->json(['data' => ['person_id' => $person->id, 'has_photo' => $person->photo_file_id !== null]]);
    }

    private function address(Person $person, string $id): PersonAddress
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $address = PersonAddress::query()->where('person_id', $person->id)->whereKey($id)->first();
        abort_unless($address instanceof PersonAddress, 404);

        return $address;
    }

    private function contact(Person $person, string $id): EmergencyContact
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $contact = EmergencyContact::query()->where('person_id', $person->id)->whereKey($id)->first();
        abort_unless($contact instanceof EmergencyContact, 404);

        return $contact;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressRules(bool $creating): array
    {
        return [
            'kind' => ['sometimes', Rule::in(['home', 'mailing', 'other'])],
            'address' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:300'],
            'district_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$req, 'string', 'min:2', 'max:150'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:60'],
            'phone' => [$req, 'string', 'regex:/^[0-9+() \-]{6,30}$/'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
