<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();
$mensaje = '';
$errores = [];

$monedas = $pdo->query('SELECT id, codigo FROM ceo_monedas ORDER BY codigo')->fetchAll();
$proyectos = $pdo->query('SELECT id, codigo, nombre FROM ceo_proyectos ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $oc = trim($_POST['oc'] ?? '');
  $contrato = trim($_POST['contrato'] ?? '');
  $fechaEntrega = $_POST['fecha_entrega'] ?? null;
  $monedaId = (int)($_POST['moneda_id'] ?? 0);
  $pep = trim($_POST['pep'] ?? '');
  $sociedad = trim($_POST['sociedad'] ?? 'CL13');
  $proyectoId = (int)($_POST['proyecto_id'] ?? 0);
  $monto = (float)str_replace(['.', ','], ['', '.'], trim($_POST['monto'] ?? '0'));
  $estado = trim($_POST['estado'] ?? 'Registrado');

  if ($oc === '' || $monedaId <= 0 || $proyectoId <= 0) {
    $errores[] = 'OC, moneda y proyecto son obligatorios.';
  }

  if (empty($errores)) {
    $stmt = $pdo->prepare(
      'INSERT INTO ceo_ordenes (oc, contrato, fecha_entrega, moneda_id, pep, sociedad, proyecto_id, monto, estado)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$oc, $contrato ?: null, $fechaEntrega ?: null, $monedaId, $pep ?: null, $sociedad, $proyectoId, $monto, $estado]);
    $mensaje = 'Orden registrada correctamente.';
  }
}

$ordenes = $pdo->query(
  'SELECT o.oc, o.contrato, o.fecha_entrega, o.monto, o.estado, m.codigo AS moneda,
          p.codigo, p.nombre
   FROM ceo_ordenes o
   INNER JOIN ceo_monedas m ON m.id = o.moneda_id
   INNER JOIN ceo_proyectos p ON p.id = o.proyecto_id
   ORDER BY o.id DESC
   LIMIT 50'
)->fetchAll();
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Registro de Ejecucion Real</h2>
      <p class="text-secondary mb-0">Registra ordenes de compra y rebaja de presupuesto por estado.</p>
    </div>
  </div>
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
  <form class="row g-3" method="post">
    <div class="col-md-3">
      <label class="form-label">Numero OC</label>
      <input type="text" class="form-control" name="oc" placeholder="OC-0001" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Contrato</label>
      <input type="text" class="form-control" name="contrato" placeholder="CTR-2026-01">
    </div>
    <div class="col-md-3">
      <label class="form-label">Fecha entrega</label>
      <input type="date" class="form-control" name="fecha_entrega">
    </div>
    <div class="col-md-3">
      <label class="form-label">Moneda</label>
      <select class="form-select" name="moneda_id" required>
        <option value="">Seleccione...</option>
        <?php foreach ($monedas as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['codigo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Elemento PEP</label>
      <input type="text" class="form-control" name="pep" placeholder="PEP-001">
    </div>
    <div class="col-md-4">
      <label class="form-label">Sociedad</label>
      <input type="text" class="form-control" name="sociedad" value="CL13">
    </div>
    <div class="col-md-4">
      <label class="form-label">Proyecto</label>
      <select class="form-select" name="proyecto_id" required>
        <option value="">Seleccione...</option>
        <?php foreach ($proyectos as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars(trim($p['codigo'] . ' ' . $p['nombre'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Monto</label>
      <input type="text" class="form-control" name="monto" placeholder="0">
    </div>
    <div class="col-md-3">
      <label class="form-label">Estado</label>
      <select class="form-select" name="estado">
        <option>Registrado</option>
        <option>Pagado</option>
      </select>
    </div>
    <div class="col-12 text-end">
      <button type="submit" class="btn btn-primary">Guardar</button>
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
          <th>Moneda</th>
          <th class="text-end">Monto</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ordenes)): ?>
          <tr>
            <td colspan="6" class="text-center text-secondary">Sin registros.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($ordenes as $o): ?>
            <tr>
              <td><?= htmlspecialchars($o['oc']) ?></td>
              <td><?= htmlspecialchars(trim($o['codigo'] . ' ' . $o['nombre'])) ?></td>
              <td><?= htmlspecialchars($o['fecha_entrega'] ?? '-') ?></td>
              <td><?= htmlspecialchars($o['moneda']) ?></td>
              <td class="text-end"><?= number_format((float)$o['monto'], 0, ',', '.') ?></td>
              <td><?= htmlspecialchars($o['estado']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
