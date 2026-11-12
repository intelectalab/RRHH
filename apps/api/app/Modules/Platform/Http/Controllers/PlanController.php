<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Plans\AddonCatalog;
use App\Modules\Platform\Application\Plans\PlanCatalog;
use App\Modules\Platform\Domain\PlanStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SAS-3 · Planes comerciales y complementos de la consola. El estado cambia solo por acciones.
 */
final class PlanController
{
    private const CODE = '/^[a-z0-9][a-z0-9_-]{1,49}$/';

    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly AddonCatalog $addons,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['draft', 'active', 'inactive'])],
        ]);
        $filter = (array) $request->query('filter', []);

        return response()->json($this->plans->list(
            isset($filter['status']) ? (string) $filter['status'] : null,
            $request->query('q') === null ? null : (string) $request->query('q'),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return response()->json(['data' => $this->plans->create($data, $this->actor($request))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // «status» y «code» no se editan: el estado cambia por acciones y el código identifica las versiones.
        $data = $request->validate($this->rules(false));

        return response()->json(['data' => $this->plans->update($id, $data, $this->actor($request))]);
    }

    public function versions(string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->versions($id)]);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'regex:'.self::CODE],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json(['data' => $this->plans->duplicate($id, $data, $this->actor($request))]);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->transition($id, PlanStateMachine::ACTIVATE, $this->actor($request))]);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->transition($id, PlanStateMachine::RETIRE, $this->actor($request))]);
    }

    public function addons(Request $request): JsonResponse
    {
        $request->validate(['filter.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])]]);
        $filter = (array) $request->query('filter', []);

        return response()->json($this->addons->list(isset($filter['status']) ? (string) $filter['status'] : null));
    }

    public function showAddon(string $id): JsonResponse
    {
        return response()->json(['data' => $this->addons->show($id)]);
    }

    public function updateAddon(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual', 'one_time'])],
            'price' => ['sometimes', 'nullable', 'array'],
            'price.amount' => ['required_with:price', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'price.currency' => ['required_with:price', 'string', 'size:3'],
            'limit_increment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'modules' => ['sometimes', 'array', 'max:40'],
            'modules.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => $this->addons->update($id, $data, $this->actor($request))]);
    }

    public function activateAddon(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->addons->transition($id, PlanStateMachine::ACTIVATE, $this->actor($request))]);
    }

    public function retireAddon(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->addons->transition($id, PlanStateMachine::RETIRE, $this->actor($request))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating ? ['required', 'string', 'regex:'.self::CODE] : ['prohibited'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual'])],
            'price' => ['sometimes', 'nullable', 'array'],
            'price.amount' => ['required_with:price', 'string'],
            'price.currency' => ['required_with:price', 'string', 'size:3'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'is_public' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'array', 'max:60'],
            'modules.*' => ['string', 'max:40'],
            'limits' => ['sometimes', 'array', 'max:20'],
            'limits.*' => ['array'],
            'features' => ['sometimes', 'array', 'max:60'],
            'features.*' => ['string', 'max:80'],
        ];
    }

    private function actor(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user('platform');
    }
}
