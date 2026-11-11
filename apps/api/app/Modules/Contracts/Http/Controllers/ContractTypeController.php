<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Modules\Contracts\Application\ContractTypeCatalog;
use App\Modules\Contracts\Infrastructure\Models\ContractType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CON-1 · Tipos de contrato y modalidades. Los del paquete de país son de solo lectura; la
 * organización agrega y edita los suyos. Plazo máximo y periodo de prueba se resuelven a la fecha
 * (?as_of=) desde los parámetros legales con vigencia: nunca se guardan como número en el tipo.
 */
final class ContractTypeController
{
    public function __construct(
        private readonly ContractTypeCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $this->catalog->ensureCountryTypes();
        $query = ContractType::query();
        $category = $request->input('filter.category', $request->query('category'));
        if (is_string($category) && in_array($category, ContractType::CATEGORIES, true)) {
            $query->where('category', $category);
        }
        $rows = $query->orderByDesc('is_system')->orderBy('category')->orderBy('name')->get();

        return response()->json([
            'data' => $rows->map(fn (ContractType $t) => $this->catalog->present($t, $asOf))->all(),
            'meta' => ['per_page' => max(1, $rows->count()), 'next_cursor' => null, 'as_of' => $asOf],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->present($this->find($id), $this->asOf($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->catalog->ensureCountryTypes();
        $data = $request->validate($this->rules(true));
        if (ContractType::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('contracts.types.code_exists'));
        }
        $country = (string) DB::table('current_tenant')->value('country_code');

        $type = DB::transaction(function () use ($data, $country): ContractType {
            $t = ContractType::query()->create($data + ['country_code' => $country, 'is_system' => false]);
            $this->audit->record('contract_type.created', 'contract_type', $t->id, null, $this->snapshot($t->refresh()));

            return $t;
        });

        return response()->json(['data' => $this->catalog->present($type, now()->toDateString())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $type = $this->find($id);
        if ($type->is_system) {
            throw new BusinessRuleViolatedException('contract_type_is_system', (string) trans('contracts.types.system_read_only'));
        }
        $data = $request->validate($this->rules(false));
        if (isset($data['code']) && $data['code'] !== $type->code && ContractType::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('contracts.types.code_exists'));
        }
        $before = $this->snapshot($type);

        DB::transaction(function () use ($type, $data, $before): void {
            $type->fill($data)->save();
            $this->audit->record('contract_type.updated', 'contract_type', $type->id, $before, $this->snapshot($type->refresh()));
        });

        return response()->json(['data' => $this->catalog->present($type, $this->asOf($request))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $country = (string) DB::table('current_tenant')->value('country_code');
        $params = $this->catalog->parameterCodes($country);

        return [
            'code' => [$req, 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => [$req, 'string', 'max:120'],
            'category' => [$req, Rule::in(ContractType::CATEGORIES)],
            'modality_code' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'requires_end_date' => ['sometimes', 'boolean'],
            'requires_objective_cause' => ['sometimes', 'boolean'],
            // Los plazos se toman de un parámetro legal existente del país: el tipo no guarda números.
            'max_duration_parameter_code' => ['sometimes', 'nullable', 'string', Rule::in($params)],
            'trial_period_parameter_code' => ['sometimes', 'nullable', 'string', Rule::in($params)],
            'document_template_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('document_templates', 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ContractType $t): array
    {
        return $t->only(['code', 'name', 'category', 'modality_code', 'requires_end_date', 'requires_objective_cause',
            'max_duration_parameter_code', 'trial_period_parameter_code', 'document_template_id']);
    }

    private function asOf(Request $request): string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null) {
            return now()->toDateString();
        }
        $request->validate(['as_of' => ['date_format:Y-m-d']]);

        return (string) $asOf;
    }

    private function find(string $id): ContractType
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var ContractType */
        return ContractType::query()->whereKey($id)->firstOrFail();
    }
}
