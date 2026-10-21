<?php
use App\Support\Auth;
use App\Support\Money;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = $db->prepare(
        'UPDATE descuentos_legales SET porcentaje = ?, tope_centavos = ?, acumulable_promos = ?, exento_isv = ?,
                requiere_documento = ?, activo = ? WHERE id = ?'
    );
    foreach ($_POST['d'] ?? [] as $id => $d) {
        $st->execute([
            min(100, max(0, (float) $d['porcentaje'])), Money::aCentavos(($d['tope'] ?? '') ?: 0),
            (int) !empty($d['acumulable']), (int) !empty($d['exento']), (int) !empty($d['documento']),
            (int) !empty($d['activo']), (int) $id,
        ]);
    }
    redirigir('descuentos', 'Descuentos de ley actualizados.');
}

$reglas = $db->query('SELECT * FROM descuentos_legales ORDER BY id')->fetchAll();
$otorgado = $db->query(
    "SELECT d.nombre, COUNT(v.id) AS facturas, COALESCE(SUM(v.descuento_legal), 0) AS monto
     FROM descuentos_legales d LEFT JOIN ventas v ON v.descuento_legal_id = d.id AND v.estado = 'EMITIDA'
       AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY d.id, d.nombre ORDER BY d.id"
)->fetchAll();

require __DIR__ . '/_layout.php';
layout_inicio('Descuentos de ley', 'descuentos', $db);
?>
<h1>Descuentos de ley · Honduras</h1>
<p class="aviso">Los porcentajes, topes y el tratamiento de ISV son <b>parámetros</b>. Antes de producción valídelos con su
  asesor legal/contable y la normativa vigente (Ley del Adulto Mayor y Jubilados, reformas de cuarta edad,
  Ley de personas con discapacidad, disposiciones de la SAR).</p>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
  <div class="tabla-scroll">
  <table class="tabla">
    <thead><tr><th>Beneficio</th><th>%</th><th>Tope consumo (L)</th><th>Acumula con promos</th><th>Exento ISV</th><th>Pide DNI/carné</th><th>Activo</th></tr></thead>
    <tbody>
    <?php foreach ($reglas as $r): $n = "d[{$r['id']}]"; ?>
      <tr>
        <td><b><?= e($r['nombre']) ?></b><?= $r['edad_minima'] ? ' <small>(' . (int) $r['edad_minima'] . '+ años)</small>' : '' ?><br>
          <small class="tenue"><?= e($r['base_legal']) ?></small></td>
        <td><input class="corto" name="<?= $n ?>[porcentaje]" value="<?= e((float) $r['porcentaje']) ?>" inputmode="decimal"></td>
        <td><input class="corto" name="<?= $n ?>[tope]" value="<?= $r['tope_centavos'] ? e($r['tope_centavos'] / 100) : '' ?>" placeholder="Sin tope" inputmode="decimal"></td>
        <td><input type="checkbox" name="<?= $n ?>[acumulable]" <?= $r['acumulable_promos'] ? 'checked' : '' ?>></td>
        <td><input type="checkbox" name="<?= $n ?>[exento]" <?= $r['exento_isv'] ? 'checked' : '' ?>></td>
        <td><input type="checkbox" name="<?= $n ?>[documento]" <?= $r['requiere_documento'] ? 'checked' : '' ?>></td>
        <td><input type="checkbox" name="<?= $n ?>[activo]" <?= $r['activo'] ? 'checked' : '' ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button class="btn primario">Guardar</button>
</form>

<h2>Otorgado este mes (todas las tiendas)</h2>
<div class="kpis">
  <?php foreach ($otorgado as $o): ?>
    <div class="kpi"><span><?= e($o['nombre']) ?></span><b><?= lps($o['monto']) ?></b><small><?= (int) $o['facturas'] ?> facturas</small></div>
  <?php endforeach; ?>
</div>
<p class="tenue chico"><b>No acumulable</b>: si la línea ya tiene promoción, el cliente recibe el mayor de los dos beneficios.
  <b>Exento ISV</b>: el consumo del beneficiario se factura como importe exento (sin ISV).</p>
<?php layout_fin(); ?>
