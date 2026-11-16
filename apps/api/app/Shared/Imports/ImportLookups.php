<?php

namespace App\Shared\Imports;

use App\Shared\Security\BlindIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-2 · Resuelve los códigos que trae un archivo (RUC de la empresa, código de unidad, puesto,
 * posición, sede, centro de costo, tipo de contrato) a los registros de la organización. Busca solo
 * registros vigentes y no borrados; devuelve null si el código no existe (es error de la fila:
 * RN-IMP-03). La consulta corre con el contexto de organización (RLS).
 */
final class ImportLookups
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function legalEntity(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->remember('le:'.$value, fn () => DB::table('legal_entities')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('tax_id', preg_replace('/\s+/', '', $value))->orWhereRaw('lower(legal_name) = lower(?)', [$value]))
            ->value('id'));
    }

    public function byCode(string $table, string $value, ?string $legalEntityId = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->remember("{$table}:{$legalEntityId}:".mb_strtoupper($value), fn () => DB::table($table)->whereNull('deleted_at')
            ->whereRaw('upper(code) = ?', [mb_strtoupper($value)])
            ->when($legalEntityId !== null && $this->hasEntity($table), fn ($q) => $q->where(fn ($w) => $w->where('legal_entity_id', $legalEntityId)->orWhereNull('legal_entity_id')))
            ->value('id'));
    }

    /** Código o nombre (sedes, tipos de unidad, tipos de contrato). */
    public function byCodeOrName(string $table, string $value, ?string $legalEntityId = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $id = $this->byCode($table, $value, $legalEntityId);

        return $id ?? $this->remember("{$table}:name:{$legalEntityId}:".mb_strtolower($value), fn () => DB::table($table)->whereNull('deleted_at')
            ->whereRaw('lower(name) = lower(?)', [$value])
            ->when($legalEntityId !== null && $this->hasEntity($table), fn ($q) => $q->where(fn ($w) => $w->where('legal_entity_id', $legalEntityId)->orWhereNull('legal_entity_id')))
            ->value('id'));
    }

    /**
     * Relación laboral abierta (o la última) de la persona con ese documento en la empresa.
     */
    public function employment(string $documentType, string $documentNumber, string $legalEntityId): ?string
    {
        $number = self::normalizeDocument($documentNumber);
        if ($number === '') {
            return null;
        }
        // Mismo índice ciego que CoreHR (IdentityDocuments::hash): el número nunca se busca en claro.
        $hash = BlindIndex::for('national_id', (mb_strtolower(trim($documentType)) ?: 'dni').':'.$number);

        return $this->remember("emp:{$legalEntityId}:{$hash}", fn () => DB::table('employments as e')
            ->join('person_documents as d', 'd.person_id', '=', 'e.person_id')
            ->where('d.number_hash', $hash)->whereNull('d.deleted_at')->where('e.legal_entity_id', $legalEntityId)->whereNull('e.deleted_at')
            ->orderByRaw("case when e.status in ('active','pending','suspended','terminating') then 0 else 1 end")
            ->orderByDesc('e.hire_date')->value('e.id'));
    }

    /** @var array<string, bool> */
    private array $entityColumn = [];

    /** Solo las tablas con empresa (sedes, centros de costo, posiciones, unidades) se filtran por ella. */
    private function hasEntity(string $table): bool
    {
        return $this->entityColumn[$table] ??= Schema::hasColumn($table, 'legal_entity_id');
    }

    public static function normalizeDocument(string $number): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($number)));
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @param  callable(): mixed  $query
     */
    private function remember(string $key, callable $query): ?string
    {
        // Solo se recuerda lo encontrado: un código que falta puede crearse en una fila anterior del mismo archivo.
        if (! isset($this->cache[$key])) {
            $value = $query();
            if (! is_string($value)) {
                return null;
            }
            $this->cache[$key] = $value;
        }

        return $this->cache[$key];
    }
}
