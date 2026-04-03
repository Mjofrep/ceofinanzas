<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();
$anio = (int)($_GET['anio'] ?? date('Y'));

$stmt = $pdo->prepare(
  "SELECT p.id,
          p.codigo,
          p.nombre,
          COALESCE(SUM(CASE WHEN pm.mes = 1 THEN pm.monto END), 0) AS presupuesto_1,
          COALESCE(SUM(CASE WHEN pm.mes = 2 THEN pm.monto END), 0) AS presupuesto_2,
          COALESCE(SUM(CASE WHEN pm.mes = 3 THEN pm.monto END), 0) AS presupuesto_3,
          COALESCE(SUM(CASE WHEN pm.mes = 4 THEN pm.monto END), 0) AS presupuesto_4,
          COALESCE(SUM(CASE WHEN pm.mes = 5 THEN pm.monto END), 0) AS presupuesto_5,
          COALESCE(SUM(CASE WHEN pm.mes = 6 THEN pm.monto END), 0) AS presupuesto_6,
          COALESCE(SUM(CASE WHEN pm.mes = 7 THEN pm.monto END), 0) AS presupuesto_7,
          COALESCE(SUM(CASE WHEN pm.mes = 8 THEN pm.monto END), 0) AS presupuesto_8,
          COALESCE(SUM(CASE WHEN pm.mes = 9 THEN pm.monto END), 0) AS presupuesto_9,
          COALESCE(SUM(CASE WHEN pm.mes = 10 THEN pm.monto END), 0) AS presupuesto_10,
          COALESCE(SUM(CASE WHEN pm.mes = 11 THEN pm.monto END), 0) AS presupuesto_11,
          COALESCE(SUM(CASE WHEN pm.mes = 12 THEN pm.monto END), 0) AS presupuesto_12,
          COALESCE(SUM(pm.monto), 0) AS presupuesto_total
   FROM ceo_proyectos p
   LEFT JOIN ceo_presupuesto_mensual pm ON pm.proyecto_id = p.id AND pm.anio = ?
   WHERE p.codigo LIKE 'CEO%'
   GROUP BY p.id, p.codigo, p.nombre
   ORDER BY p.codigo"
);
$stmt->execute([$anio]);
$presupuestos = $stmt->fetchAll();

$stmt = $pdo->prepare(
  "SELECT o.proyecto_id,
          MONTH(COALESCE(o.fecha_contable, o.fecha_entrega)) AS mes,
          SUM(
            CASE
              WHEN m.codigo = 'CLP' THEN
                CASE WHEN o.estado = 'Pagado' THEN o.monto ELSE CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END END
              WHEN tc.valor_clp IS NOT NULL THEN
                (CASE WHEN o.estado = 'Pagado' THEN o.monto ELSE CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END END) * tc.valor_clp
              ELSE 0
            END
          ) AS ejecutado_mes,
          SUM(CASE WHEN m.codigo <> 'CLP' AND tc.valor_clp IS NULL THEN 1 ELSE 0 END) AS sin_tc
   FROM ceo_ordenes o
   INNER JOIN ceo_proyectos p ON p.id = o.proyecto_id
   INNER JOIN ceo_monedas m ON m.id = o.moneda_id
   LEFT JOIN ceo_tipo_cambio tc
     ON tc.fecha = COALESCE(o.fecha_contable, o.fecha_entrega)
    AND tc.moneda = m.codigo
   WHERE p.codigo LIKE 'CEO%'
     AND o.eliminada = 0
     AND YEAR(COALESCE(o.fecha_contable, o.fecha_entrega)) = ?
   GROUP BY o.proyecto_id, MONTH(COALESCE(o.fecha_contable, o.fecha_entrega))"
);
$stmt->execute([$anio]);
$ordenesMes = $stmt->fetchAll();

$consumos_por_proyecto = [];
$sin_tc_total = 0;
foreach ($ordenesMes as $row) {
  $proyecto_id = (int)$row['proyecto_id'];
  $mes = (int)$row['mes'];
  $consumos_por_proyecto[$proyecto_id][$mes] = (float)$row['ejecutado_mes'];
  $sin_tc_total += (int)$row['sin_tc'];
}

$meses = [
  1 => 'Enero',
  2 => 'Febrero',
  3 => 'Marzo',
  4 => 'Abril',
  5 => 'Mayo',
  6 => 'Junio',
  7 => 'Julio',
  8 => 'Agosto',
  9 => 'Septiembre',
  10 => 'Octubre',
  11 => 'Noviembre',
  12 => 'Diciembre'
];

function monto_formateado(float $monto): string
{
  return number_format($monto, 0, ',', '.');
}

$totales_mes = [];
foreach (array_keys($meses) as $mes) {
  $totales_mes[$mes] = [
    'presupuesto' => 0.0,
    'real' => 0.0,
    'diferencia' => 0.0,
  ];
}

$totales_generales = [
  'presupuesto' => 0.0,
  'real' => 0.0,
  'diferencia' => 0.0,
];
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Seguimiento Anual de Presupuesto</h2>
      <p class="text-secondary mb-0">Presupuesto, aprovisionado + pagado y diferencia por proyecto CEO.</p>
    </div>
  </div>
