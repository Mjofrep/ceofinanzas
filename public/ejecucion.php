<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();
$mensaje = '';
$errores = [];

$monedas = $pdo->query('SELECT id, codigo FROM ceo_monedas ORDER BY codigo')->fetchAll();
$proyectos = $pdo->query('SELECT id, codigo, nombre FROM ceo_proyectos ORDER BY nombre')->fetchAll();
$estados_detalle = [
  'Ingresado',
  'Pendiente de firmas',
  'En espera de presupuesto',
  'Observado',
  'En revision',
  'Otro'
];

$tcMensaje = '';
try {
  $fechaTc = date('Y-m-d');
  $stmtTc = $pdo->prepare('SELECT COUNT(*) FROM ceo_tipo_cambio WHERE fecha = ? AND moneda IN ("UF", "USD", "EUR")');
  $stmtTc->execute([$fechaTc]);
  $tcCount = (int)$stmtTc->fetchColumn();

  if ($tcCount < 3) {
    $json = @file_get_contents('https://mindicador.cl/api');
    if ($json === false) {
      throw new RuntimeException('No se pudo consultar API de tipos de cambio.');
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['uf']['valor']) || empty($data['dolar']['valor']) || empty($data['euro']['valor'])) {
      throw new RuntimeException('Respuesta invalida de API de tipos de cambio.');
    }

    $uf = (float)$data['uf']['valor'];
    $usd = (float)$data['dolar']['valor'];
    $eur = (float)$data['euro']['valor'];

    $stmtIns = $pdo->prepare('INSERT INTO ceo_tipo_cambio (fecha, moneda, valor_clp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor_clp = VALUES(valor_clp)');
    $stmtIns->execute([$fechaTc, 'UF', $uf]);
    $stmtIns->execute([$fechaTc, 'USD', $usd]);
    $stmtIns->execute([$fechaTc, 'EUR', $eur]);

    $tcMensaje = 'Tipos de cambio del dia cargados automaticamente.';
  }
} catch (Throwable $e) {
  $tcMensaje = 'No fue posible cargar tipos de cambio automaticamente.';
}

function limpiarMontoInput(string $value): float
{
  $value = trim($value);
  if ($value === '') {
    return 0.0;
  }
  $value = str_replace(['.', ' '], '', $value);
  $value = str_replace(',', '.', $value);
  return is_numeric($value) ? (float)$value : 0.0;
}

function normalizarNombreArchivo(string $name): string
{
  $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
  return trim($name, '_');
}

