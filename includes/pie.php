<?php
/**
 * pie.php — Cierre del documento: contenedor de paneles y avisos, scripts locales y
 * los avisos (toasts) pendientes que dejó la acción anterior.
 */
$avisos = $_SESSION['avisos'] ?? []; unset($_SESSION['avisos']);
?>
</main>
<?php if (empty($pagina['sin_marco'])): ?>
</div>
<?php endif; ?>
<div id="panel-raiz"></div>
<div class="avisos" id="avisos" aria-live="polite"></div>
<div class="tip" id="tip" role="tooltip" hidden></div>
<script>window.AYNI = <?= json_encode(['csrf' => csrf(), 'avisos' => $avisos, 'pagina' => $p], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
