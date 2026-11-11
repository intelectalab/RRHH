<?php

namespace App\Modules\Audit\Application;

/**
 * Presenta verificaciones de integridad, manifiestos de archivo y actividad (AUD-3, AUD-5).
 */
final class AuditStorePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function check(object $record): array
    {
        /** @var array<string, mixed> $r */
        $r = (array) $record;
        $issues = json_decode((string) ($r['issues'] ?? '[]'), true);

        return [
            'id' => (string) $r['id'],
            'status' => (string) $r['status'],
            'checked_rows' => (int) $r['checked_rows'],
            'archived_rows' => (int) $r['archived_rows'],
            'unchained_rows' => (int) $r['unchained_rows'],
            'first_seq' => $r['first_seq'] === null ? null : (int) $r['first_seq'],
            'last_seq' => $r['last_seq'] === null ? null : (int) $r['last_seq'],
            'issues' => is_array($issues) ? array_values($issues) : [],
            'started_at' => $this->time($r['started_at']),
            'finished_at' => $r['finished_at'] === null ? null : $this->time($r['finished_at']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(object $record): array
    {
        /** @var array<string, mixed> $r */
        $r = (array) $record;

        return [
            'id' => (string) $r['id'],
            'period_month' => substr((string) $r['period_month'], 0, 10),
            'from_seq' => $r['from_seq'] === null ? null : (int) $r['from_seq'],
            'to_seq' => $r['to_seq'] === null ? null : (int) $r['to_seq'],
            'row_count' => (int) $r['row_count'],
            'sha256' => (string) $r['sha256'],
            'size_bytes' => $r['size_bytes'] === null ? null : (int) $r['size_bytes'],
            'archived_at' => $this->time($r['archived_at']),
            'purge_after' => substr((string) $r['purge_after'], 0, 10),
            'purged_at' => $r['purged_at'] === null ? null : $this->time($r['purged_at']),
            // Consultable mientras no venza el plazo de conservación.
            'available' => $r['purged_at'] === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(object $record): array
    {
        /** @var array<string, mixed> $r */
        $r = (array) $record;
        $metadata = $r['metadata'] === null ? null : json_decode((string) $r['metadata'], true);

        return [
            'id' => (string) $r['id'],
            'user_id' => $r['user_id'] === null ? null : (string) $r['user_id'],
            'user_name' => $r['user_name'] ?? null,
            'activity' => (string) $r['activity'],
            'subject_type' => $r['subject_type'] === null ? null : (string) $r['subject_type'],
            'subject_id' => $r['subject_id'] === null ? null : (string) $r['subject_id'],
            'metadata' => is_array($metadata) ? $this->withoutIp($metadata) : null,
            'occurred_at' => $this->time($r['occurred_at']),
        ];
    }

    /**
     * La IP de un ingreso no se muestra en la línea de actividad (es un dato técnico).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withoutIp(array $metadata): array
    {
        unset($metadata['ip']);

        return $metadata;
    }

    private function time(mixed $value): string
    {
        return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
    }
}