function formatearMonto(float $monto, string $moneda): string
{
  $decimales = $moneda === 'CLP' ? 0 : 2;
  return number_format($monto, $decimales, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? 'crear';
  $ordenId = (int)($_POST['orden_id'] ?? 0);
  $oc = trim($_POST['oc'] ?? '');
  $contrato = trim($_POST['contrato'] ?? '');
  $fechaEntrega = $_POST['fecha_entrega'] ?? null;
  $monedaId = (int)($_POST['moneda_id'] ?? 0);
  $pep = trim($_POST['pep'] ?? '');
  $sociedad = trim($_POST['sociedad'] ?? 'CL13');
  $proyectoId = (int)($_POST['proyecto_id'] ?? 0);
  $monto = limpiarMontoInput((string)($_POST['monto'] ?? '0'));
  $montoComprometido = limpiarMontoInput((string)($_POST['monto_comprometido'] ?? '0'));
  $estado = trim($_POST['estado'] ?? 'Registrado');
  $estadoDetalle = trim($_POST['estado_detalle'] ?? 'Ingresado');
  $estadoDetalleOtro = trim($_POST['estado_detalle_otro'] ?? '');
  $hes = trim($_POST['hes'] ?? '');
  $eliminada = isset($_POST['eliminada']) ? 1 : 0;

  if ($oc === '' || $monedaId <= 0 || $proyectoId <= 0) {
    $errores[] = 'OC, moneda y proyecto son obligatorios.';
  }

  if ($estado === 'Pagado' && $hes === '') {
    $errores[] = 'HES es obligatorio cuando el estado es Pagado.';
  }

  if ($estadoDetalle === 'Otro' && $estadoDetalleOtro === '') {
    $errores[] = 'Debe especificar el estado detalle cuando selecciona Otro.';
  }

  if ($hes !== '') {
    $estado = 'Pagado';
  }

  if (empty($errores)) {
    if ($accion === 'actualizar' && $ordenId > 0) {
      $stmtPrev = $pdo->prepare('SELECT estado, eliminada, monto, monto_comprometido FROM ceo_ordenes WHERE id = ?');
      $stmtPrev->execute([$ordenId]);
      $prev = $stmtPrev->fetch();

      $stmt = $pdo->prepare(
        'UPDATE ceo_ordenes
         SET oc = ?, contrato = ?, fecha_entrega = ?, moneda_id = ?, pep = ?, sociedad = ?, proyecto_id = ?, monto = ?, monto_comprometido = ?, estado = ?,
             estado_detalle = ?, estado_detalle_otro = ?, hes = ?, eliminada = ?
         WHERE id = ?'
      );
      $stmt->execute([
        $oc,
        $contrato ?: null,
        $fechaEntrega ?: null,
        $monedaId,
        $pep ?: null,
        $sociedad,
        $proyectoId,
        $monto,
        $montoComprometido,
        $estado,
        $estadoDetalle,
        $estadoDetalle === 'Otro' ? $estadoDetalleOtro : null,
        $hes !== '' ? $hes : null,
        $eliminada,
        $ordenId
      ]);
      $mensaje = 'Orden actualizada correctamente.';

      if ($prev) {
        $prevTemp = ($prev['estado'] !== 'Pagado' && (int)$prev['eliminada'] === 0) ? (float)$prev['monto_comprometido'] : 0.0;
        $prevDef = ($prev['estado'] === 'Pagado' && (int)$prev['eliminada'] === 0) ? (float)$prev['monto'] : 0.0;
        $newTemp = ($estado !== 'Pagado' && $eliminada === 0) ? $montoComprometido : 0.0;
        $newDef = ($estado === 'Pagado' && $eliminada === 0) ? $monto : 0.0;

        $deltaTemp = $newTemp - $prevTemp;
        $deltaDef = $newDef - $prevDef;
        $fechaMov = date('Y-m-d');

        if ($deltaTemp != 0.0) {
          $tipo = $deltaTemp > 0 ? 'ajuste_temporal' : 'reversa_temporal';
          $stmtMov = $pdo->prepare('INSERT INTO ceo_presupuesto_movimientos (orden_id, tipo, monto, fecha, estado) VALUES (?, ?, ?, ?, ?)');
          $stmtMov->execute([$ordenId, $tipo, $deltaTemp, $fechaMov, $estado]);
        }

        if ($deltaDef != 0.0) {
          $tipo = $deltaDef > 0 ? 'ajuste_definitivo' : 'reversa_definitivo';
          $stmtMov = $pdo->prepare('INSERT INTO ceo_presupuesto_movimientos (orden_id, tipo, monto, fecha, estado) VALUES (?, ?, ?, ?, ?)');
          $stmtMov->execute([$ordenId, $tipo, $deltaDef, $fechaMov, $estado]);
        }
      }
    } else {
      $stmt = $pdo->prepare(
        'INSERT INTO ceo_ordenes (oc, contrato, fecha_entrega, moneda_id, pep, sociedad, proyecto_id, monto, monto_comprometido, estado, estado_detalle, estado_detalle_otro, hes, eliminada)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $stmt->execute([
        $oc,
        $contrato ?: null,
        $fechaEntrega ?: null,
        $monedaId,
        $pep ?: null,
        $sociedad,
        $proyectoId,
        $monto,
        $montoComprometido,
        $estado,
        $estadoDetalle,
        $estadoDetalle === 'Otro' ? $estadoDetalleOtro : null,
        $hes !== '' ? $hes : null,
        $eliminada
      ]);

      $ordenId = (int)$pdo->lastInsertId();
      $fechaMov = date('Y-m-d');
      if ($montoComprometido > 0) {
        $stmtMov = $pdo->prepare('INSERT INTO ceo_presupuesto_movimientos (orden_id, tipo, monto, fecha, estado) VALUES (?, ?, ?, ?, ?)');
        $stmtMov->execute([$ordenId, 'temporal', $montoComprometido, $fechaMov, $estado]);
      }
      if ($estado === 'Pagado' && $monto > 0) {
        $stmtMov = $pdo->prepare('INSERT INTO ceo_presupuesto_movimientos (orden_id, tipo, monto, fecha, estado) VALUES (?, ?, ?, ?, ?)');
        $stmtMov->execute([$ordenId, 'definitivo', $monto, $fechaMov, $estado]);
      }

      $mensaje = 'Orden registrada correctamente.';
    }

    if (!empty($_FILES['adjuntos']['name'][0]) && $ordenId > 0) {
      $uploadsDir = __DIR__ . '/../uploads/ordenes';
      if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0775, true);
      }
      $allowed = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'];
      $maxSize = 10 * 1024 * 1024;

      foreach ($_FILES['adjuntos']['name'] as $idx => $nombre) {
        $tmp = $_FILES['adjuntos']['tmp_name'][$idx] ?? '';
        $size = (int)($_FILES['adjuntos']['size'][$idx] ?? 0);
        $error = (int)($_FILES['adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
          continue;
        }
        if ($size > $maxSize) {
          continue;
        }
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
          continue;
        }

        $safeName = normalizarNombreArchivo($nombre);
        $destName = uniqid('ord_', true) . '_' . $safeName;
        $destPath = $uploadsDir . '/' . $destName;
        if (!move_uploaded_file($tmp, $destPath)) {
          continue;
        }

        $mime = mime_content_type($destPath) ?: 'application/octet-stream';
        $stmtAdj = $pdo->prepare(
          'INSERT INTO ceo_ordenes_adjuntos (orden_id, nombre_original, ruta, tipo_mime, tamano)
           VALUES (?, ?, ?, ?, ?)'
        );
        $stmtAdj->execute([$ordenId, $nombre, '/ceofinanzas/uploads/ordenes/' . $destName, $mime, $size]);
      }
    }
  }
}

