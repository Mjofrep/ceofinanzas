<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

$status = 'OK';
$message = 'Conexion exitosa.';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $status = 'ERROR';
    $message = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Estado DB</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card p-4">
      <h1 class="h5 mb-2">Estado de Conexion</h1>
      <p class="mb-0"><strong><?= htmlspecialchars($status) ?></strong> - <?= htmlspecialchars($message) ?></p>
    </div>
  </div>
</body>
</html>
