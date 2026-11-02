<?php
/**
 * encabezado.php — Encabezado de cada pantalla: ruta de navegación, título, subtítulo,
 * acciones a la derecha y pestañas de sección. Los valores los define cada página en $pagina.
 */
$migas = $pagina['migas'] ?? [];
?>
<div class="phead <?= $pagina['clase_head'] ?? '' ?>"><div class="in">
  <?php if ($migas): ?>
  <nav aria-label="Ruta"><ol class="breadcrumb">
    <?php foreach ($migas as $m): ?>
      <?php if (!empty($m[1])): ?><li class="breadcrumb-item"><a href="<?= e($m[1]) ?>"><?= e($m[0]) ?></a></li>
      <?php else: ?><li class="breadcrumb-item active" aria-current="page"><?= e($m[0]) ?></li><?php endif; ?>
    <?php endforeach; ?>
  </ol></nav>
  <?php endif; ?>
  <div class="phead-fila <?= empty($pagina['pestanas']) ? 'pb-3' : '' ?>">
    <div class="min-w-0">
      <h1><?= $pagina['titulo_html'] ?? e($pagina['titulo'] ?? '') ?></h1>
      <?php if (!empty($pagina['sub'])): ?><div class="sub"><?= $pagina['sub'] ?></div><?php endif; ?>
    </div>
    <div class="acc"><?= $pagina['acciones'] ?? '' ?></div>
  </div>
  <?= $pagina['pestanas'] ?? '' ?>
</div></div>
