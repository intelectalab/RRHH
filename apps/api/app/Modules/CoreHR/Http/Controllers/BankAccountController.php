<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\BankAccountService;
use App\Modules\CoreHR\Http\Controllers\Concerns\ResolvesEmployment;
use App\Modules\CoreHR\Infrastructure\Models\BankAccount;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * COL-8 · Cuentas de pago de una relación laboral. Se listan con people.employments.view (el número y el
 * CCI completos solo viajan con people.people.bank_account.view; si no, enmascarados); registrar un cambio
 * exige people.people.bank_account.edit y aprobarlo people.change_requests.approve, por otra persona.
 */
final class BankAccountController
{
    use ResolvesEmployment;

    private const SCHEMA = 'BankAccount';

    public function __construct(
        private readonly BankAccountService $accounts,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');
        // RN-COL-12: sin ningún permiso sobre cuentas de pago, el bloque no se envía (ni enmascarado).
        $permissions = $this->permissions($request);
        if (! $permissions->has('people.people.bank_account.view') && ! $permissions->has('people.people.bank_account.edit')
            && ! $permissions->has('people.change_requests.approve')) {
            throw new AyniException('forbidden', 403, (string) trans('people.payments.forbidden'), [], ['permission' => 'people.people.bank_account.view']);
        }
        $rows = array_map(fn (BankAccount $a) => $this->accounts->present($a), $this->accounts->forEmployment($employment));

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $rows, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function store(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.people.bank_account.edit');
        $input = $request->validate([
            'purpose' => ['required', Rule::in(BankAccountService::PURPOSES)],
            'bank_code' => ['required', 'string', 'max:40'],
            'account_type' => ['required', Rule::in(BankAccountService::ACCOUNT_TYPES)],
            'account_number' => ['required', 'string', 'max:40'],
            'interbank_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $result = $this->accounts->request($employment, $input, (string) $this->userId($request));

        return $this->respond($request, $employment, $result['account'], 201, $result['warnings']);
    }

    public function approve(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $account] = $this->find($request, $employment_id, $id, 'people.change_requests.approve');
        $input = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($request, $employment, $this->accounts->approve($account, (string) $this->userId($request), $input['notes'] ?? null));
    }

    public function reject(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $account] = $this->find($request, $employment_id, $id, 'people.change_requests.approve');
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($request, $employment, $this->accounts->reject($account, (string) $this->userId($request), (string) $input['reason']));
    }

    public function cancel(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $account] = $this->find($request, $employment_id, $id, 'people.people.bank_account.edit');
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($request, $employment, $this->accounts->cancel($account, (string) $this->userId($request), (string) $input['reason']));
    }

    /**
     * @return array{0: Employment, 1: BankAccount}
     */
    private function find(Request $request, string $employmentId, string $id, string $permission): array
    {
        $employment = $this->employment($request, $employmentId, $permission);
        self::uuidOr404($id);
        $account = BankAccount::query()->whereKey($id)->where('employment_id', $employment->id)->first();
        abort_unless($account instanceof BankAccount, 404);

        return [$employment, $account];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function respond(Request $request, Employment $employment, BankAccount $account, int $status = 200, array $warnings = []): JsonResponse
    {
        $data = $this->sensitive->present(self::SCHEMA, $this->accounts->present($account), $this->permissions($request), 'api', $employment->person_id);

        return response()->json(['data' => $data, 'meta' => ['warnings' => $warnings]], $status)->header('ETag', '"'.$account->lock_version.'"');
    }
}
