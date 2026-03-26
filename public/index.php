<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
  <div class="col-12">
    <div class="card p-4">
      <h2 class="h5 mb-2">Panel principal</h2>
      <p class="text-secondary mb-0">Control de presupuesto, ejecucion real y pagos. Selecciona una opcion para comenzar.</p>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card p-4 h-100">
      <h3 class="h6 section-title">Presupuesto</h3>
      <p class="text-secondary">Carga Excel, visualiza totales por area y proyecto.</p>
      <div class="d-grid gap-2">
        <a class="btn btn-outline-primary" href="/ceofinanzas/public/import_presupuesto.php">Importar Excel</a>
        <a class="btn btn-primary" href="/ceofinanzas/public/presupuesto.php">Ver Presupuesto</a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card p-4 h-100">
      <h3 class="h6 section-title">Ejecucion Real</h3>
      <p class="text-secondary">Registra OC, contrato, moneda, PEP y estado.</p>
      <a class="btn btn-primary w-100" href="/ceofinanzas/public/ejecucion.php">Ingresar Orden</a>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card p-4 h-100">
      <h3 class="h6 section-title">Pagos y Facturas</h3>
      <p class="text-secondary">Control de facturacion y pagos por proveedor.</p>
      <a class="btn btn-primary w-100" href="/ceofinanzas/public/pagos.php">Gestionar Pagos</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
