<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes = (int)($_GET['mes'] ?? date('n'));
if ($mes < 1 || $mes > 12) {
  $mes = (int)date('n');
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
  12 => 'Diciembre',
];

function monto_formateado(float $monto): string
{
  return number_format($monto, 0, ',', '.');
}

$stmt = $pdo->prepare(
  "SELECT p.id, p.codigo, p.nombre,
          COALESCE(pm.presupuesto, 0) AS presupuesto,
          COALESCE(o.comprometido, 0) AS comprometido,
          COALESCE(o.pagado, 0) AS pagado
   FROM ceo_proyectos p
   LEFT JOIN (
     SELECT proyecto_id, SUM(monto) AS presupuesto
     FROM ceo_presupuesto_mensual
     WHERE anio = ? AND mes <= ?
     GROUP BY proyecto_id
   ) pm ON pm.proyecto_id = p.id
   LEFT JOIN (
     SELECT o.proyecto_id,
            SUM(CASE
                  WHEN o.estado <> 'Pagado' AND o.eliminada = 0 THEN
                    CASE
                      WHEN m.codigo = 'CLP' THEN
                        (CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END)
                      WHEN tc.valor_clp IS NOT NULL THEN
                        (CASE WHEN o.monto_comprometido > 0 THEN o.monto_comprometido ELSE o.monto END) * tc.valor_clp
                      ELSE 0
                    END
                  ELSE 0
                END) AS comprometido,
            SUM(CASE
                  WHEN o.estado = 'Pagado' AND o.eliminada = 0 THEN
                    CASE
                      WHEN m.codigo = 'CLP' THEN o.monto
                      WHEN tc.valor_clp IS NOT NULL THEN o.monto * tc.valor_clp
                      ELSE 0
                    END
                  ELSE 0
                END) AS pagado
     FROM ceo_ordenes o
      INNER JOIN ceo_monedas m ON m.id = o.moneda_id
      LEFT JOIN ceo_tipo_cambio tc ON tc.fecha = COALESCE(o.fecha_contable, o.fecha_entrega) AND tc.moneda = m.codigo
      WHERE YEAR(COALESCE(o.fecha_contable, o.fecha_entrega)) = ?
        AND MONTH(COALESCE(o.fecha_contable, o.fecha_entrega)) <= ?
     GROUP BY o.proyecto_id
   ) o ON o.proyecto_id = p.id
   WHERE p.codigo LIKE 'CEO%'
   ORDER BY p.codigo"
);
$stmt->execute([$anio, $mes, $anio, $mes]);
$rows = $stmt->fetchAll();

$labels = [];
$nombres = [];
$presupuestos = [];
$comprometidos = [];
$pagados = [];
$ritmos = [];
$ejecuciones = [];
$consumos = [];
$coloresRitmo = [];
$totalConsumoCeo = 0.0;
foreach ($rows as $r) {
  $presupuesto = (float)$r['presupuesto'];
  $comprometido = (float)$r['comprometido'];
  $pagado = (float)$r['pagado'];
  $consumo = $comprometido + $pagado;
  $ritmo = $presupuesto > 0 ? ($consumo / $presupuesto) * 100 : null;
  $ejecucion = $totalPresupuestoProyecto = 0.0;

  $stmtTotalProyecto = $pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM ceo_presupuesto_mensual WHERE anio = ? AND proyecto_id = ?');
  $stmtTotalProyecto->execute([$anio, (int)$r['id']]);
  $totalPresupuestoProyecto = (float)$stmtTotalProyecto->fetchColumn();
  $ejecucion = $totalPresupuestoProyecto > 0 ? ($consumo / $totalPresupuestoProyecto) * 100 : null;
  $estadoRitmo = ($ritmo !== null && $ritmo >= 95.0 && $ritmo <= 100.0) ? 'verde' : 'rojo';

  $labels[] = trim((string)$r['codigo'] . ' - ' . (string)$r['nombre']);
  $nombres[] = $r['nombre'];
  $presupuestos[] = $presupuesto;
  $comprometidos[] = $comprometido;
  $pagados[] = $pagado;
  $consumos[] = $consumo;
  $ritmos[] = $ritmo;
  $ejecuciones[] = $ejecucion;
  $coloresRitmo[] = $estadoRitmo === 'verde'
    ? 'rgba(25, 135, 84, 0.78)'
    : 'rgba(220, 53, 69, 0.78)';
  $totalConsumoCeo += $consumo;
}