$verEliminadas = isset($_GET['ver_eliminadas']);
$whereEliminadas = $verEliminadas ? '' : 'WHERE o.eliminada = 0';

$ordenes = $pdo->query(
  "SELECT o.id, o.oc, o.contrato, o.fecha_entrega, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.estado_detalle_otro,
          o.hes, o.eliminada, o.moneda_id, o.pep, o.sociedad, o.proyecto_id, m.codigo AS moneda, p.codigo, p.nombre,
          COUNT(a.id) AS adjuntos,
          GROUP_CONCAT(CONCAT(a.ruta, '||', a.nombre_original) SEPARATOR '##') AS adjuntos_lista
   FROM ceo_ordenes o
   INNER JOIN ceo_monedas m ON m.id = o.moneda_id
   INNER JOIN ceo_proyectos p ON p.id = o.proyecto_id
   LEFT JOIN ceo_ordenes_adjuntos a ON a.orden_id = o.id
   {$whereEliminadas}
   GROUP BY o.id, o.oc, o.contrato, o.fecha_entrega, o.monto, o.monto_comprometido, o.estado, o.estado_detalle, o.estado_detalle_otro,
            o.hes, o.eliminada, o.moneda_id, o.pep, o.sociedad, o.proyecto_id, m.codigo, p.codigo, p.nombre
   ORDER BY o.id DESC
   LIMIT 50"
)->fetchAll();

$movimientosPorOrden = [];
if (!empty($ordenes)) {
  $ids = array_map(static fn($o) => (int)$o['id'], $ordenes);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmtMov = $pdo->prepare(
    "SELECT orden_id, tipo, monto, fecha, estado, creado_en
     FROM ceo_presupuesto_movimientos
     WHERE orden_id IN ({$placeholders})
     ORDER BY creado_en DESC"
  );
  $stmtMov->execute($ids);
  foreach ($stmtMov->fetchAll() as $mov) {
    $movimientosPorOrden[(int)$mov['orden_id']][] = $mov;
  }
}
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

<?php if (!empty($tcMensaje)): ?>
  <div class="alert alert-info"><?= htmlspecialchars($tcMensaje) ?></div>
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
  <form class="row g-3" method="post" enctype="multipart/form-data" id="formOrden">
    <input type="hidden" name="accion" value="crear">
    <input type="hidden" name="orden_id" id="orden_id" value="">
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
      <label class="form-label">Monto comprometido</label>
      <input type="text" class="form-control" name="monto_comprometido" placeholder="0">
    </div>
    <div class="col-md-3">
      <label class="form-label">Estado</label>
      <select class="form-select" name="estado">
        <option>Registrado</option>
        <option>Pagado</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">HES</label>
      <input type="text" class="form-control" name="hes" placeholder="HES-0001">
    </div>
    <div class="col-md-4">
      <label class="form-label">Estado detalle</label>
      <select class="form-select" name="estado_detalle">
        <?php foreach ($estados_detalle as $estado): ?>
          <option value="<?= htmlspecialchars($estado) ?>"><?= htmlspecialchars($estado) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Estado detalle (Otro)</label>
      <input type="text" class="form-control" name="estado_detalle_otro" placeholder="Describe el estado">
    </div>
    <div class="col-md-4">
      <label class="form-label">Adjuntos</label>
      <input type="file" class="form-control" name="adjuntos[]" multiple accept=".pdf,.xlsx,.xls,.csv,.doc,.docx">
      <div class="form-hint">Maximo 10MB por archivo.</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">Eliminada</label>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="eliminada" id="eliminada">
        <label class="form-check-label" for="eliminada">Marcar eliminada</label>
      </div>
    </div>
    <div class="col-12 text-end">
      <button type="submit" class="btn btn-primary" id="btnGuardarOrden" onclick="this.form.accion.value='crear'">Guardar</button>
      <button type="submit" class="btn btn-warning d-none" id="btnActualizarOrden" onclick="this.form.accion.value='actualizar'">Actualizar</button>
      <button type="button" class="btn btn-secondary d-none" id="btnCancelarOrden">Cancelar</button>
    </div>
  </form>
