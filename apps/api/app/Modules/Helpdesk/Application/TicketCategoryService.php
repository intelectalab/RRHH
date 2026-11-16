<?php

namespace App\Modules\Helpdesk\Application;

use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ESS-5 · Tipos de solicitud a RRHH. Los de partida se crean la primera vez que se usan en la organización
 * (atiende RRHH); la organización edita, agrega o archiva los suyos (helpdesk.settings.update). El nombre de
 * los de partida se traduce mientras la organización no lo cambie.
 */
final class TicketCategoryService
{
    /** Tipos de partida: código => orden. */
    public const DEFAULTS = ['general' => 10, 'payslips' => 20, 'documents' => 30, 'benefits' => 40, 'schedule' => 50, 'other' => 90];

    private bool $ensured = false;

    public function __construct(private readonly AuditLogger $audit) {}

    public function ensureDefaults(): void
    {
        if ($this->ensured) {
            return;
        }
        $tenant = TenancyScope::requireTenant();
        if (DB::table('ticket_categories')->count() === 0) {
            $rrhh = DB::table('roles')->where('system_role_code', 'rrhh')->whereNull('deleted_at')->value('id');
            foreach (self::DEFAULTS as $code => $order) {
                DB::table('ticket_categories')->insertOrIgnore([
                    'tenant_id' => $tenant, 'code' => $code, 'name' => (string) trans('helpdesk.categories.'.$code, [], 'es'),
                    'description' => (string) trans('helpdesk.category_descriptions.'.$code, [], 'es'),
                    'default_assignee_role_id' => $rrhh, 'sort_order' => $order,
                ]);
            }
        }
        $this->ensured = true;
    }

    /** @return list<TicketCategory> */
    public function all(bool $includeArchived): array
    {
        $this->ensureDefaults();

        /** @var list<TicketCategory> */
        return TicketCategory::query()->when(! $includeArchived, fn ($q) => $q->where('status', 'active'))
            ->orderBy('sort_order')->orderBy('name')->get()->all();
    }

    public function find(string $id): TicketCategory
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $c = TicketCategory::query()->find($id);
        abort_unless($c instanceof TicketCategory, 404);

        return $c;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TicketCategory
    {
        $this->ensureDefaults();
        if (TicketCategory::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('helpdesk.code_taken'));
        }
        $this->validateRole($data);

        return DB::transaction(function () use ($data): TicketCategory {
            $c = TicketCategory::create($data);
            $this->audit->record('ticket_category.created', 'ticket_category', $c->id, null, $c->only(array_keys($data)));

            return $c;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TicketCategory $c, array $data): TicketCategory
    {
        unset($data['code']);
        $this->validateRole($data);
        $before = $c->only(array_keys($data));
        DB::transaction(function () use ($c, $data, $before): void {
            $c->fill($data);
            $c->save();
            $this->audit->record('ticket_category.updated', 'ticket_category', $c->id, $before, $c->only(array_keys($data)));
        });

        return $c;
    }

    public function name(TicketCategory $c): string
    {
        if (array_key_exists($c->code, self::DEFAULTS) && $c->name === trans('helpdesk.categories.'.$c->code, [], 'es')) {
            return (string) trans('helpdesk.categories.'.$c->code);
        }

        return $c->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketCategory $c): array
    {
        $isDefault = array_key_exists($c->code, self::DEFAULTS);

        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $this->name($c),
            'description' => $isDefault && $c->description === trans('helpdesk.category_descriptions.'.$c->code, [], 'es')
                ? (string) trans('helpdesk.category_descriptions.'.$c->code) : $c->description,
            'default_assignee_role_id' => $c->default_assignee_role_id,
            'default_assignee_role_name' => $c->default_assignee_role_id === null ? null : DB::table('roles')->where('id', $c->default_assignee_role_id)->value('name'),
            'requires_attachment' => $c->requires_attachment, 'is_confidential' => $c->is_confidential,
            'sort_order' => $c->sort_order, 'status' => $c->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRole(array $data): void
    {
        $role = $data['default_assignee_role_id'] ?? null;
        if (is_string($role) && ! DB::table('roles')->where('id', $role)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['default_assignee_role_id' => [(string) trans('helpdesk.role_unknown')]]);
        }
    }
}