$totalPresupuestoCeo = array_sum($presupuestos);
$totalComprometidoCeo = array_sum($comprometidos);
$totalRealCeo = array_sum($pagados);
$alturaGrafico = max(460, count($labels) * 46);
?>

<div class="row g-4">
  <div class="col-12">
    <div class="card p-4">
      <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
          <h2 class="h5 mb-2">Panel principal</h2>
          <p class="text-secondary mb-0">Resumen por proyecto con presupuesto acumulado y consumo acumulado al mes seleccionado.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <form class="d-flex gap-2 flex-wrap" method="get">
            <input type="number" class="form-control form-control-sm" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100">
            <select class="form-select form-select-sm" name="mes">
              <?php foreach ($meses as $numeroMes => $nombreMes): ?>
                <option value="<?= $numeroMes ?>" <?= $mes === $numeroMes ? 'selected' : '' ?>><?= htmlspecialchars($nombreMes) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" type="submit">Actualizar</button>
          </form>
          <a class="btn btn-sm btn-outline-secondary" href="https://www.noetica.cl/ceo.noetica.cl/public/general.php">Volver al CEO</a>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-4">
      <h3 class="h6 section-title mb-3">Proyectos CEO (CLP) - Presupuesto, consumo y ejecucion acumulados a <?= htmlspecialchars($meses[$mes] . ' ' . (string)$anio) ?></h3>
      <?php if (empty($labels)): ?>
        <div class="text-secondary">Sin proyectos CEO con datos para el ano seleccionado.</div>
      <?php else: ?>
        <div class="table-responsive">
          <div style="min-height: <?= (int)$alturaGrafico ?>px;">
            <canvas id="ritmoChart"></canvas>
          </div>
        </div>
        <div class="row g-3 mt-3">
          <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
              <div class="text-secondary small">Total presupuesto CEO</div>
              <div class="h5 mb-0"><?= number_format($totalPresupuestoCeo, 0, ',', '.') ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
              <div class="text-secondary small">Total real + comprometido CEO</div>
              <div class="h5 mb-0"><?= number_format($totalConsumoCeo, 0, ',', '.') ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded-3 p-3 h-100">
              <div class="text-secondary small">Total comprometido / real CEO</div>
              <div class="h5 mb-0"><?= number_format($totalComprometidoCeo, 0, ',', '.') ?> / <?= number_format($totalRealCeo, 0, ',', '.') ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($labels)): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const nombres = <?= json_encode($nombres, JSON_UNESCAPED_UNICODE) ?>;
    const dataPresupuesto = <?= json_encode($presupuestos) ?>;
    const dataComprometido = <?= json_encode($comprometidos) ?>;
    const dataPagado = <?= json_encode($pagados) ?>;
    const dataRitmo = <?= json_encode($ritmos) ?>;
    const dataEjecucion = <?= json_encode($ejecuciones) ?>;
    const dataConsumo = <?= json_encode($consumos) ?>;
    const coloresRitmo = <?= json_encode($coloresRitmo, JSON_UNESCAPED_UNICODE) ?>;

    const targetLinePlugin = {
      id: 'targetLinePlugin',
      afterDraw(chart) {
        const {ctx, chartArea, scales} = chart;
        if (!chartArea || !scales.x1) return;
        const x = scales.x1.getPixelForValue(100);
        ctx.save();
        ctx.strokeStyle = '#0d6efd';
        ctx.lineWidth = 2;
        ctx.setLineDash([6, 4]);
        ctx.beginPath();
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
      }
    };

    const ctx = document.getElementById('ritmoChart');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Presupuesto acumulado',
            data: dataPresupuesto,
            backgroundColor: 'rgba(25, 135, 84, 0.72)',
            borderRadius: 6,
            barThickness: 7,
            categoryPercentage: 0.58,
            barPercentage: 0.72
          },
          {
            label: 'Real + comprometido',
            data: dataConsumo,
            backgroundColor: 'rgba(255, 193, 7, 0.78)',
            borderRadius: 6,
            barThickness: 7,
            categoryPercentage: 0.58,
            barPercentage: 0.72
          },
          {
            label: 'Ejecucion (%)',
            data: dataRitmo,
            backgroundColor: coloresRitmo,
            borderRadius: 6,
            barThickness: 7,
            categoryPercentage: 0.58,
            barPercentage: 0.72,
            xAxisID: 'x1'
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false,
          axis: 'y'
        },
        scales: {
          x: {
            beginAtZero: true,
            position: 'top',
            ticks: {
              callback: (value) => Number(value).toLocaleString('es-CL')
            },
            title: {
              display: true,
              text: 'Monto acumulado (CLP)'
            }
          },
          x1: {
            beginAtZero: true,
            suggestedMax: 120,
            position: 'bottom',
            grid: {
              drawOnChartArea: false
            },
            ticks: {
              callback: (value) => value + '%'
            },
            title: {
              display: true,
              text: 'Ritmo de ejecucion (%)'
            }
          },
          y: {
            ticks: {
              autoSkip: false,
              font: {
                size: 11
              }
            }
          }
        },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              boxWidth: 14,
              boxHeight: 14,
              padding: 18
            }
          },
          tooltip: {
            mode: 'index',
            intersect: false,
            callbacks: {
              title: (items) => {
                if (!items.length) return '';
                const idx = items[0].dataIndex;
                return labels[idx] + ' - ' + (nombres[idx] || '');
              },
              label: (ctx) => {
                const idx = ctx.dataIndex;
                const consumo = Number(dataComprometido[idx] ?? 0) + Number(dataPagado[idx] ?? 0);
                const presupuesto = Number(dataPresupuesto[idx] ?? 0);
                const diferencia = presupuesto - consumo;
                const ritmo = dataRitmo[idx];
                const ejecucion = dataEjecucion[idx];
                if (ctx.dataset.label === 'Presupuesto acumulado') {
                  return `${ctx.dataset.label}: ${presupuesto.toLocaleString('es-CL')}`;
                }
                if (ctx.dataset.label === 'Real + comprometido') {
                  return [
                    `${ctx.dataset.label}: ${consumo.toLocaleString('es-CL')}`,
                    `Comprometido: ${Number(dataComprometido[idx] ?? 0).toLocaleString('es-CL')}`,
                    `Pagado: ${Number(dataPagado[idx] ?? 0).toLocaleString('es-CL')}`,
                    `${diferencia >= 0 ? 'Disponible' : 'Exceso'}: ${Math.abs(diferencia).toLocaleString('es-CL')}`
                  ];
                }
                return [
                  `Ejecucion: ${ritmo === null ? 'Sin presupuesto acumulado' : Number(ritmo).toLocaleString('es-CL', {maximumFractionDigits: 1}) + '%'}`,
                  `Estado: ${ritmo !== null && ritmo >= 95 && ritmo <= 100 ? 'En regla' : 'Fuera de ritmo'}`,
                  `Ejecucion anual: ${ejecucion === null ? 'Sin presupuesto anual' : Number(ejecucion).toLocaleString('es-CL', {maximumFractionDigits: 1}) + '%'}`
                ];
              }
            }
          }
        }
      },
      plugins: [targetLinePlugin]
    });
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
