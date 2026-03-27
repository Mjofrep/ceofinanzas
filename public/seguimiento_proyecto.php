<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$anio = (int)($_GET['anio'] ?? 2026);
$proyectoId = (int)($_GET['proyecto_id'] ?? 0);
$fechaTc = $_GET['fecha_tc'] ?? date('Y-m-d');
$mensaje = '';
$errores = [];

$proyectos = $pdo->query('SELECT id, codigo, nombre FROM ceo_proyectos ORDER BY codigo')->fetchAll();

$presupuestoTotal = 0.0;
$comprometidoClp = 0.0;
$ejecutadoClp = 0.0;
$disponibleClp = 0.0;
$resumenMonedas = [];
$ordenes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_tc') {
  $fechaTc = $_POST['fecha_tc'] ?? date('Y-m-d');
  $tcUf = (float)str_replace([',', ' '], ['.', ''], trim($_POST['tc_uf'] ?? '0'));
  $tcUsd = (float)str_replace([',', ' '], ['.', ''], trim($_POST['tc_usd'] ?? '0'));
  $tcEur = (float)str_replace([',', ' '], ['.', ''], trim($_POST['tc_eur'] ?? '0'));

  if ($tcUf <= 0 || $tcUsd <= 0 || $tcEur <= 0) {
    $errores[] = 'Debe ingresar tipo de cambio valido para UF, USD y EUR.';
  }

  if (empty($errores)) {
    $stmt = $pdo->prepare('INSERT INTO ceo_tipo_cambio (fecha, moneda, valor_clp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor_clp = VALUES(valor_clp)');
    $stmt->execute([$fechaTc, 'UF', $tcUf]);
    $stmt->execute([$fechaTc, 'USD', $tcUsd]);
    $stmt->execute([$fechaTc, 'EUR', $tcEur]);
    $mensaje = 'Tipo de cambio guardado.';
  }
}

if ($proyectoId > 0) {
  $stmt = $pdo->prepare('SELECT SUM(monto) AS total FROM ceo_presupuesto_mensual WHERE proyecto_id = ? AND anio = ?');
  $stmt->execute([$proyectoId, $anio]);
  $presupuestoTotal = (float)($stmt->fetchColumn() ?: 0.0);

  $stmt = $pdo->prepare(
    "SELECT m.codigo AS moneda,
            SUM(CASE WHEN o.estado <> 'Pagado' AND o.eliminada = 0 THEN o.monto_comprometido ELSE 0 END) AS comprometido,
            SUM(CASE WHEN o.estado = 'Pagado' AND o.eliminada = 0 THEN o.monto ELSE 0 END) AS ejecutado
     FROM ceo_ordenes o
     INNER JOIN ceo_monedas m ON m.id = o.moneda_id
     WHERE o.proyecto_id = ?
     GROUP BY m.codigo
     ORDER BY m.codigo"
  );
  $stmt->execute([$proyectoId]);
  $resumenMonedas = $stmt->fetchAll();

  $stmt = $pdo->prepare('SELECT moneda, valor_clp FROM ceo_tipo_cambio WHERE fecha = ?');
  $stmt->execute([$fechaTc]);
  $tc = [];
  foreach ($stmt->fetchAll() as $row) {
    $tc[$row['moneda']] = (float)$row['valor_clp'];
  }

  foreach ($resumenMonedas as $row) {
    $moneda = $row['moneda'];
    $comp = (float)$row['comprometido'];
    $eje = (float)$row['ejecutado'];
    if ($moneda === 'CLP') {
      $comprometidoClp += $comp;
      $ejecutadoClp += $eje;
    } elseif (isset($tc[$moneda]) && $tc[$moneda] > 0) {
      $comprometidoClp += $comp * $tc[$moneda];
      $ejecutadoClp += $eje * $tc[$moneda];
    }
  }

  $disponibleClp = $presupuestoTotal - $comprometidoClp - $ejecutadoClp;

  $stmt = $pdo->prepare(
    "SELECT o.oc, o.fecha_entrega, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.hes, m.codigo AS moneda
     FROM ceo_ordenes o
     INNER JOIN ceo_monedas m ON m.id = o.moneda_id
     WHERE o.proyecto_id = ? AND o.eliminada = 0
     ORDER BY o.id DESC"
  );
  $stmt->execute([$proyectoId]);
  $ordenes = $stmt->fetchAll();
}

function formatearMonto(float $monto, string $moneda): string
{
  $decimales = $moneda === 'CLP' ? 0 : 2;
  return number_format($monto, $decimales, ',', '.');
}
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Seguimiento de Presupuesto por Proyecto</h2>
      <p class="text-secondary mb-0">Presupuesto CLP y ejecución por moneda.</p>
    </div>
  </div>
