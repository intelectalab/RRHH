<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Shared\Security\FieldCipher;
use Illuminate\Support\Facades\DB;

/**
 * DEC-2 · DEC-3 · DEC-4 · Datos de la ficha que alimentan los archivos oficiales: documento principal de la persona
 * (descifrado solo para escribir el archivo), nombres, perfil del registro laboral (COL-6) y afiliación pensionaria
 * (COL-7) vigentes a una fecha, y los códigos del paquete de país (packages/country-pe/data/declaraciones.php).
 */
final class DeclarationSources
{
    /** @var array<string, mixed>|null */
    private ?array $pack = null;

    /** @return array<string, mixed> */
    public function pack(): array
    {
        if ($this->pack === null) {
            $file = base_path('packages/country-pe/data/declaraciones.php');
            /** @var array<string, mixed> $data */
            $data = is_file($file) ? require $file : [];
            $this->pack = $data;
        }

        return $this->pack;
    }

    public function code(string $map, ?string $key): ?string
    {
        $values = $this->pack()[$map] ?? [];

        return is_array($values) && $key !== null && isset($values[$key]) ? (string) $values[$key] : null;
    }

    /**
     * Fila base de una relación laboral: documento, persona y datos de la relación.
     *
     * @return array<string, mixed>
     */
    public function worker(string $employmentId): array
    {
        $e = DB::table('employments AS e')->join('people AS p', 'p.id', '=', 'e.person_id')->where('e.id', $employmentId)
            ->first(['e.id', 'e.person_id', 'e.employee_code', 'e.hire_date', 'e.termination_date', 'p.first_names', 'p.paternal_surname',
                'p.maternal_surname', 'p.birth_date', 'p.sex', 'p.nationality_code']);
        if ($e === null) {
            return [];
        }
        $doc = DB::table('person_documents')->where('person_id', $e->person_id)->whereNull('deleted_at')
            ->orderByDesc('is_primary')->orderBy('created_at')->first(['doc_type', 'number_enc', 'issuing_country']);
        $number = $doc === null ? null : FieldCipher::decrypt((string) $doc->number_enc);
        $docType = $doc === null ? null : (string) $doc->doc_type;

        return [
            '_ref' => (string) $e->id,
            '_name' => trim($e->paternal_surname.' '.($e->maternal_surname ?? '').' '.$e->first_names),
            '_employee_code' => $e->employee_code === null ? null : (string) $e->employee_code,
            '_hire_date' => substr((string) $e->hire_date, 0, 10),
            '_termination_date' => $e->termination_date === null ? null : substr((string) $e->termination_date, 0, 10),
            'document.type_code' => $this->code('documento_tabla3', $docType),
            'document.afpnet_code' => $this->code('documento_afpnet', $docType),
            'document.number' => $number,
            'document.issuing_country' => $doc?->issuing_country === null ? null : (string) $doc->issuing_country,
            'person.first_names' => (string) $e->first_names,
            'person.paternal_surname' => (string) $e->paternal_surname,
            'person.maternal_surname' => $e->maternal_surname === null ? null : (string) $e->maternal_surname,
            'person.birth_date' => $e->birth_date === null ? null : substr((string) $e->birth_date, 0, 10),
            'person.sex_code' => $this->code('sexo_tabla', $e->sex === null ? null : (string) $e->sex),
            'person.nationality_code' => $e->nationality_code === null ? null : (string) $e->nationality_code,
        ];
    }

    /**
     * Perfil del registro laboral vigente a la fecha (null si no hay).
     *
     * @return array{values: array<string, mixed>, ready: bool, missing: list<string>}|null
     */
    public function profile(string $employmentId, string $on): ?array
    {
        $p = DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId)->where('valid_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))->orderByDesc('valid_from')->first();
        if ($p === null) {
            return null;
        }
        $values = [];
        foreach ((array) $p as $k => $v) {
            $values['profile.'.$k] = is_bool($v) ? ($v ? '1' : '0') : $v;
        }
        $values['profile.is_tax_resident'] = $p->is_tax_resident ? '1' : '2';
        $values['profile.sctr_pension'] = match ((string) $p->sctr_pension) {
            'onp' => '1', 'insurer' => '2', default => '0'
        };
        $values['profile.asegura_pension'] = '0';
        $values['profile.artist_fund'] = null;
        $missing = $p->missing_fields === null ? [] : array_values(array_filter(explode(',', trim((string) $p->missing_fields, '{}'))));

        return ['values' => $values, 'ready' => (string) $p->completeness_status === 'ready', 'missing' => $missing];
    }

    /**
     * Afiliación pensionaria vigente a la fecha.
     *
     * @return array{system: string, fund_code: string|null, afpnet_code: string|null, cuspp: string|null}|null
     */
    public function pension(string $employmentId, string $on): ?array
    {
        $a = DB::table('employment_pension_affiliations')->where('employment_id', $employmentId)->where('valid_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))->orderByDesc('valid_from')->first();
        if ($a === null) {
            return null;
        }
        $fund = $a->pension_fund_code === null ? null : (string) $a->pension_fund_code;
        $afpnet = $fund === null ? null : DB::table('pension_funds')->where('code', $fund)->value('afpnet_code');

        return [
            'system' => (string) $a->pension_system, 'fund_code' => $fund, 'afpnet_code' => is_string($afpnet) ? $afpnet : null,
            'cuspp' => $a->affiliate_code_enc === null ? null : FieldCipher::decrypt((string) $a->affiliate_code_enc),
        ];
    }
}
