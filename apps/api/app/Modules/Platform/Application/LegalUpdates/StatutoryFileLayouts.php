<?php

namespace App\Modules\Platform\Application\LegalUpdates;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DEC-10 · Estructuras de archivos oficiales versionadas como datos. Una estructura nueva (PLAME, T-Registro, AFPnet u
 * otra) se carga desde la consola sin programar: queda con vigencia (cierra la versión anterior el día previo) y sin
 * verificar, así el generador solo produce borradores con ella hasta que se verifique contra la herramienta
 * oficial (queda en la auditoría de la consola). Las estructuras verificadas tienen su archivo dorado en
 * tests/Golden/Declarations.
 */
final class StatutoryFileLayouts
{
    /** Tipos que entiende FileSerializer. */
    private const TYPES = ['text', 'number', 'date', 'fixed_number'];

    public function __construct(private readonly ConsoleAudit $audit) {}

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function load(array $in, PlatformUser $actor): array
    {
        $errors = [];
        foreach (['filing_kind', 'structure_code', 'name', 'file_name_pattern', 'layout_version', 'valid_from', 'source'] as $k) {
            if (! isset($in[$k]) || ! is_string($in[$k]) || trim($in[$k]) === '') {
                $errors[$k] = [(string) trans('validation.required', ['attribute' => $k])];
            }
        }
        $fields = $in['fields'] ?? null;
        if (! is_array($fields) || $fields === [] || ! array_is_list($fields)) {
            $errors['fields'] = [(string) trans('validation.required', ['attribute' => 'fields'])];
        } else {
            foreach ($fields as $i => $f) {
                if (! is_array($f) || ! isset($f['name'], $f['source']) || ! is_string($f['name']) || ! in_array($f['type'] ?? null, self::TYPES, true)
                    || (isset($f['max_len']) && (! is_int($f['max_len']) || $f['max_len'] < 1))) {
                    $errors["fields.{$i}"] = [(string) trans('validation.in', ['attribute' => "fields.{$i}"])];
                }
            }
        }
        if (isset($in['valid_from']) && is_string($in['valid_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['valid_from']) !== 1) {
            $errors['valid_from'] = [(string) trans('validation.date', ['attribute' => 'valid_from'])];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $country = strtoupper((string) ($in['country'] ?? 'PE'));
        $key = DB::table('statutory_file_layouts')->where('country_code', $country)->where('filing_kind', $in['filing_kind'])->where('structure_code', $in['structure_code']);
        if ((clone $key)->where(fn ($q) => $q->where('layout_version', $in['layout_version'])->orWhere('valid_from', '>=', $in['valid_from']))->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('payroll.file_layouts.duplicate_version'));
        }

        $row = DB::transaction(function () use ($in, $country, $key, $fields): array {
            (clone $key)->whereNull('valid_to')->update(['valid_to' => CarbonImmutable::parse((string) $in['valid_from'])->subDay()->toDateString()]);
            $id = (string) DB::table('statutory_file_layouts')->insertGetId([
                'country_code' => $country, 'filing_kind' => $in['filing_kind'], 'structure_code' => $in['structure_code'], 'name' => $in['name'],
                'file_name_pattern' => $in['file_name_pattern'], 'file_extension' => $in['file_extension'] ?? null, 'layout_version' => $in['layout_version'],
                'field_separator' => $in['field_separator'] ?? null, 'encoding' => $in['encoding'] ?? 'ISO-8859-1', 'line_ending' => $in['line_ending'] ?? 'CRLF',
                'has_header' => (bool) ($in['has_header'] ?? false), 'trailing_separator' => (bool) ($in['trailing_separator'] ?? true),
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'source' => $in['source'], 'verified' => false, 'valid_from' => $in['valid_from'],
            ]);

            return $this->show($id);
        });
        $this->audit->record('statutory_file_layout.loaded', $actor, 'statutory_file_layout', (string) $row['id'], null,
            ['filing_kind' => $row['filing_kind'], 'structure_code' => $row['structure_code'], 'layout_version' => $row['layout_version'], 'valid_from' => $row['valid_from']]);

        return $row;
    }

    /** @return array<string, mixed> */
    public function verify(string $id, PlatformUser $actor): array
    {
        $row = $this->show($id);
        if ($row['verified'] === true) {
            throw new AyniException('conflict', 409, (string) trans('payroll.file_layouts.already_verified'));
        }
        DB::table('statutory_file_layouts')->where('id', $id)->update(['verified' => true]);
        $this->audit->record('statutory_file_layout.verified', $actor, 'statutory_file_layout', $id, ['verified' => false], ['verified' => true]);

        return $this->show($id);
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $r = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('statutory_file_layouts')->where('id', $id)->first() : null;
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return [
            'id' => (string) $r->id, 'country' => (string) $r->country_code, 'filing_kind' => (string) $r->filing_kind, 'structure_code' => (string) $r->structure_code,
            'name' => (string) $r->name, 'file_name_pattern' => (string) $r->file_name_pattern, 'file_extension' => $r->file_extension,
            'layout_version' => (string) $r->layout_version, 'field_separator' => $r->field_separator, 'encoding' => (string) $r->encoding,
            'line_ending' => (string) $r->line_ending, 'fields' => json_decode((string) $r->fields, true), 'source' => $r->source, 'verified' => (bool) $r->verified,
            'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10),
        ];
    }
}
