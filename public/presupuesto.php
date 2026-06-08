<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$anio = (int)($_GET['anio'] ?? 2026);
$areaId = (int)($_GET['area_id'] ?? 0);
$proyectoId = (int)($_GET['proyecto_id'] ?? 0);
$tipoClase = $_GET['tipo'] ?? '';

$areas = $pdo->query('SELECT id, nombre FROM ceo_areas ORDER BY nombre')->fetchAll();
$proyectos = $pdo->query('SELECT id, codigo, nombre FROM ceo_proyectos ORDER BY nombre')->fetchAll();

$clases = $pdo->query('SELECT DISTINCT tipo FROM ceo_clase_costo ORDER BY tipo')->fetchAll();

$params = ['anio' => $anio];
$where = 'pm.anio = :anio';

if ($areaId > 0) {
  $where .= ' AND pm.area_id = :area_id';
  $params['area_id'] = $areaId;
}

if ($proyectoId > 0) {
  $where .= ' AND pm.proyecto_id = :proyecto_id';
  $params['proyecto_id'] = $proyectoId;
}

if ($tipoClase !== '') {
  $where .= ' AND cc.tipo = :tipo';
  $params['tipo'] = $tipoClase;
}

$sql = "
  SELECT a.nombre AS area,
         p.codigo,
         p.nombre AS proyecto,
         pm.ceco,
         cc.tipo AS clase,
         SUM(pm.monto) AS total,
         COALESCE(o.comprometido, 0) AS comprometido,
         COALESCE(o.real_fecha, 0) AS real_fecha
  FROM ceo_presupuesto_mensual pm
  INNER JOIN ceo_areas a ON a.id = pm.area_id
  INNER JOIN ceo_proyectos p ON p.id = pm.proyecto_id
  INNER JOIN ceo_clase_costo cc ON cc.id = pm.clase_costo_id
   LEFT JOIN (
     SELECT proyecto_id,
            SUM(CASE
                  WHEN o.estado <> 'Pagado' AND o.eliminada = 0 THEN
                    CASE
                      WHEN m.codigo = 'CLP' THEN
                        CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END
                      WHEN tc.valor_clp IS NOT NULL THEN
                        (CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END) * tc.valor_clp
                      ELSE 0
                    END
                  ELSE 0
                END) AS comprometido,
            SUM(CASE
                  WHEN o.estado = 'Pagado'
                    AND o.eliminada = 0
                    AND COALESCE(o.fecha_contable, o.fecha_entrega) <= CURDATE() THEN
                    CASE
                      WHEN m.codigo = 'CLP' THEN o.monto
                      WHEN tc.valor_clp IS NOT NULL THEN o.monto * tc.valor_clp
                      ELSE 0
                    END
                  ELSE 0
                END) AS real_fecha
     FROM ceo_ordenes o
     INNER JOIN ceo_monedas m ON m.id = o.moneda_id
     LEFT JOIN ceo_tipo_cambio tc ON tc.fecha = COALESCE(o.fecha_contable, o.fecha_entrega) AND tc.moneda = m.codigo
     GROUP BY proyecto_id
   ) o ON o.proyecto_id = pm.proyecto_id
  WHERE {$where}
  GROUP BY a.nombre, p.codigo, p.nombre, pm.ceco, cc.tipo, o.comprometido, o.real_fecha
  ORDER BY a.nombre, p.nombre
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Presupuesto por Area y Proyecto</h2>
      <p class="text-secondary mb-0">Vista consolidada con filtros por ano, area, proyecto y clase.</p>
    </div>
    <a href="/ceofinanzas/public/import_presupuesto.php" class="btn btn-outline-primary btn-sm">Importar Excel</a>
  </div>
</div>

<div class="card p-4 mb-4">
  <form class="row g-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Año</label>
      <input type="number" class="form-control" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100">
    </div>
    <div class="col-md-3">
      <label class="form-label">Area</label>
      <select class="form-select" name="area_id">
        <option value="0">Todos</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $areaId === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Proyecto</label>
      <select class="form-select" name="proyecto_id">
        <option value="0">Todos</option>
        <?php foreach ($proyectos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $proyectoId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['codigo'] . ' ' . $p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Clase</label>
      <select class="form-select" name="tipo">
        <option value="">Todas</option>
        <?php foreach ($clases as $c): ?>
          <option value="<?= htmlspecialchars($c['tipo']) ?>" <?= $tipoClase === $c['tipo'] ? 'selected' : '' ?>><?= htmlspecialchars($c['tipo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 text-end">
      <button type="submit" class="btn btn-primary">Aplicar filtros</button>
    </div>
  </form>
</div>

<div class="card p-4">
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead>
        <tr>
          <th>Area</th>
          <th>Proyecto</th>
          <th>CECO</th>
          <th>Clase</th>
          <th class="text-end">Total</th>
          <th class="text-end">Total comprometido</th>
          <th class="text-end">Real a la fecha</th>
          <th class="text-end">Disponible</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="text-center text-secondary">Sin registros.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $total = (float)$r['total'];
              $comprometido = (float)$r['comprometido'];
              $realFecha = (float)$r['real_fecha'];
              $disponible = $total - $comprometido - $realFecha;
            ?>
            <tr>
              <td><?= htmlspecialchars($r['area']) ?></td>
              <td><?= htmlspecialchars(trim($r['codigo'] . ' ' . $r['proyecto'])) ?></td>
              <td><?= htmlspecialchars($r['ceco'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['clase']) ?></td>
              <td class="text-end"><?= number_format($total, 0, ',', '.') ?></td>
              <td class="text-end"><?= number_format($comprometido, 0, ',', '.') ?></td>
              <td class="text-end"><?= number_format($realFecha, 0, ',', '.') ?></td>
              <td class="text-end"><?= number_format($disponible, 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
