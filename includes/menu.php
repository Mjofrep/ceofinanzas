<?php
declare(strict_types=1);

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
?>
<nav class="navbar navbar-expand-lg navbar-ceo">
  <div class="container">
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCeo" aria-controls="navbarCeo" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarCeo">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="/ceofinanzas/public/index.php">Inicio</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['import_presupuesto.php','presupuesto.php'], true) ? 'active' : '' ?>" href="#" id="presupuestoMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Presupuesto
          </a>
          <ul class="dropdown-menu" aria-labelledby="presupuestoMenu">
            <li><a class="dropdown-item <?= $currentPage === 'import_presupuesto.php' ? 'active' : '' ?>" href="/ceofinanzas/public/import_presupuesto.php">Importar Excel</a></li>
            <li><a class="dropdown-item <?= $currentPage === 'presupuesto.php' ? 'active' : '' ?>" href="/ceofinanzas/public/presupuesto.php">Ver Presupuesto</a></li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'ejecucion.php' ? 'active' : '' ?>" href="/ceofinanzas/public/ejecucion.php">Ordenes de Pedido</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'pagos.php' ? 'active' : '' ?>" href="/ceofinanzas/public/pagos.php">Pagos y Facturas</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['seguimiento_proyecto.php'], true) ? 'active' : '' ?>" href="#" id="seguimientoMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Seguimiento
          </a>
          <ul class="dropdown-menu" aria-labelledby="seguimientoMenu">
            <li><a class="dropdown-item <?= $currentPage === 'seguimiento_proyecto.php' ? 'active' : '' ?>" href="/ceofinanzas/public/seguimiento_proyecto.php">Proyectos</a></li>
            <li><a class="dropdown-item <?= $currentPage === 'seguimiento_ordenes.php' ? 'active' : '' ?>" href="/ceofinanzas/public/seguimiento_ordenes.php">Ordenes de Pedido</a></li>
          </ul>
        </li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <span class="text-secondary small">2026</span>
      </div>
    </div>
  </div>
</nav>