</div>

<div class="card p-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="h6 section-title mb-0">Ordenes Registradas</h3>
    <a class="btn btn-sm btn-outline-secondary" href="/ceofinanzas/public/ejecucion.php<?= $verEliminadas ? '' : '?ver_eliminadas=1' ?>">
      <?= $verEliminadas ? 'Ocultar eliminadas' : 'Ver eliminadas' ?>
    </a>
  </div>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>OC</th>
          <th>Proyecto</th>
          <th>Fecha entrega</th>
          <th>Moneda</th>
          <th class="text-end">Monto</th>
          <th class="text-end">Comprometido</th>
          <th>Estado</th>
          <th>Estado detalle</th>
          <th>HES</th>
          <th>Adjuntos</th>
          <th>Eliminada</th>
          <th>Acciones</th>
          <th>Historial</th>
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
              <td class="text-end"><?= formatearMonto((float)$o['monto'], (string)$o['moneda']) ?></td>
              <td class="text-end"><?= formatearMonto((float)$o['monto_comprometido'], (string)$o['moneda']) ?></td>
              <td><?= htmlspecialchars($o['estado']) ?></td>
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
              <td>
                <button type="button" class="btn btn-sm btn-outline-primary" data-orden-edit
                        data-id="<?= (int)$o['id'] ?>"
                        data-oc="<?= htmlspecialchars($o['oc']) ?>"
                        data-contrato="<?= htmlspecialchars($o['contrato'] ?? '') ?>"
                        data-fecha-entrega="<?= htmlspecialchars($o['fecha_entrega'] ?? '') ?>"
                        data-moneda-id="<?= htmlspecialchars((string)($o['moneda_id'] ?? '')) ?>"
                        data-pep="<?= htmlspecialchars($o['pep'] ?? '') ?>"
                        data-sociedad="<?= htmlspecialchars($o['sociedad'] ?? 'CL13') ?>"
                        data-proyecto-id="<?= htmlspecialchars((string)($o['proyecto_id'] ?? '')) ?>"
                        data-monto="<?= htmlspecialchars((string)$o['monto']) ?>"
                        data-monto-comprometido="<?= htmlspecialchars((string)$o['monto_comprometido']) ?>"
                        data-estado="<?= htmlspecialchars($o['estado']) ?>"
                        data-hes="<?= htmlspecialchars($o['hes'] ?? '') ?>"
                        data-estado-detalle="<?= htmlspecialchars($o['estado_detalle'] ?? 'Ingresado') ?>"
                        data-estado-detalle-otro="<?= htmlspecialchars($o['estado_detalle_otro'] ?? '') ?>"
                        data-eliminada="<?= (int)$o['eliminada'] ?>">
                  Editar
                </button>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#historial-<?= (int)$o['id'] ?>" aria-expanded="false" aria-controls="historial-<?= (int)$o['id'] ?>">
                  Ver
                </button>
              </td>
            </tr>
            <tr class="collapse" id="historial-<?= (int)$o['id'] ?>">
              <td colspan="12">
                <div class="p-3 bg-light rounded">
                  <div class="fw-semibold mb-2">Historial de movimientos</div>
                  <?php $movs = $movimientosPorOrden[(int)$o['id']] ?? []; ?>
                  <?php if (empty($movs)): ?>
                    <div class="text-secondary">Sin movimientos registrados.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm mb-0">
                        <thead>
                          <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th class="text-end">Monto</th>
                            <th>Estado</th>
                            <th>Creado</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($movs as $mov): ?>
                            <tr>
                              <td><?= htmlspecialchars($mov['fecha']) ?></td>
                              <td><?= htmlspecialchars($mov['tipo']) ?></td>
                              <td class="text-end"><?= number_format((float)$mov['monto'], 2, ',', '.') ?></td>
                              <td><?= htmlspecialchars($mov['estado']) ?></td>
                              <td><?= htmlspecialchars($mov['creado_en']) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
