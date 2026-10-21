<?php
use App\Caja\CajaService;
use App\Support\Auth;
use App\Support\Money;

$caja = new CajaService($db);
$esSupervisor = in_array($usuario['rol'], ['ADMIN', 'GERENTE'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['accion'] ?? '') {
            case 'abrir':
                $caja->abrir(Auth::tiendaId(), $usuario['id'], Money::aCentavos($_POST['fondo'] ?? 0));
                redirigir($_POST['volver'] ?? 'caja', 'Turno abierto. Ya puede facturar.');
            case 'movimiento':
                $t = $caja->turnoAbierto($usuario['id']) ?? throw new DomainException('No tiene un turno abierto.');
                $caja->movimiento((int) $t['id'], $usuario['id'], $_POST['tipo'] ?? '', Money::aCentavos($_POST['monto'] ?? 0), $_POST['motivo'] ?? '');
                redirigir('caja', 'Movimiento registrado.');
            case 'cerrar':
                $turnoId = (int) $_POST['turno_id'];
                $t = $caja->turno($turnoId);
                // El cajero cierra su propio turno; gerente/admin pueden cerrar los de su tienda
                if (!$t || ((int) $t['usuario_id'] !== $usuario['id'] && !($esSupervisor && ($usuario['tienda_id'] === null || (int) $t['tienda_id'] === $usuario['tienda_id'])))) {
                    throw new DomainException('No puede cerrar este turno.');
                }
                $r = $caja->cerrar($turnoId, $usuario['id'], $_POST['conteo'] ?? [], $_POST['notas'] ?? '');
                $_SESSION['flash'] = 'Turno cerrado. ' . ($r['diferencia'] === 0 ? 'La caja cuadra.' : ($r['diferencia'] < 0 ? 'Faltante: ' : 'Sobrante: ') . lps(abs($r['diferencia'])));
                header('Location: index.php?p=cierre&id=' . $turnoId);
                exit;
        }
    } catch (DomainException $e) {
        redirigir('caja' . (isset($_POST['turno_id']) ? '&cerrar=' . (int) $_POST['turno_id'] : ''), $e->getMessage());
    }
}

$mio = $caja->turnoAbierto($usuario['id']);
$cerrarId = (int) ($_GET['cerrar'] ?? 0);
$aCerrar = $cerrarId ? $caja->turno($cerrarId) : null;
if ($aCerrar && $aCerrar['estado'] !== 'ABIERTO') {
    $aCerrar = null;
}
$resumen = $mio ? $caja->resumen((int) $mio['id']) : null;
$resumenCierre = $aCerrar ? $caja->resumen((int) $aCerrar['id']) : null;

// Historial: el cajero ve sus turnos; gerente/admin ven todos los de la tienda
$st = $db->prepare(
    'SELECT tc.*, u.nombre AS cajero FROM turnos_caja tc JOIN usuarios u ON u.id = tc.usuario_id
     WHERE tc.tienda_id = ?' . ($esSupervisor ? '' : ' AND tc.usuario_id = ' . (int) $usuario['id']) . '
     ORDER BY tc.estado = \'ABIERTO\' DESC, tc.apertura DESC LIMIT 30'
);
$st->execute([Auth::tiendaId()]);
$historial = $st->fetchAll();

require __DIR__ . '/_layout.php';
layout_inicio('Turno de caja', 'caja', $db);
?>
<h1>Turno de caja</h1>

