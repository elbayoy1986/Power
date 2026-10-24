<?php
use App\Mesas\MesasService;
use App\Support\Auth;

$zonas = (new MesasService($db))->salon(Auth::tiendaId());
$esSupervisor = in_array($usuario['rol'], ['ADMIN', 'GERENTE'], true);

require __DIR__ . '/_layout.php';
layout_inicio('Mesas', 'salon', $db);
?>
<div class="fila-entre">
  <h1>Salón</h1>
  <div class="fila">
    <label class="check-linea"><input type="checkbox" id="solo-mias"> Solo mis mesas</label>
    <?php if ($esSupervisor): ?><a class="btn" href="index.php?p=salon_config">Configurar salón y cocina</a><?php endif; ?>
  </div>
</div>
<p class="leyenda">
  <span><i class="punto libre"></i>Libre</span><span><i class="punto ocupada"></i>Ocupada</span>
  <span><i class="punto lista"></i>Comida lista para servir</span><span><i class="punto cobrar"></i>Pidió la cuenta</span>
  <span class="tenue" id="resumen-salon"></span>
</p>

<?php if (!$zonas): ?>
  <section class="tarjeta apertura">
    <h2>Esta tienda no tiene mesas</h2>
    <p class="tenue">Para atender en mesa, cree las zonas (Salón, Terraza…) y sus mesas.</p>
    <?php if ($esSupervisor): ?><a class="btn primario" href="index.php?p=salon_config">Configurar el salón</a>
    <?php else: ?><p>Pida a su gerente que configure el salón.</p><?php endif; ?>
  </section>
<?php endif; ?>

<div id="salon" data-usuario="<?= (int) $usuario['id'] ?>" data-rol="<?= e($usuario['rol']) ?>"
     data-inicial="<?= e(json_encode($zonas, JSON_UNESCAPED_UNICODE)) ?>"></div>
<?php layout_fin(['assets/comun.js', 'assets/salon.js']); ?>
