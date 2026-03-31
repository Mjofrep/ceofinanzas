<?php
declare(strict_types=1);

function registrar_actividad(PDO $pdo, string $accion, ?string $detalle = null): void
{
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  $usuario = $_SESSION['auth']['nombre'] ?? 'Sistema';
  $url = $_SERVER['REQUEST_URI'] ?? '';
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';

  $stmt = $pdo->prepare(
    'INSERT INTO ceo_actividad (usuario, accion, detalle, url, ip) VALUES (?, ?, ?, ?, ?)'
  );
  $stmt->execute([$usuario, $accion, $detalle, $url, $ip]);
}