</div>

<div class="card p-4 mb-4">
  <form class="row g-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Ano</label>
      <input type="number" class="form-control" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Filtrar</button>
    </div>
  </form>
  <?php if ($sin_tc_total > 0): ?>
    <div class="form-hint text-danger mt-3">Hay <?= $sin_tc_total ?> registro(s) sin tipo de cambio para su fecha contable o fecha de entrega.</div>
  <?php endif; ?>
</div>

<div class="card p-4">
  <div class="table-responsive presupuesto-anual-wrapper">
    <table class="table table-striped align-middle presupuesto-anual-table">
      <thead>
        <tr>
          <th rowspan="2">Proyecto</th>
          <?php foreach ($meses as $mes_nombre): ?>
            <th colspan="3" class="text-center"><?= htmlspecialchars($mes_nombre) ?></th>
          <?php endforeach; ?>
          <th colspan="3" class="text-center">Total</th>
        </tr>
        <tr>
          <?php foreach ($meses as $_): ?>
            <th class="text-end">Pres</th>
            <th class="text-end">Real</th>
            <th class="text-end">Diferencia</th>
          <?php endforeach; ?>
          <th class="text-end">Pres</th>
          <th class="text-end">Real</th>
          <th class="text-end">Diferencia</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($presupuestos)): ?>
          <tr>
            <td colspan="40" class="text-center text-secondary">Sin registros.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($presupuestos as $row): ?>
            <?php
              $proyecto_id = (int)$row['id'];
              $total_consumido = 0.0;
              $total_presupuesto = (float)$row['presupuesto_total'];
            ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($row['codigo']) ?></div>
                <div class="small text-secondary"><?= htmlspecialchars($row['nombre']) ?></div>
              </td>
              <?php foreach (array_keys($meses) as $mes): ?>
                <?php
                  $presupuesto = (float)$row['presupuesto_' . $mes];
                  $consumido = (float)($consumos_por_proyecto[$proyecto_id][$mes] ?? 0.0);
                  $diferencia = $presupuesto - $consumido;
                  $total_consumido += $consumido;
                  $totales_mes[$mes]['presupuesto'] += $presupuesto;
                  $totales_mes[$mes]['real'] += $consumido;
                  $totales_mes[$mes]['diferencia'] += $diferencia;
                ?>
                <td class="text-end detalle-mes"><?= monto_formateado($presupuesto) ?></td>
                <td class="text-end detalle-mes"><?= monto_formateado($consumido) ?></td>
                <td class="text-end detalle-mes <?= $diferencia < 0 ? 'text-danger' : 'text-secondary' ?>"><?= monto_formateado($diferencia) ?></td>
              <?php endforeach; ?>
              <?php
                $total_diferencia = $total_presupuesto - $total_consumido;
                $totales_generales['presupuesto'] += $total_presupuesto;
                $totales_generales['real'] += $total_consumido;
                $totales_generales['diferencia'] += $total_diferencia;
              ?>
              <td class="text-end detalle-mes"><?= monto_formateado($total_presupuesto) ?></td>
              <td class="text-end detalle-mes"><?= monto_formateado($total_consumido) ?></td>
              <td class="text-end detalle-mes <?= $total_diferencia < 0 ? 'text-danger' : 'text-secondary' ?>"><?= monto_formateado($total_diferencia) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="table-secondary fw-semibold">
            <td>Total</td>
            <?php foreach (array_keys($meses) as $mes): ?>
              <td class="text-end detalle-mes"><?= monto_formateado($totales_mes[$mes]['presupuesto']) ?></td>
              <td class="text-end detalle-mes"><?= monto_formateado($totales_mes[$mes]['real']) ?></td>
              <td class="text-end detalle-mes <?= $totales_mes[$mes]['diferencia'] < 0 ? 'text-danger' : '' ?>"><?= monto_formateado($totales_mes[$mes]['diferencia']) ?></td>
            <?php endforeach; ?>
            <td class="text-end detalle-mes"><?= monto_formateado($totales_generales['presupuesto']) ?></td>
            <td class="text-end detalle-mes"><?= monto_formateado($totales_generales['real']) ?></td>
            <td class="text-end detalle-mes <?= $totales_generales['diferencia'] < 0 ? 'text-danger' : '' ?>"><?= monto_formateado($totales_generales['diferencia']) ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
  .presupuesto-anual-wrapper {
    max-height: 70vh;
    overflow: auto;
  }

  .presupuesto-anual-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f4f8ff;
    white-space: nowrap;
    border: 1px solid #cfd8e3;
  }

  .presupuesto-anual-table thead tr:nth-child(2) th {
    top: 38px;
    z-index: 3;
  }

  .presupuesto-anual-table thead th[rowspan] {
    z-index: 4;
  }

  .presupuesto-anual-table .detalle-mes {
    font-size: 0.8rem;
    white-space: nowrap;
  }

  .presupuesto-anual-table td {
    border: 1px solid #dee2e6;
    white-space: nowrap;
  }

  .presupuesto-anual-table {
    border-collapse: separate;
    border-spacing: 0;
  }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
