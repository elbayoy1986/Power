<?php
use App\Mesas\MesasService;
use App\Support\Auth;

$estaciones = (new MesasService($db))->estaciones(true);
$estacionId = (int) ($_GET['estacion'] ?? 0);
$actual = current(array_filter($estaciones, fn ($e) => (int) $e['id'] === $estacionId)) ?: null;
$titulo = $actual ? $actual['nombre'] : 'Todas las estaciones';

require __DIR__ . '/_layout.php';
layout_inicio('Cocina · ' . $titulo, 'cocina', $db, 'pantalla-cocina');
?>
<div class="fila-entre cocina-cab">
  <nav class="filtros" aria-label="Estación">
    <a class="chip <?= $estacionId ? '' : 'activo' ?>" href="index.php?p=cocina">Todas</a>
    <?php foreach ($estaciones as $e): ?>
      <a class="chip <?= (int) $e['id'] === $estacionId ? 'activo' : '' ?>" href="index.php?p=cocina&estacion=<?= (int) $e['id'] ?>"><?= e($e['nombre']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="fila">
    <span class="tenue chico" id="contador"></span>
    <label class="check-linea"><input type="checkbox" id="sonido"> 🔔 Sonido</label>
    <label class="check-linea" title="Este equipo imprime cada comanda nueva de esta estación"><input type="checkbox" id="auto-imprimir"> 🖨 Imprimir automáticamente</label>
  </div>
</div>
<?php if (!$estaciones): ?>
  <section class="tarjeta apertura"><h2>No hay estaciones de cocina activas</h2>
    <p class="tenue">Cree al menos una (Cocina, Bar…) en <a href="index.php?p=salon_config">Salón y cocina</a> para recibir comandas.</p></section>
<?php endif; ?>

<div id="cocina" class="kds" data-csrf="<?= e(Auth::csrf()) ?>" data-estacion="<?= $estacionId ?>" data-todas="<?= $estacionId ? 0 : 1 ?>">
  <p class="tenue">Cargando comandas…</p>
</div>
<section class="recientes">
  <h2>Despachadas recientemente</h2>
  <div id="recientes" class="recientes-lista"></div>
</section>
<iframe id="impresora" title="Impresión de comandas" class="oculto-impresion"></iframe>
<?php layout_fin(['assets/comun.js', 'assets/cocina.js']); ?>
