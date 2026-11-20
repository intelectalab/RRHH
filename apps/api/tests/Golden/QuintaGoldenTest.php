<?php

/**
 * PER-5 · Casos dorados de la retención de quinta categoría (tests/Golden/Quinta/<caso>/): los cuatro casos
 * resueltos del documento 18 §2.9, mes a mes, con la UIT y la escala como datos del caso. Comparación exacta al
 * céntimo. El desarrollador nunca cambia un resultado esperado para hacer pasar la prueba (kit/docs/11 §8).
 *
 * Además se repite cada caso con otra UIT y se afirma la propiedad del procedimiento: con la regularización de
 * diciembre, lo retenido en el año (propio y declarado) iguala el impuesto anual sobre lo realmente percibido.
 * Si el código tuviera algún monto fijo, la propiedad fallaría con la otra UIT.
 */

use App\Modules\Payroll\Domain\Tax\ProjectedAnnualWithholding;
use Brick\Math\BigDecimal;
use Symfony\Component\Yaml\Yaml;

$cases = array_map('basename', glob(__DIR__.'/Quinta/*', GLOB_ONLYDIR) ?: []);

/**
 * @param  array<string, mixed>  $input
 * @param  array<string, mixed>  $params
 * @return array{withholdings: array<int, string>, total: BigDecimal, external: BigDecimal, income: BigDecimal}
 */
function quintaYear(array $input, array $params): array
{
    $external = BigDecimal::zero();
    $withheld = [];
    foreach ((array) ($input['external_withholdings'] ?? []) as $m => $v) {
        $withheld[(int) $m] = (string) $v;
        $external = $external->plus((string) $v);
    }
    $prior = BigDecimal::zero();
    $own = [];
    $total = BigDecimal::zero();
    foreach ($input['months'] as $row) {
        $m = (int) $row['month'];
        $r = ProjectedAnnualWithholding::calculate([
            'month' => $m, 'ordinary_month' => (string) $row['ordinary'], 'pending_gratifications' => (string) $row['pending_gratifications'],
            'prior_income' => (string) $prior, 'external_received' => (string) ($input['external_received'] ?? '0'), 'external_projected' => '0',
            'extraordinary_month' => (string) $row['extraordinary'], 'taxable_month_total' => '0', 'withholdings_by_month' => $withheld,
        ], $params);
        $own[$m] = (string) $r['withholding'];
        $withheld[$m] = (string) BigDecimal::of($withheld[$m] ?? '0')->plus($r['withholding']);
        $total = $total->plus($r['withholding']);
        $prior = $prior->plus((string) $row['ordinary'])->plus((string) $row['paid_other']);
    }

    return ['withholdings' => $own, 'total' => $total, 'external' => $external, 'income' => $prior->plus((string) ($input['external_received'] ?? '0'))];
}

test('caso dorado de quinta', function (string $case): void {
    $dir = __DIR__.'/Quinta/'.$case;
    $meta = Yaml::parseFile($dir.'/case.yaml');
    if (($meta['status'] ?? '') !== 'validated') {
        test()->markTestSkipped("{$case}: {$meta['status']} (no bloquea)");
    }
    $input = Yaml::parseFile($dir.'/input.yaml');
    $params = Yaml::parseFile($dir.'/params.yaml');
    $expected = Yaml::parseFile($dir.'/expected.yaml');

    $year = quintaYear($input, $params);
    $want = [];
    foreach ($expected['withholdings'] as $m => $v) {
        $want[(int) $m] = (string) $v;
    }

    expect($year['withholdings'])->toBe($want)
        ->and((string) $year['total']->toScale(2))->toBe((string) $expected['total']);
})->with($cases);

test('con otra UIT lo retenido en el año iguala el impuesto anual (sin montos fijos en el código)', function (string $case): void {
    $dir = __DIR__.'/Quinta/'.$case;
    $input = Yaml::parseFile($dir.'/input.yaml');
    $params = Yaml::parseFile($dir.'/params.yaml');
    foreach (['5500', '5150', '6000'] as $uit) {
        $params['uit'] = $uit;
        $year = quintaYear($input, $params);
        $deduction = BigDecimal::of($params['deduction_uit'])->multipliedBy($uit);
        $tax = ProjectedAnnualWithholding::tax($year['income']->minus($deduction), BigDecimal::of($uit), $params['scale']);
        expect((string) $year['total']->plus($year['external'])->toScale(2))->toBe((string) $tax, "{$case} con UIT {$uit}");
    }
})->with($cases);
