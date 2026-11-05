<?php

namespace App\Shared\StateMachine;

use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Exceptions\VersionConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Trait para modelos Eloquent con bloqueo optimista mediante columna lock_version.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property int $lock_version
 */
trait HasOptimisticLocking
{
    /**
     * Aplica una acción de estado validando la máquina de estados y usando bloqueo optimista (RN-PLT-01).
     *
     * @param  callable(): void|null  $effect
     *
     * @throws VersionConflictException
     * @throws InvalidTransitionException
     */
    public function applyTransition(string $action, StateMachine $stateMachine, ?callable $effect = null): void
    {
        $nextState = $stateMachine->getNextState($this->status, $action);

        DB::transaction(function () use ($nextState, $effect) {
            if ($effect !== null) {
                $effect();
            }

            $currentVersion = $this->lock_version ?? 1;

            $query = DB::table($this->getTable())
                ->where('id', $this->id)
                ->where('lock_version', $currentVersion);

            if (isset($this->tenant_id)) {
                $query->where('tenant_id', $this->tenant_id);
            }

            $affected = $query->update([
                'status' => $nextState,
                'lock_version' => $currentVersion + 1,
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                $actual = DB::table($this->getTable())->where('id', $this->id)->first();
                $actualVersion = isset($actual->lock_version) ? (int) $actual->lock_version : $currentVersion;

                throw new VersionConflictException($currentVersion, $actualVersion);
            }

            $this->status = $nextState;
            $this->lock_version = $currentVersion + 1;
        });
    }

    /**
     * Guarda cambios con verificación de bloqueo optimista sobre lock_version.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws VersionConflictException
     */
    public function updateWithOptimisticLock(array $attributes): void
    {
        DB::transaction(function () use ($attributes) {
            $currentVersion = $this->lock_version ?? 1;

            $query = DB::table($this->getTable())
                ->where('id', $this->id)
                ->where('lock_version', $currentVersion);

            if (isset($this->tenant_id)) {
                $query->where('tenant_id', $this->tenant_id);
            }

            $updateData = array_merge($attributes, [
                'lock_version' => $currentVersion + 1,
                'updated_at' => now(),
            ]);

            $affected = $query->update($updateData);

            if ($affected === 0) {
                $actual = DB::table($this->getTable())->where('id', $this->id)->first();
                $actualVersion = isset($actual->lock_version) ? (int) $actual->lock_version : $currentVersion;

                throw new VersionConflictException($currentVersion, $actualVersion);
            }

            $this->fill($attributes);
            $this->lock_version = $currentVersion + 1;
        });
    }
}
