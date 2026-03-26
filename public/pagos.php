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
  $proveedorNombre = trim($_POST['proveedor'] ?? '');
  $proveedorTipo = trim($_POST['proveedor_tipo'] ?? 'contratista');
  $factura = trim($_POST['numero'] ?? '');
  $fecha = $_POST['fecha'] ?? '';
  $monedaId = (int)($_POST['moneda_id'] ?? 0);
  $monto = (float)str_replace(['.', ','], ['', '.'], trim($_POST['monto'] ?? '0'));
  $estado = trim($_POST['estado'] ?? 'Registrado');
  $proyectoId = (int)($_POST['proyecto_id'] ?? 0);

  if ($proveedorNombre === '' || $factura === '' || $fecha === '' || $monedaId <= 0 || $proyectoId <= 0) {
    $errores[] = 'Proveedor, factura, fecha, proyecto y moneda son obligatorios.';
  }

  if (empty($errores)) {
    $stmt = $pdo->prepare('SELECT id FROM ceo_proveedores WHERE nombre = ?');
    $stmt->execute([$proveedorNombre]);
    $proveedorId = $stmt->fetchColumn();
    if (!$proveedorId) {
      $stmt = $pdo->prepare('INSERT INTO ceo_proveedores (nombre, tipo) VALUES (?, ?)');
      $stmt->execute([$proveedorNombre, $proveedorTipo]);
      $proveedorId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
      'INSERT INTO ceo_facturas (proveedor_id, proyecto_id, numero, fecha, monto, moneda_id, estado)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$proveedorId, $proyectoId, $factura, $fecha, $monto, $monedaId, $estado]);
    $mensaje = 'Factura registrada correctamente.';
  }
}

$facturas = $pdo->query(
  'SELECT f.numero, f.fecha, f.monto, f.estado, m.codigo AS moneda,
          pr.nombre AS proveedor, p.codigo, p.nombre AS proyecto
   FROM ceo_facturas f
   INNER JOIN ceo_monedas m ON m.id = f.moneda_id
   INNER JOIN ceo_proveedores pr ON pr.id = f.proveedor_id
   INNER JOIN ceo_proyectos p ON p.id = f.proyecto_id
   ORDER BY f.id DESC
   LIMIT 50'
)->fetchAll();
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Pagos y Facturas</h2>
      <p class="text-secondary mb-0">Control de pagos por contratistas y colegios.</p>
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
    <div class="col-md-4">
      <label class="form-label">Proveedor</label>
      <input type="text" class="form-control" name="proveedor" placeholder="Nombre proveedor" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Tipo</label>
      <select class="form-select" name="proveedor_tipo">
        <option value="contratista">Contratista</option>
        <option value="colegio">Colegio</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Numero factura</label>
      <input type="text" class="form-control" name="numero" placeholder="F-0001" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Fecha factura</label>
      <input type="date" class="form-control" name="fecha" required>
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
    <div class="col-md-2">
      <label class="form-label">Moneda</label>
      <select class="form-select" name="moneda_id" required>
        <option value="">Seleccione...</option>
        <?php foreach ($monedas as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['codigo']) ?></option>
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
  <h3 class="h6 section-title mb-3">Facturas Registradas</h3>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>Proveedor</th>
          <th>Proyecto</th>
          <th>Factura</th>
          <th>Fecha</th>
          <th>Moneda</th>
          <th class="text-end">Monto</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($facturas)): ?>
          <tr>
            <td colspan="7" class="text-center text-secondary">Sin registros.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($facturas as $f): ?>
            <tr>
              <td><?= htmlspecialchars($f['proveedor']) ?></td>
              <td><?= htmlspecialchars(trim($f['codigo'] . ' ' . $f['proyecto'])) ?></td>
              <td><?= htmlspecialchars($f['numero']) ?></td>
              <td><?= htmlspecialchars($f['fecha']) ?></td>
              <td><?= htmlspecialchars($f['moneda']) ?></td>
              <td class="text-end"><?= number_format((float)$f['monto'], 0, ',', '.') ?></td>
              <td><span class="badge text-bg-secondary"><?= htmlspecialchars($f['estado']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
