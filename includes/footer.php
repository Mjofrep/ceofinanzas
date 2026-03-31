<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
?>
  </main>

  <footer class="footer-ceo">
    <div class="container">
      <?= APP_FOOTER ?>
    </div>
  </footer>

  <div class="modal fade" id="themeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Color de fondo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex flex-wrap gap-2" id="themeOptions">
            <button type="button" class="btn btn-outline-secondary" data-theme="default">Defecto</button>
            <button type="button" class="btn btn-outline-dark" data-theme="black">Negro</button>
            <button type="button" class="btn btn-outline-light" data-theme="white">Blanco</button>
            <button type="button" class="btn btn-outline-info" data-theme="sky">Celeste</button>
            <button type="button" class="btn btn-outline-success" data-theme="mint">Verde claro</button>
            <button type="button" class="btn btn-outline-danger" data-theme="rose">Rosa</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php $app_js = '/ceofinanzas/assets/js/app.js?v=' . (string)@filemtime(__DIR__ . '/../assets/js/app.js'); ?>
  <script src="<?= htmlspecialchars($app_js) ?>"></script>
</body>
</html>
