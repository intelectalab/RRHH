<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Infrastructure\Payments\TemplateBankFile;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * DEC-5 · Plantillas de archivo bancario: las del paquete de país (packages/country-pe/data/formatos_bancarios.php,
 * de solo lectura) y las propias de la organización (bank_file_templates). Una plantilla activa es un formato más
 * del puerto BankFileFormat de PER-10 (ADR 0020).
 */
final class BankFileTemplates
{
    private const SECTIONS = ['header', 'detail', 'trailer'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, array<string, mixed>> */
    private function pack(): array
    {
        $file = base_path('packages/country-pe/data/formatos_bancarios.php');
        /** @var array<string, array<string, mixed>> $data */
        $data = is_file($file) ? require $file : [];

        return $data;
    }

    public function format(string $code): ?TemplateBankFile
    {
        $own = DB::table('bank_file_templates')->where('code', $code)->whereNull('deleted_at')->where('status', 'active')->value('definition');
        if (is_string($own)) {
            return new TemplateBankFile($code, (array) json_decode($own, true));
        }
        $pack = $this->pack()[$code] ?? null;

        return $pack === null ? null : new TemplateBankFile($code, (array) $pack['definition']);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $out = [];
        foreach ($this->pack() as $code => $t) {
            $out[] = ['id' => null, 'code' => $code, 'bank_code' => $t['bank_code'], 'name' => $t['name'], 'kind' => $t['kind'], 'definition' => $t['definition'],
                'verified' => (bool) $t['verified'], 'status' => 'active', 'source' => $t['source'], 'origin' => 'pack'];
        }
        foreach (DB::table('bank_file_templates')->whereNull('deleted_at')->orderBy('code')->get() as $r) {
            $out[] = $this->present($r);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(\stdClass $r): array
    {
        return ['id' => (string) $r->id, 'code' => (string) $r->code, 'bank_code' => (string) $r->bank_code, 'name' => (string) $r->name, 'kind' => (string) $r->kind,
            'definition' => json_decode((string) $r->definition, true), 'verified' => (bool) $r->verified, 'status' => (string) $r->status,
            'source' => $r->source === null ? null : (string) $r->source, 'origin' => 'organization', 'lock_version' => (int) $r->lock_version];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->check((array) $data['definition']);
        if (isset($this->pack()[$data['code']]) || DB::table('bank_file_templates')->where('code', $data['code'])->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.template_code_taken'),
                [['field' => 'code', 'rule' => 'unique', 'message' => (string) trans('payroll.declarations.template_code_taken')]]);
        }

        return DB::transaction(function () use ($data): array {
            $id = (string) DB::table('bank_file_templates')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'code' => $data['code'], 'bank_code' => $data['bank_code'], 'name' => $data['name'],
                'kind' => $data['kind'] ?? 'salary', 'definition' => json_encode($data['definition']), 'verified' => (bool) ($data['verified'] ?? false),
                'source' => $data['source'] ?? null,
            ]);
            $this->audit->record('bank_file_template.created', 'bank_file_template', $id, null, ['code' => $data['code']]);

            return $this->show($id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $r = DB::table('bank_file_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }
        if (isset($data['lock_version']) && (int) $data['lock_version'] !== (int) $r->lock_version) {
            throw new AyniException('conflict', 409, (string) trans('payroll.declarations.template_conflict'));
        }
        if (isset($data['definition'])) {
            $this->check((array) $data['definition']);
        }
        $changes = array_filter([
            'name' => $data['name'] ?? null, 'bank_code' => $data['bank_code'] ?? null, 'kind' => $data['kind'] ?? null, 'status' => $data['status'] ?? null,
            'definition' => isset($data['definition']) ? json_encode($data['definition']) : null, 'source' => $data['source'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('verified', $data)) {
            $changes['verified'] = (bool) $data['verified'];
        }

        return DB::transaction(function () use ($id, $r, $changes): array {
            DB::table('bank_file_templates')->where('id', $id)->update($changes);
            $this->audit->record('bank_file_template.updated', 'bank_file_template', $id, ['status' => (string) $r->status, 'verified' => (bool) $r->verified],
                array_diff_key($changes, ['definition' => true]));

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $r = DB::table('bank_file_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $this->present($r);
    }

    /**
     * La definición debe tener detalle y campos con fuente o valor; si no, 422 con el campo que falla.
     *
     * @param  array<mixed>  $definition
     */
    private function check(array $definition): void
    {
        $errors = [];
        if (! isset($definition['detail']) || ! is_array($definition['detail']) || $definition['detail'] === []) {
            $errors[] = ['field' => 'definition.detail', 'rule' => 'required', 'message' => (string) trans('payroll.declarations.template_invalid')];
        }
        foreach (self::SECTIONS as $section) {
            foreach ((array) ($definition[$section] ?? []) as $i => $f) {
                $f = (array) $f;
                if (! array_key_exists('value', $f) && ! (isset($f['source']) && preg_match('/^(header|item)\.[a-z_]+$/', (string) $f['source']) === 1)) {
                    $errors[] = ['field' => "definition.$section.$i", 'rule' => 'source', 'message' => (string) trans('payroll.declarations.template_invalid')];
                }
            }
        }
        $pattern = $definition['account_rules']['account_number_pattern'] ?? null;
        if (is_string($pattern) && @preg_match($pattern, '') === false) {
            $errors[] = ['field' => 'definition.account_rules.account_number_pattern', 'rule' => 'regex', 'message' => (string) trans('payroll.declarations.template_invalid')];
        }
        if ($errors !== []) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.template_invalid'), $errors);
        }
    }
}
