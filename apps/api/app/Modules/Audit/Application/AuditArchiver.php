<?php

namespace App\Modules\Audit\Application;

use App\Shared\Files\ColdArchiveStorage;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AUD-5 · Archivado en frío de la auditoría (RN-AUD-06: se archiva, nunca se borra antes del plazo).
 *
 * 1. archiveDue(): por organización, los meses anteriores al corte en línea pasan a un archivo JSONL
 *    en el disco frío, con su manifiesto (rango de la cadena, huella sha256 y plazo de consulta).
 * 2. dropArchivedPartitions(): una partición mensual se retira de la base solo cuando todas las
 *    organizaciones con entradas en ese mes ya la archivaron con el mismo número de filas.
 * 3. purgeExpired(): al vencer el plazo de conservación se borra el archivo frío y se marca el manifiesto.
 */
final class AuditArchiver
{
    public function __construct(
        private readonly RetentionPolicies $policies,
        private readonly ColdArchiveStorage $storage,
        private readonly CrossTenantMaintenance $maintenance,
    ) {}

    /**
     * @return int meses archivados de la organización activa
     */
    public function archiveDue(): int
    {
        TenancyScope::requireTenant();
        $cutoff = $this->policies->hotCutoff();
        $months = DB::table('audit_events')->where('occurred_at', '<', $cutoff->toIso8601String())
            ->selectRaw("to_char(date_trunc('month', occurred_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS m")
            ->groupBy('m')->orderBy('m')->pluck('m')->map(fn ($m) => (string) $m)->all();
        $done = DB::table('audit_archives')->pluck('period_month')->map(fn ($d) => substr((string) $d, 0, 10))->all();

        $archived = 0;
        foreach (array_diff($months, $done) as $month) {
            $this->archiveMonth(CarbonImmutable::parse($month, 'UTC'));
            $archived++;
        }

        return $archived;
    }

    /**
     * @return int archivos fríos borrados por vencer su plazo (organización activa)
     */
    public function purgeExpired(): int
    {
        TenancyScope::requireTenant();
        $expired = DB::table('audit_archives')->whereNull('purged_at')
            ->where('purge_after', '<', CarbonImmutable::now('UTC')->toDateString())->get();
        foreach ($expired as $archive) {
            $this->storage->delete((string) $archive->storage_disk, (string) $archive->storage_key);
            // El rol de la aplicación no reescribe manifiestos: lo hace la tarea con el rol propietario.
            $this->maintenance->asOwner(fn () => DB::table('audit_archives')->where('id', $archive->id)->update(['purged_at' => now(), 'updated_at' => now()]));
        }

        return $expired->count();
    }

    /**
     * Retira de la base las particiones mensuales ya archivadas por todas las organizaciones.
     * Se ejecuta sin organización activa (tarea de plataforma).
     *
     * @return array<int, string> particiones retiradas
     */
    public function dropArchivedPartitions(): array
    {
        if (TenancyScope::current() !== null) {
            throw new RuntimeException('El retiro de particiones de auditoría es una tarea de plataforma.');
        }
        $cutoff = $this->policies->hotCutoff();
        $dropped = [];
        foreach ($this->maintenance->monthlyPartitions('audit_events') as $name => $from) {
            if ($from->addMonthNoOverflow()->greaterThan($cutoff) || ! $this->fullyArchived($from)) {
                continue;
            }
            $this->maintenance->dropPartition('audit_events', $name);
            $dropped[] = $name;
        }

        return $dropped;
    }

    private function archiveMonth(CarbonImmutable $month): void
    {
        $tenantId = TenancyScope::requireTenant();
        $to = $month->addMonthNoOverflow();
        $disk = $this->storage->disk();
        $key = sprintf('%s/%s/%s.jsonl', trim((string) config('audit.archive_prefix', 'audit-archives'), '/'), $tenantId, $month->format('Y-m'));

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo preparar el archivo de auditoría.');
        }
        $hash = hash_init('sha256');
        $count = 0;
        $size = 0;
        $fromSeq = $toSeq = null;
        $firstPrev = $lastHash = null;
        $rows = DB::table('audit_events')->where('occurred_at', '>=', $month->toIso8601String())->where('occurred_at', '<', $to->toIso8601String())
            ->orderByRaw('chain_seq NULLS LAST')->orderBy('occurred_at')->orderBy('id')->cursor();
        foreach ($rows as $row) {
            $line = json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            fwrite($stream, $line);
            hash_update($hash, $line);
            $size += strlen($line);
            $count++;
            if ($row->chain_seq !== null) {
                $seq = (int) $row->chain_seq;
                if ($fromSeq === null) {
                    $fromSeq = $seq;
                    $firstPrev = $row->prev_hash;
                }
                $toSeq = $seq;
                $lastHash = $row->row_hash;
            }
        }
        rewind($stream);
        $this->storage->put($disk, $key, $stream);
        fclose($stream);

        DB::table('audit_archives')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'period_month' => $month->toDateString(),
            'from_seq' => $fromSeq,
            'to_seq' => $toSeq,
            'row_count' => $count,
            'first_prev_hash' => $firstPrev,
            'last_hash' => $lastHash,
            'storage_disk' => $disk,
            'storage_key' => $key,
            'sha256' => hash_final($hash),
            'size_bytes' => $size,
            'archived_at' => now(),
            'purge_after' => $to->addYears($this->policies->auditYears())->toDateString(),
        ]);
    }

    /** ¿Cada organización con entradas en el mes las archivó todas? (lectura entre organizaciones). */
    private function fullyArchived(CarbonImmutable $from): bool
    {
        [$counts, $archived] = $this->maintenance->readAcrossTenants(fn (): array => [
            DB::table('audit_events')->where('occurred_at', '>=', $from->toIso8601String())
                ->where('occurred_at', '<', $from->addMonthNoOverflow()->toIso8601String())
                ->groupBy('tenant_id')->selectRaw('tenant_id, count(*) AS n')->pluck('n', 'tenant_id')->all(),
            DB::table('audit_archives')->where('period_month', $from->toDateString())->pluck('row_count', 'tenant_id')->all(),
        ]);
        foreach ($counts as $tenantId => $n) {
            if ((int) ($archived[$tenantId] ?? -1) !== (int) $n) {
                return false;
            }
        }

        return true;
    }
}