<?php if ($aCerrar): $rc = $resumenCierre; ?>
  <!-- ================= Cierre con arqueo ================= -->
  <section class="tarjeta" id="cierre">
    <h2>Cierre de turno · <?= e($aCerrar['cajero']) ?> · abierto <?= e(date('d/m H:i', strtotime($aCerrar['apertura']))) ?></h2>
    <div class="columnas">
      <div>
        <dl class="totales">
          <dt>Fondo inicial</dt><dd><?= lps($aCerrar['fondo_inicial']) ?></dd>
          <dt>Ventas en efectivo</dt><dd><?= lps($rc['efectivo_ventas']) ?></dd>
          <dt>Ingresos de efectivo</dt><dd><?= lps($rc['ingresos']) ?></dd>
          <dt>Retiros de efectivo</dt><dd>−<?= lps($rc['retiros']) ?></dd>
          <dt class="total">Efectivo esperado</dt><dd class="total" id="esperado" data-c="<?= $rc['efectivo_esperado'] ?>"><?= lps($rc['efectivo_esperado']) ?></dd>
        </dl>
        <p class="tenue chico">Tarjeta, transferencia y la parte no efectiva del pago mixto no se cuentan en caja; aparecen en el reporte de cierre.</p>
      </div>
      <form method="post" id="form-arqueo">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="accion" value="cerrar">
        <input type="hidden" name="turno_id" value="<?= (int) $aCerrar['id'] ?>">
        <?php foreach (CajaService::DENOMINACIONES as $grupo => $lista): ?>
          <fieldset><legend><?= $grupo === 'billetes' ? 'Billetes' : 'Monedas' ?></legend>
            <div class="arqueo">
              <?php foreach ($lista as $d): ?>
                <label class="denom"><span><?= $d >= 100 ? 'L ' . ($d / 100) : $d . ' ctv' ?></span>
                  <input type="number" min="0" step="1" inputmode="numeric" name="conteo[<?= $d ?>]" data-d="<?= $d ?>" placeholder="0" id="den-<?= $d ?>">
                  <output class="sub">L 0.00</output></label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
        <dl class="totales">
          <dt>Contado</dt><dd id="contado">L 0.00</dd>
          <dt class="total">Diferencia</dt><dd class="total" id="diferencia">—</dd>
        </dl>
        <label>Notas (obligatorias si hay diferencia) <input name="notas" id="notas-cierre" maxlength="255" placeholder="Ej.: faltante por vuelto mal entregado"></label>
        <div class="fila">
          <a class="btn texto" href="index.php?p=caja">Cancelar</a>
          <button class="btn primario">Cerrar turno</button>
        </div>
      </form>
    </div>
  </section>
  <script>
    (() => {
      const lps = (c) => 'L ' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const esperado = +document.getElementById('esperado').dataset.c;
      const calc = () => {
        let total = 0;
        document.querySelectorAll('#form-arqueo [data-d]').forEach((i) => {
          const sub = Math.max(0, Math.floor(+i.value || 0)) * +i.dataset.d;
          i.nextElementSibling.textContent = lps(sub);
          total += sub;
        });
        const dif = total - esperado;
        document.getElementById('contado').textContent = lps(total);
        const el = document.getElementById('diferencia');
        el.textContent = dif === 0 ? 'Cuadra ✔' : (dif < 0 ? 'Faltante ' : 'Sobrante ') + lps(Math.abs(dif));
        el.className = 'total ' + (dif === 0 ? 'ok-txt' : 'mal-txt');
        document.getElementById('notas-cierre').required = dif !== 0;
      };
      document.getElementById('form-arqueo').addEventListener('input', calc);
      calc();
    })();
  </script>

<?php elseif (!$mio): ?>
  <!-- ================= Apertura ================= -->
  <section class="tarjeta apertura">
    <h2>Abrir turno en <?= e($db->query('SELECT nombre FROM tiendas WHERE id = ' . Auth::tiendaId())->fetchColumn()) ?></h2>
    <p class="tenue">Cuente el efectivo con que inicia (fondo de cambio). Sin turno abierto no se puede facturar.</p>
    <form method="post" class="fila">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <input type="hidden" name="accion" value="abrir">
      <label>Fondo inicial (L) <input name="fondo" inputmode="decimal" required placeholder="1,000.00" id="fondo"></label>
      <button class="btn primario">Abrir turno</button>
    </form>
  </section>

