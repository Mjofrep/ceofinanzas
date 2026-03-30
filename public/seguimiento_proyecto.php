<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$anio = (int)($_GET['anio'] ?? 2026);
$proyectoId = (int)($_GET['proyecto_id'] ?? 0);
$mensaje = '';
$errores = [];

$proyectos = $pdo->query('SELECT id, codigo, nombre FROM ceo_proyectos ORDER BY codigo')->fetchAll();

$presupuestoTotal = 0.0;
$comprometidoClp = 0.0;
$ejecutadoClp = 0.0;
$disponibleClp = 0.0;
$resumenMonedas = [];
$ordenes = [];

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

  $stmt = $pdo->prepare(
    "SELECT
        SUM(CASE
              WHEN m.codigo = 'CLP' THEN o.monto_comprometido
              WHEN tc.valor_clp IS NOT NULL THEN o.monto_comprometido * tc.valor_clp
              ELSE 0
            END) AS comprometido_clp,
        SUM(CASE
              WHEN m.codigo = 'CLP' THEN o.monto
              WHEN tc.valor_clp IS NOT NULL THEN o.monto * tc.valor_clp
              ELSE 0
            END) AS ejecutado_clp,
        SUM(CASE WHEN m.codigo <> 'CLP' AND tc.valor_clp IS NULL THEN 1 ELSE 0 END) AS sin_tc
     FROM ceo_ordenes o
     INNER JOIN ceo_monedas m ON m.id = o.moneda_id
     LEFT JOIN ceo_tipo_cambio tc ON tc.fecha = o.fecha_entrega AND tc.moneda = m.codigo
     WHERE o.proyecto_id = ? AND o.eliminada = 0"
  );
  $stmt->execute([$proyectoId]);
  $calc = $stmt->fetch() ?: [];
  $comprometidoClp = (float)($calc['comprometido_clp'] ?? 0);
  $ejecutadoClp = (float)($calc['ejecutado_clp'] ?? 0);
  $sinTc = (int)($calc['sin_tc'] ?? 0);

  $disponibleClp = $presupuestoTotal - $comprometidoClp - $ejecutadoClp;

  $stmt = $pdo->prepare(
    "SELECT o.oc, o.fecha_entrega, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.hes, m.codigo AS moneda,
            tc.valor_clp AS tc_valor
     FROM ceo_ordenes o
     INNER JOIN ceo_monedas m ON m.id = o.moneda_id
     LEFT JOIN ceo_tipo_cambio tc ON tc.fecha = o.fecha_entrega AND tc.moneda = m.codigo
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
    <div class="col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Ver</button>
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
    <div class="form-hint">Conversión a CLP usa el tipo de cambio de la fecha de entrega de cada orden.</div>
    <?php if (!empty($sinTc) && $sinTc > 0): ?>
      <div class="form-hint text-danger">Hay <?= (int)$sinTc ?> orden(es) sin tipo de cambio registrado en su fecha de entrega.</div>
    <?php endif; ?>
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
            <th class="text-end">TC (CLP)</th>
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
                <td class="text-end"><?= $o['moneda'] === 'CLP' ? '-' : number_format((float)($o['tc_valor'] ?? 0), 2, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
