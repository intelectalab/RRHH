<?php

namespace App\Modules\Identity\Infrastructure\Usage;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;
use Illuminate\Support\Facades\DB;

/** SAS-6 · Usuarios no desactivados con el rol de administrador de la organización. */
final class AdminsUsageSource implements UsageSource
{
    public function metric(): string
    {
        return UsageMetrics::ADMINS;
    }

    public function measure(): int
    {
        return DB::table('users as u')
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', [User::STATUS_DISABLED, User::STATUS_ALUMNI])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->whereColumn('ur.user_id', 'u.id')->where('r.system_role_code', 'admin_empresa')->whereNull('r.deleted_at'))
            ->count() * 100;
    }
}
