<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | <?= APP_SUBTITLE ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="/ceofinanzas/assets/css/app.css">
</head>
<body>

  <header class="topbar">
    <div class="container">
      <div class="brand">
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
      <div class="brand-meta">
        <span class="badge text-bg-light">Administrador</span>
      </div>
    </div>
  </header>

  <?php require __DIR__ . '/menu.php'; ?>

  <main class="container mt-4">
