<?php

namespace App\Shared\Imports;

use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\CustomFields\HasCustomFields;
use Illuminate\Validation\ValidationException;

/**
 * CFG-5 · Envuelve a un importador cuyos registros admiten campos personalizados: suma una columna
 * custom.clave por campo, interpreta y valida cada celda con el tipo del campo (las mismas reglas que
 * el formulario), guarda los valores junto con el registro y, al deshacer, los quita. Un campo
 * sensible exige su permiso por campo para importarse (RN-IMP-04). El módulo no programa nada.
 */
final class CustomFieldsImporter implements Importer
{
    private const KEY = '__custom_fields';

    public function __construct(
        private readonly Importer $inner,
        private readonly string $customEntity,
    ) {}

    public static function wrap(Importer $importer): Importer
    {
        return $importer instanceof HasCustomFields ? new self($importer, $importer->customFieldEntity()) : $importer;
    }

    public function entity(): string
    {
        return $this->inner->entity();
    }

    public function permission(): string
    {
        return $this->inner->permission();
    }

    public function columns(): array
    {
        $columns = $this->inner->columns();
        foreach ($this->fields()->definitions($this->customEntity) as $d) {
            $columns[] = new ImportColumn('custom.'.$d->key, false, '', [(string) $d->label, (string) $d->key],
                $d->is_sensitive ? (string) ($d->visibility_permission_code ?? 'custom_fields.sensitive') : null);
        }

        return $columns;
    }

    public function naturalKey(array $data): ?string
    {
        return $this->inner->naturalKey($this->native($data));
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $check = $this->inner->validate($this->native($data), $context);
        $input = [];
        $types = $this->fields()->definitions($this->customEntity)->keyBy('key');
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'custom.') && trim($value) !== '') {
                $k = substr($key, 7);
                $input[$k] = CustomFieldValues::fromText((string) ($types->get($k)->data_type ?? 'text'), $value);
            }
        }
        if ($input === []) {
            return $check;
        }
        try {
            $clean = $this->fields()->validate($this->customEntity, $input, false, $context->permissions());
            if ($check->ok()) {
                $check->parsed[self::KEY] = $clean;
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $check->error(str_replace('custom_fields.', 'custom.', (string) $field), 'invalid', (string) ($messages[0] ?? ''));
            }
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $custom = (array) ($parsed[self::KEY] ?? []);
        unset($parsed[self::KEY]);
        $result = $this->inner->import($parsed, $context);
        if ($custom !== []) {
            $previous = $result['previous'] === null ? null
                : $result['previous'] + [self::KEY => array_intersect_key($this->fields()->read($this->customEntity, $result['entity_id'], $context->permissions(), 'import'), $custom)];
            $this->fields()->save($this->customEntity, $result['entity_id'], $custom);
            $result['previous'] = $previous;
        }

        return $result;
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        return $this->inner->blockingReason($entityId, $context);
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $custom = $previous === null ? null : ($previous[self::KEY] ?? null);
        if (is_array($previous)) {
            unset($previous[self::KEY]);
        }
        $this->inner->revert($entityId, $previous, $context);
        if (is_array($custom)) {
            // Una fila que actualizó un registro vuelve a sus valores anteriores; una que lo creó lo deja en la papelera con los suyos.
            $this->fields()->save($this->customEntity, $entityId, $custom);
        }
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function native(array $data): array
    {
        return array_filter($data, fn ($k) => ! str_starts_with((string) $k, 'custom.'), ARRAY_FILTER_USE_KEY);
    }

    private function fields(): CustomFieldValues
    {
        return app(CustomFieldValues::class);
    }
}
