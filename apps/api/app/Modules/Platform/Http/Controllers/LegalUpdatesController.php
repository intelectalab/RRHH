<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\LegalUpdates\LegalParameterPublications;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\LegalParameters\PensionFundRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAR-2 · PAR-4 · Consola: actualizaciones normativas con doble revisión y carga de las tablas de las AFP por
 * periodo (formulario o archivo CSV) con la fuente oficial citada.
 */
final class LegalUpdatesController extends Controller
{
    private const RATE_COLUMNS = ['fund_code', 'valid_from', 'contribution_rate', 'commission_flow_rate', 'commission_mixed_rate', 'insurance_premium_rate', 'max_insurable_remuneration', 'source'];

    public function __construct(
        private readonly LegalParameterPublications $publications,
        private readonly PensionFundRates $rates,
        private readonly ConsoleAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return response()->json(['data' => $this->publications->list(is_string($status) && $status !== '' ? $status : null)]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->publications->show($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->publications->create($this->input($request), $this->actor($request))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->publications->show($id);

        return response()->json(['data' => $this->publications->update($id, $this->input($request), $this->actor($request))]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->publications->submit($id, $this->actor($request))]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->publications->publish($id, $request->input('note') === null ? null : (string) $request->input('note'), $this->actor($request))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->publications->show($id);

        return response()->json(['data' => $this->publications->reject($id, (string) $request->input('note', ''), $this->actor($request))]);
    }

    public function rates(Request $request): JsonResponse
    {
        $asOf = (string) $request->query('as_of', now()->toDateString());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            throw ValidationException::withMessages(['as_of' => trans('payroll.parameters.invalid_date')]);
        }
        $country = strtoupper((string) $request->query('country', 'PE'));

        return response()->json(['data' => ['as_of' => $asOf, 'funds' => $this->rates->forDate($country, $asOf)]]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $row = DB::transaction(fn (): array => $this->rates->store($request->only(self::RATE_COLUMNS + [8 => 'verified', 9 => 'currency'])));
        $this->audit->record('pension_fund_rate.loaded', $this->actor($request), 'pension_fund_rate', (string) $row['id'], null, $row);

        return response()->json(['data' => $row], 201);
    }

    /**
     * Carga por archivo: CSV con cabecera; cada fila es la tabla de una administradora para un periodo. Todo o nada.
     */
    public function importRates(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => trans('payroll.parameters.invalid_file')]);
        }
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => trans('payroll.parameters.invalid_file')]);
        }
        $header = fgetcsv($handle);
        $header = $header === false ? [] : array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), $header);
        if (! in_array('fund_code', $header, true) || ! in_array('valid_from', $header, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => trans('payroll.parameters.invalid_file')]);
        }
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
        }
        fclose($handle);
        $actor = $this->actor($request);
        $loaded = DB::transaction(function () use ($rows, $request): array {
            $out = [];
            foreach ($rows as $i => $row) {
                try {
                    $out[] = $this->rates->store($row + ['source' => $request->input('source')]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['rows.'.($i + 2).'.'.$k => $m])->all());
                }
            }

            return $out;
        });
        $this->audit->record('pension_fund_rate.imported', $actor, 'pension_fund_rate', null, null, ['rows' => count($loaded)]);

        return response()->json(['data' => ['loaded' => count($loaded), 'rates' => $loaded]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'country' => ['required', 'string', 'size:2'], 'title' => ['required', 'string', 'max:200'], 'summary' => ['nullable', 'string', 'max:2000'],
            'legal_source' => ['required', 'string', 'max:500'], 'changes' => ['required', 'array', 'min:1', 'max:200'],
        ]);
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