</div>

<div class="card p-4 mb-4">
  <form class="row g-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Ano</label>
      <input type="number" class="form-control" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100">
    </div>
    <div class="col-md-6">
      <label class="form-label">Proyecto</label>
      <select class="form-select" name="proyecto_id" required>
        <option value="0">Seleccione...</option>
        <?php foreach ($proyectos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $proyectoId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($p['codigo'] . ' ' . $p['nombre'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Fecha tipo de cambio</label>
      <input type="date" class="form-control" name="fecha_tc" value="<?= htmlspecialchars($fechaTc) ?>">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Ver</button>
    </div>
  </form>
</div>

<?php if (!empty($mensaje)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errores as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card p-4 mb-4">
  <h3 class="h6 section-title mb-3">Registrar tipo de cambio (CLP)</h3>
  <form class="row g-3" method="post">
    <input type="hidden" name="accion" value="guardar_tc">
    <div class="col-md-3">
      <label class="form-label">Fecha</label>
      <input type="date" class="form-control" name="fecha_tc" value="<?= htmlspecialchars($fechaTc) ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">UF</label>
      <input type="text" class="form-control" name="tc_uf" placeholder="0">
    </div>
    <div class="col-md-3">
      <label class="form-label">USD</label>
      <input type="text" class="form-control" name="tc_usd" placeholder="0">
    </div>
    <div class="col-md-3">
      <label class="form-label">EUR</label>
      <input type="text" class="form-control" name="tc_eur" placeholder="0">
    </div>
    <div class="col-12 text-end">
      <button type="submit" class="btn btn-outline-primary">Guardar tipo de cambio</button>
    </div>
  </form>
</div>

<?php if ($proyectoId > 0): ?>
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card p-3">
        <div class="text-secondary small">Presupuesto CLP</div>
        <div class="h5 mb-0"><?= number_format($presupuestoTotal, 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="text-secondary small">Comprometido CLP</div>
        <div class="h5 mb-0"><?= number_format($comprometidoClp, 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="text-secondary small">Ejecutado CLP</div>
        <div class="h5 mb-0"><?= number_format($ejecutadoClp, 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="text-secondary small">Disponible CLP</div>
        <div class="h5 mb-0"><?= number_format($disponibleClp, 0, ',', '.') ?></div>
      </div>
    </div>
  </div>

  <div class="card p-4 mb-4">
    <h3 class="h6 section-title mb-3">Resumen por Moneda</h3>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>Moneda</th>
            <th class="text-end">Comprometido</th>
            <th class="text-end">Ejecutado</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($resumenMonedas)): ?>
            <tr><td colspan="3" class="text-center text-secondary">Sin ordenes.</td></tr>
          <?php else: ?>
            <?php foreach ($resumenMonedas as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['moneda']) ?></td>
                <td class="text-end"><?= formatearMonto((float)$r['comprometido'], (string)$r['moneda']) ?></td>
                <td class="text-end"><?= formatearMonto((float)$r['ejecutado'], (string)$r['moneda']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="form-hint">Conversión a CLP usa tipo de cambio de la fecha seleccionada.</div>
  </div>

  <div class="card p-4">
    <h3 class="h6 section-title mb-3">Ordenes del Proyecto</h3>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>OC</th>
            <th>Fecha entrega</th>
            <th>Moneda</th>
            <th class="text-end">Monto</th>
            <th class="text-end">Comprometido</th>
            <th>Estado</th>
            <th>Detalle</th>
            <th>HES</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($ordenes)): ?>
            <tr><td colspan="8" class="text-center text-secondary">Sin ordenes.</td></tr>
          <?php else: ?>
            <?php foreach ($ordenes as $o): ?>
              <tr>
                <td><?= htmlspecialchars($o['oc']) ?></td>
                <td><?= htmlspecialchars($o['fecha_entrega'] ?? '-') ?></td>
                <td><?= htmlspecialchars($o['moneda']) ?></td>
                <td class="text-end"><?= formatearMonto((float)$o['monto'], (string)$o['moneda']) ?></td>
                <td class="text-end"><?= formatearMonto((float)$o['monto_comprometido'], (string)$o['moneda']) ?></td>
                <td><?= htmlspecialchars($o['estado']) ?></td>
                <td><?= htmlspecialchars($o['estado_detalle'] ?? '-') ?></td>
                <td><?= htmlspecialchars($o['hes'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
