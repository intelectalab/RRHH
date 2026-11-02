<?php
/**
 * contratos.php — Contratos sujetos a plazo: vencidos y por vencer agrupados por urgencia,
 * vencimientos por mes y renovación desde la misma fila.
 */
$act = activos(); $editar = puede('personal.editar');
$plazo = array_values(array_filter($act, fn($c) => $c['tipoContrato'] !== 'Indeterminado' && $c['finContrato']));
usort($plazo, fn($a, $b) => strcmp($a['finContrato'], $b['finContrato']));
$grupos = ['Vencidos sin renovar ni cese' => fn($d) => $d < 0, 'Vencen en los próximos 30 días' => fn($d) => $d >= 0 && $d <= 30, 'Vencen en 31 a 60 días' => fn($d) => $d > 30 && $d <= 60, 'Vencen en 61 a 90 días' => fn($d) => $d > 60 && $d <= 90, 'Vencen después' => fn($d) => $d > 90];
$porGrupo = []; foreach ($plazo as $c) { $d = dif_dias(HOY, $c['finContrato']); foreach ($grupos as $g => $fn) if ($fn($d)) { $porGrupo[$g][] = $c; break; } }
$meses = []; for ($i = 0; $i < 6; $i++) { $ym = substr(add_meses(substr(HOY, 0, 7).'-15', $i), 0, 7);
    $l = array_filter($plazo, fn($c) => substr($c['finContrato'], 0, 7) === $ym);
    $meses[] = ['et' => MES3[(int)substr($ym, 5) - 1], 'v' => count($l), 'tip' => mes_nombre((int)substr($ym, 5)).': '.(count($l) ? implode(', ', array_map('nomc', $l)) : 'ningún vencimiento')]; }
$indet = count($act) - count($plazo);

$pagina['titulo'] = 'Personal';
$pagina['sub'] = 'Contratos sujetos a plazo · aviso con '.param('avisoContratoDias').' días de anticipación';
$pagina['pestanas'] = pestanas([[url('personal'), 'Colaboradores', false], [url('organigrama'), 'Organigrama', false], [url('contratos'), 'Contratos', true, count($porGrupo['Vencidos sin renovar ni cese'] ?? []) + count($porGrupo['Vencen en los próximos 30 días'] ?? [])], [url('cumpleanos'), 'Cumpleaños y aniversarios', false]]);
?>
<div class="row g-4">
  <div class="col-xl-5">
    <section class="card mb-0 h-100" aria-label="Resumen de contratos"><div class="cifras cifras-sin-borde py-2">
      <div><div class="l">Vencidos</div><div class="v <?= !empty($porGrupo['Vencidos sin renovar ni cese']) ? 'text-danger' : '' ?>"><?= count($porGrupo['Vencidos sin renovar ni cese'] ?? []) ?></div><div class="ctx">Sin renovación ni cese registrados</div></div>
      <div><div class="l">Vencen en 30 días</div><div class="v"><?= count($porGrupo['Vencen en los próximos 30 días'] ?? []) ?></div><div class="ctx">Decida si renueva o comunica el término</div></div>
      <div><div class="l">Sujetos a plazo</div><div class="v"><?= count($plazo) ?></div><div class="ctx"><?= pct(count($plazo), count($act)) ?> de la plantilla activa</div></div>
      <div><div class="l">Indeterminados</div><div class="v"><?= $indet ?></div><div class="ctx"><?= pct($indet, count($act)) ?> de la plantilla activa</div></div>
    </div></section>
  </div>
  <div class="col-xl-7">
    <section class="card mb-0 h-100"><div class="card-header"><h2>Vencimientos por mes</h2><span class="ctx">Próximos 6 meses</span></div>
      <div class="card-body"><?= columnas($meses, ['alto' => 150, 'titulo' => 'Contratos que vencen por mes']) ?></div></section>
  </div>
</div>

<section class="card mt-4">
  <div class="card-header"><h2>Contratos por vencer</h2><a class="small" href="<?= url('personal', ['vista' => 'vencer']) ?>">Ver en la lista de personal</a></div>
  <?php if (!$plazo): ?><?= estado_vacio('No hay contratos sujetos a plazo', 'Toda la plantilla activa tiene contrato indeterminado.') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Colaborador</th><th>Contrato</th><th>Vence</th><th>Avance del periodo</th><th class="num">Renovaciones</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($grupos as $g => $fn): if (empty($porGrupo[$g])) continue; ?>
      <tr class="fila-grupo"><td colspan="6"><?= e($g) ?> · <?= count($porGrupo[$g]) ?></td></tr>
      <?php foreach ($porGrupo[$g] as $c): $d = dif_dias(HOY, $c['finContrato']);
        $ren = array_values(array_filter(tabla('historial'), fn($h) => $h['colabId'] === $c['id'] && $h['tipo'] === 'Renovación'));
        $iniC = $ren ? (fecha_iso(end($ren)['antes']) ?? $c['fecIngreso']) : $c['fecIngreso']; $tot = max(1, dif_dias($iniC, $c['finContrato'])); ?>
      <tr data-href="<?= url('colaborador', ['id' => $c['id'], 'tab' => 'contrato']) ?>" data-menu class="<?= $d < 0 ? 'fila-alerta' : '' ?>">
        <td><?= celda_nombre($c, e(puesto($c)).' · '.e(sede($c)), true, 'contrato') ?></td>
        <td><?= e($c['tipoContrato']) ?><span class="sub2">Desde el <?= f($iniC) ?></span></td>
        <td><b><?= f($c['finContrato']) ?></b><span class="sub2 <?= $d < 0 ? 'text-danger fw-bold' : ($d <= 30 ? 'text-warning-emphasis fw-bold' : '') ?>"><?= e(ucfirst(hace($c['finContrato']))) ?></span></td>
        <td style="min-width:140px" data-tip="<?= e(min(100, round(max(0, dif_dias($iniC, HOY)) / $tot * 100)).' % del periodo transcurrido') ?>"><?= medidor(max(0, dif_dias($iniC, HOY)) / $tot * 100, $d <= 30 ? 'wa' : 'g1') ?></td>
        <td class="num"><?= count($ren) ?: vacio_d() ?></td>
        <td class="text-end text-nowrap"><?= $editar ? btn_panel('renovar', ['id' => $c['id']], 'Renovar', 'btn btn-sec btn-sm') : '' ?>
          <div class="dropdown d-inline-block acc-fila"><button class="btn btn-ter btn-sm btn-ico" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acciones para <?= e(nomc($c)) ?>"><?= ic('dots-three-vertical') ?></button><ul class="dropdown-menu dropdown-menu-end"><?= menu_colab($c) ?></ul></div></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