<?php else: $r = $resumen; ?>
  <!-- ================= Turno abierto ================= -->
  <div class="kpis">
    <div class="kpi"><span>Abierto desde</span><b><?= e(date('H:i', strtotime($mio['apertura']))) ?></b><small><?= e(date('d/m/Y', strtotime($mio['apertura']))) ?> · <?= e($mio['tienda']) ?></small></div>
    <div class="kpi"><span>Facturas</span><b><?= (int) $r['ventas']['facturas'] ?></b></div>
    <div class="kpi"><span>Vendido</span><b><?= lps($r['ventas']['total']) ?></b></div>
    <div class="kpi"><span>Efectivo en caja</span><b><?= lps($r['efectivo_esperado']) ?></b><small>esperado</small></div>
  </div>
  <div class="columnas">
    <section class="tarjeta">
      <h2>Entradas y retiros de efectivo</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="accion" value="movimiento">
        <div class="fila">
          <label>Tipo <select name="tipo" id="mov-tipo"><option value="RETIRO">Retiro (remesa, pago menor)</option><option value="INGRESO">Ingreso (más cambio)</option></select></label>
          <label>Monto (L) <input name="monto" inputmode="decimal" required id="mov-monto"></label>
        </div>
        <label>Motivo <input name="motivo" required maxlength="150" placeholder="Remesa a bóveda #123" id="mov-motivo"></label>
        <button class="btn">Registrar</button>
      </form>
      <?php if ($r['movimientos']): ?>
        <div class="tabla-scroll"><table class="tabla">
          <thead><tr><th>Hora</th><th>Tipo</th><th>Motivo</th><th class="num">Monto</th></tr></thead>
          <tbody><?php foreach ($r['movimientos'] as $m): ?>
            <tr><td><?= e(date('H:i', strtotime($m['fecha']))) ?></td><td><?= e($m['tipo']) ?></td><td><?= e($m['motivo']) ?></td>
              <td class="num"><?= $m['tipo'] === 'RETIRO' ? '−' : '' ?><?= lps($m['monto']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </section>
    <section class="tarjeta">
      <h2>Ventas del turno por forma de pago</h2>
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Forma</th><th class="num">Facturas</th><th class="num">Total</th><th class="num">En efectivo</th></tr></thead>
        <tbody><?php foreach ($r['por_pago'] as $forma => $p): ?>
          <tr><td><?= e($forma) ?></td><td class="num"><?= (int) $p['facturas'] ?></td><td class="num"><?= lps($p['total']) ?></td><td class="num"><?= lps($p['efectivo']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$r['por_pago']): ?><tr><td colspan="4" class="tenue">Aún no hay ventas en este turno.</td></tr><?php endif; ?></tbody>
      </table></div>
      <div class="fila">
        <a class="btn" href="index.php?p=cierre&id=<?= (int) $mio['id'] ?>" target="_blank">Corte X (parcial)</a>
        <a class="btn primario" href="index.php?p=caja&cerrar=<?= (int) $mio['id'] ?>">Cerrar turno y arquear</a>
      </div>
    </section>
  </div>
<?php endif; ?>

<section class="tarjeta">
  <h2>Historial de turnos <?= $esSupervisor ? 'de la tienda' : '' ?></h2>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Cajero</th><th>Apertura</th><th>Cierre</th><th class="num">Fondo</th><th class="num">Esperado</th><th class="num">Contado</th><th class="num">Diferencia</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($historial as $h): $dif = $h['diferencia'] === null ? null : (int) $h['diferencia']; ?>
      <tr>
        <td><?= e($h['cajero']) ?></td>
        <td><?= e(date('d/m H:i', strtotime($h['apertura']))) ?></td>
        <td><?= $h['cierre'] ? e(date('d/m H:i', strtotime($h['cierre']))) : '<span class="pill ok">Abierto</span>' ?></td>
        <td class="num"><?= lps($h['fondo_inicial']) ?></td>
        <td class="num"><?= $h['efectivo_esperado'] !== null ? lps($h['efectivo_esperado']) : '—' ?></td>
        <td class="num"><?= $h['efectivo_contado'] !== null ? lps($h['efectivo_contado']) : '—' ?></td>
        <td class="num"><?php if ($dif === null): ?>—<?php elseif ($dif === 0): ?><span class="pill ok">Cuadra</span><?php else: ?><span class="pill <?= $dif < 0 ? 'mal' : 'alerta' ?>"><?= $dif < 0 ? 'Faltante' : 'Sobrante' ?> <?= lps(abs($dif)) ?></span><?php endif; ?></td>
        <td class="num">
          <a href="index.php?p=cierre&id=<?= (int) $h['id'] ?>" target="_blank">Reporte</a>
          <?php if ($h['estado'] === 'ABIERTO' && $esSupervisor && (int) $h['usuario_id'] !== $usuario['id']): ?>
            · <a href="index.php?p=caja&cerrar=<?= (int) $h['id'] ?>">Cerrar</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$historial): ?><tr><td colspan="8" class="tenue">Sin turnos todavía.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php layout_fin(); ?>
