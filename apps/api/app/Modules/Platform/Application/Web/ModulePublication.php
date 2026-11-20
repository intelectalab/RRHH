<?php

namespace App\Modules\Platform\Application\Web;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WEB-2 · Un módulo está «a la venta» cuando la consola lo publica; el sitio deriva de aquí «Disponible» o
 * «Próximamente» (RN-WEB-02). Publicar o retirar queda en la auditoría de la consola.
 */
final class ModulePublication
{
    public function __construct(
        private readonly ConsoleAudit $audit,
        private readonly SiteLeads $leads,
        private readonly WebMessages $messages,
    ) {}

    /**
     * @return list<array{code: string, name: string, phase: int, is_core: bool, is_published: bool, published_at: string|null, depends_on: list<string>}>
     */
    public function list(): array
    {
        $deps = DB::table('module_dependencies')->where('is_required', true)->get(['module_code', 'depends_on_code'])->groupBy('module_code');

        return array_values(DB::table('modules')->orderBy('phase')->orderBy('code')->get()->map(fn ($m): array => [
            'code' => (string) $m->code, 'name' => (string) $m->name, 'phase' => (int) $m->phase, 'is_core' => (bool) $m->is_core,
            'is_published' => (bool) $m->is_published,
            'published_at' => $m->published_at === null ? null : (string) $m->published_at,
            'depends_on' => array_values(array_map(fn ($d): string => (string) $d->depends_on_code, ($deps[$m->code] ?? collect())->all())),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function set(string $code, bool $published, PlatformUser $actor): array
    {
        $module = DB::table('modules')->where('code', $code)->first();
        if ($module === null) {
            throw new NotFoundHttpException;
        }
        if ((bool) $module->is_published !== $published) {
            DB::transaction(function () use ($code, $published, $actor, $module): void {
                DB::table('modules')->where('code', $code)->update(['is_published' => $published, 'published_at' => $published ? now() : null]);
                $this->audit->record($published ? 'module.published' : 'module.unpublished', $actor, 'module', (string) $module->id,
                    ['is_published' => (bool) $module->is_published], ['is_published' => $published]);
            });
            // WEB-7 · RN-WEB-08: publicar avisa una sola vez a su lista de espera.
            if ($published && $this->leads->notifyWaitlist($code) > 0) {
                app()->terminating(fn () => $this->messages->deliver(100));
            }
        }

        return collect($this->list())->firstWhere('code', $code) ?? [];
    }
}
