<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$pdo = db();
$anio = (int)($_GET['anio'] ?? date('Y'));

$stmt = $pdo->prepare(
  "SELECT p.id, p.codigo, p.nombre,
          COALESCE(pm.presupuesto, 0) AS presupuesto,
          COALESCE(o.comprometido, 0) AS comprometido,
          COALESCE(o.pagado, 0) AS pagado
   FROM ceo_proyectos p
   LEFT JOIN (
     SELECT proyecto_id, SUM(monto) AS presupuesto
     FROM ceo_presupuesto_mensual
     WHERE anio = ?
     GROUP BY proyecto_id
   ) pm ON pm.proyecto_id = p.id
   LEFT JOIN (
     SELECT o.proyecto_id,
            SUM(CASE
                  WHEN o.estado <> 'Pagado' AND o.eliminada = 0 THEN
                    CASE
                      WHEN m.codigo = 'CLP' THEN o.monto_comprometido
                      WHEN tc.valor_clp IS NOT NULL THEN o.monto_comprometido * tc.valor_clp
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
     LEFT JOIN ceo_tipo_cambio tc ON tc.fecha = o.fecha_entrega AND tc.moneda = m.codigo
     GROUP BY o.proyecto_id
   ) o ON o.proyecto_id = p.id
   WHERE p.codigo LIKE 'CEO%'
   ORDER BY p.codigo"
);
$stmt->execute([$anio]);
$rows = $stmt->fetchAll();

$labels = [];
$nombres = [];
$presupuestos = [];
$comprometidos = [];
$pagados = [];
foreach ($rows as $r) {
  $labels[] = $r['codigo'];
  $nombres[] = $r['nombre'];
  $presupuestos[] = (float)$r['presupuesto'];
  $comprometidos[] = (float)$r['comprometido'];
  $pagados[] = (float)$r['pagado'];
}
?>

<div class="row g-4">
  <div class="col-12">
    <div class="card p-4">
      <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
          <h2 class="h5 mb-2">Panel principal</h2>
          <p class="text-secondary mb-0">Resumen por proyecto con presupuesto, comprometido y pagado.</p>
        </div>
        <form class="d-flex gap-2" method="get">
          <input type="number" class="form-control form-control-sm" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100">
          <button class="btn btn-sm btn-outline-primary" type="submit">Actualizar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-4">
      <h3 class="h6 section-title mb-3">Proyectos CEO (CLP)</h3>
      <?php if (empty($labels)): ?>
        <div class="text-secondary">Sin proyectos CEO con datos para el ano seleccionado.</div>
      <?php else: ?>
        <div class="table-responsive">
          <canvas id="presupuestoChart" height="180"></canvas>
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

    const ctx = document.getElementById('presupuestoChart');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Presupuesto',
            data: dataPresupuesto,
            backgroundColor: 'rgba(13, 110, 253, 0.55)'
          },
          {
            label: 'Comprometido',
            data: dataComprometido,
            backgroundColor: 'rgba(255, 193, 7, 0.75)'
          },
          {
            label: 'Pagado',
            data: dataPagado,
            backgroundColor: 'rgba(25, 135, 84, 0.7)'
          }
        ]
      },
      options: {
        responsive: true,
        interaction: {
          mode: 'index',
          intersect: false
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: (value) => value.toLocaleString('es-CL')
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              title: (items) => {
                if (!items.length) return '';
                const idx = items[0].dataIndex;
                return labels[idx] + ' - ' + (nombres[idx] || '');
              },
              label: (ctx) => {
                const val = ctx.parsed.y ?? 0;
                return `${ctx.dataset.label}: ${val.toLocaleString('es-CL')}`;
              }
            }
          }
        }
      }
    });
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
