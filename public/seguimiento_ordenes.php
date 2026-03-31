<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$fechaDesde = $_GET['fecha_desde'] ?? '';
$fechaHasta = $_GET['fecha_hasta'] ?? '';

$params = [];
$where = [];

if ($fechaDesde !== '') {
  $where[] = 'o.fecha_entrega >= ?';
  $params[] = $fechaDesde;
}
if ($fechaHasta !== '') {
  $where[] = 'o.fecha_entrega <= ?';
  $params[] = $fechaHasta;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
  "SELECT o.id, o.oc, o.contrato, o.fecha_entrega, o.fecha_contable, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.estado_detalle_otro,
          o.hes, o.eliminada, o.tipo_presupuesto, o.observacion, m.codigo AS moneda, p.codigo, p.nombre,
          COUNT(a.id) AS adjuntos,
          GROUP_CONCAT(CONCAT(a.ruta, '||', a.nombre_original) SEPARATOR '##') AS adjuntos_lista
   FROM ceo_ordenes o
   INNER JOIN ceo_monedas m ON m.id = o.moneda_id
   INNER JOIN ceo_proyectos p ON p.id = o.proyecto_id
   LEFT JOIN ceo_ordenes_adjuntos a ON a.orden_id = o.id
   {$whereSql}
   GROUP BY o.id, o.oc, o.contrato, o.fecha_entrega, o.fecha_contable, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.estado_detalle_otro,
            o.hes, o.eliminada, o.tipo_presupuesto, o.observacion, m.codigo, p.codigo, p.nombre
   ORDER BY o.fecha_entrega DESC, o.id DESC"
);
$stmt->execute($params);
$ordenes = $stmt->fetchAll();

function formatearMonto(float $monto, string $moneda): string
{
  $decimales = $moneda === 'CLP' ? 0 : 2;
  return number_format($monto, $decimales, ',', '.');
}
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Seguimiento de Ordenes de Compra</h2>
      <p class="text-secondary mb-0">Filtra por fecha de entrega.</p>
    </div>
  </div>
</div>

<div class="card p-4 mb-4">
  <form class="row g-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Fecha desde</label>
      <input type="date" class="form-control" name="fecha_desde" value="<?= htmlspecialchars($fechaDesde) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Fecha hasta</label>
      <input type="date" class="form-control" name="fecha_hasta" value="<?= htmlspecialchars($fechaHasta) ?>">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Filtrar</button>
    </div>
  </form>
</div>

<div class="card p-4">
  <h3 class="h6 section-title mb-3">Ordenes Registradas</h3>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>OC</th>
          <th>Proyecto</th>
          <th>Fecha entrega</th>
          <th>Fecha contable</th>
          <th>Moneda</th>
          <th class="text-end">Monto</th>
          <th class="text-end">Comprometido</th>
          <th>Estado</th>
          <th>Tipo</th>
          <th>Observacion</th>
          <th>Estado detalle</th>
          <th>HES</th>
          <th>Adjuntos</th>
          <th>Eliminada</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ordenes)): ?>
          <tr>
            <td colspan="14" class="text-center text-secondary">Sin registros.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($ordenes as $o): ?>
            <tr>
              <td><?= htmlspecialchars($o['oc']) ?></td>
              <td><?= htmlspecialchars(trim($o['codigo'] . ' ' . $o['nombre'])) ?></td>
              <td><?= htmlspecialchars($o['fecha_entrega'] ?? '-') ?></td>
              <td><?= htmlspecialchars($o['fecha_contable'] ?? '-') ?></td>
              <td><?= htmlspecialchars($o['moneda']) ?></td>
              <td class="text-end"><?= formatearMonto((float)$o['monto'], (string)$o['moneda']) ?></td>
              <td class="text-end"><?= formatearMonto((float)$o['monto_comprometido'], (string)$o['moneda']) ?></td>
              <td><?= htmlspecialchars($o['estado']) ?></td>
              <td><?= htmlspecialchars($o['tipo_presupuesto'] ?? 'OPEX') ?></td>
              <td><?= htmlspecialchars($o['observacion'] ?? '-') ?></td>
              <td><?= htmlspecialchars($o['estado_detalle'] === 'Otro' ? ($o['estado_detalle_otro'] ?? 'Otro') : ($o['estado_detalle'] ?? '-')) ?></td>
              <td><?= htmlspecialchars($o['hes'] ?? '-') ?></td>
              <td>
                <?php if ((int)$o['adjuntos'] > 0 && !empty($o['adjuntos_lista'])): ?>
                  <?php
                    $items = explode('##', (string)$o['adjuntos_lista']);
                    foreach ($items as $item) {
                      [$ruta, $nombre] = array_pad(explode('||', $item, 2), 2, '');
                      if ($ruta === '') {
                        continue;
                      }
                      $nombre = $nombre !== '' ? $nombre : 'Adjunto';
                      echo '<a class="d-block" href="' . htmlspecialchars($ruta) . '" target="_blank">' . htmlspecialchars($nombre) . '</a>';
                    }
                  ?>
                <?php else: ?>
                  <span class="text-secondary">-</span>
                <?php endif; ?>
              </td>
              <td><?= (int)$o['eliminada'] === 1 ? 'Si' : 'No' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
