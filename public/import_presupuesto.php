<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';

$mensaje = '';
$errores = [];
$preview = [];
$previewHeaders = [];
$archivoGuardado = '';
$tipoHoja = '';
$anio = 2026;
$guardado = false;

function normalizarEncabezado(string $value): string
{
  $value = trim($value);
  $value = preg_replace('/\s+/', ' ', $value);
  $replacements = [
    'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
    'Ñ' => 'N', 'ñ' => 'n'
  ];
  $value = strtr($value, $replacements);
  return strtolower($value);
}

function limpiarMonto(string $value): float
{
  $value = trim($value);
  if ($value === '' || $value === '-') {
    return 0.0;
  }
  $value = str_replace(['.', ' '], '', $value);
  $value = str_replace(',', '.', $value);
  if (!is_numeric($value)) {
    return 0.0;
  }
  return (float)$value;
}

function dividirCodigoNombre(string $descripcion): array
{
  $descripcion = trim($descripcion);
  $partes = explode('-', $descripcion, 3);
  if (count($partes) >= 2) {
    $codigo = trim($partes[0] . '-' . $partes[1]);
    $nombre = trim($partes[2] ?? '');
    return [$codigo, $nombre !== '' ? $nombre : $descripcion];
  }
  return [$descripcion, $descripcion];
}

function cargarDatosCsv(string $ruta): array
{
  $rows = [];
  if (($handle = fopen($ruta, 'r')) !== false) {
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
      $rows[] = $data;
    }
    fclose($handle);
  }
  return $rows;
}

function cargarDatosExcel(string $ruta, string $sheetName): array
{
  if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
    throw new RuntimeException('PhpSpreadsheet no esta instalado.');
  }
  $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
  $sheet = $spreadsheet->getSheetByName($sheetName);
  if ($sheet === null) {
    throw new RuntimeException('No se encontro la hoja ' . $sheetName);
  }
  return $sheet->toArray(null, false, false, false);
}

function obtenerMapaEncabezados(array $header): array
{
  $mapa = [];
  foreach ($header as $idx => $value) {
    $norm = normalizarEncabezado((string)$value);
    if ($norm !== '') {
      $mapa[$norm] = $idx;
    }
  }
  return $mapa;
}

function getMonedaId(PDO $pdo, string $codigo): int
{
  $stmt = $pdo->prepare('SELECT id FROM ceo_monedas WHERE codigo = ?');
  $stmt->execute([$codigo]);
  $id = $stmt->fetchColumn();
  if ($id) {
    return (int)$id;
  }
  $stmt = $pdo->prepare('INSERT INTO ceo_monedas (codigo) VALUES (?)');
  $stmt->execute([$codigo]);
  return (int)$pdo->lastInsertId();
}

function getAreaId(PDO $pdo, string $nombre, array &$cache): int
{
  if (isset($cache[$nombre])) {
    return $cache[$nombre];
  }
  $stmt = $pdo->prepare('SELECT id FROM ceo_areas WHERE nombre = ?');
  $stmt->execute([$nombre]);
  $id = $stmt->fetchColumn();
  if ($id) {
    $cache[$nombre] = (int)$id;
    return (int)$id;
  }
  $stmt = $pdo->prepare('INSERT INTO ceo_areas (nombre) VALUES (?)');
  $stmt->execute([$nombre]);
  $cache[$nombre] = (int)$pdo->lastInsertId();
  return $cache[$nombre];
}

function getClaseCostoId(PDO $pdo, string $tipo, string $subclase, array &$cache): int
{
  $key = $tipo . '|' . $subclase;
  if (isset($cache[$key])) {
    return $cache[$key];
  }
  $stmt = $pdo->prepare('SELECT id FROM ceo_clase_costo WHERE tipo = ? AND subclase = ?');
  $stmt->execute([$tipo, $subclase]);
  $id = $stmt->fetchColumn();
  if ($id) {
    $cache[$key] = (int)$id;
    return (int)$id;
  }
  $stmt = $pdo->prepare('INSERT INTO ceo_clase_costo (tipo, subclase) VALUES (?, ?)');
  $stmt->execute([$tipo, $subclase]);
  $cache[$key] = (int)$pdo->lastInsertId();
  return $cache[$key];
}

function getProyectoId(PDO $pdo, int $areaId, string $codigo, string $nombre, array &$cache): int
{
  $key = $areaId . '|' . $codigo . '|' . $nombre;
  if (isset($cache[$key])) {
    return $cache[$key];
  }
  $stmt = $pdo->prepare('SELECT id FROM ceo_proyectos WHERE area_id = ? AND codigo = ? AND nombre = ?');
  $stmt->execute([$areaId, $codigo, $nombre]);
  $id = $stmt->fetchColumn();
  if ($id) {
    $cache[$key] = (int)$id;
    return (int)$id;
  }
  $stmt = $pdo->prepare('INSERT INTO ceo_proyectos (area_id, codigo, nombre) VALUES (?, ?, ?)');
  $stmt->execute([$areaId, $codigo, $nombre]);
  $cache[$key] = (int)$pdo->lastInsertId();
  return $cache[$key];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? 'preview';
  $tipoHoja = $_POST['tipo_hoja'] ?? '';
  $anio = (int)($_POST['anio'] ?? 2026);

  if ($accion === 'guardar') {
    $archivoGuardado = $_POST['archivo_guardado'] ?? '';
    if ($archivoGuardado === '' || !is_file($archivoGuardado)) {
      $errores[] = 'No se encontro el archivo para importar.';
    }
  }

  if ($accion !== 'guardar') {
    if (!empty($_FILES['archivo']['tmp_name'])) {
      $uploadsDir = __DIR__ . '/../uploads';
      if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0775, true);
      }
      $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
      $archivoGuardado = $uploadsDir . '/presupuesto_' . date('Ymd_His') . '.' . $ext;
      if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $archivoGuardado)) {
        $errores[] = 'No se pudo guardar el archivo.';
      }
    } else {
      $errores[] = 'Debe seleccionar un archivo.';
    }
  }

  if (empty($errores)) {
    try {
      $ext = strtolower(pathinfo($archivoGuardado, PATHINFO_EXTENSION));
      if (in_array($ext, ['xlsx', 'xls'], true)) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $rows = cargarDatosExcel($archivoGuardado, $tipoHoja);
      } else {
        $rows = cargarDatosCsv($archivoGuardado);
      }

      if (count($rows) < 2) {
        throw new RuntimeException('Archivo sin datos.');
      }

      $headerRow = $rows[1];
      $mapa = obtenerMapaEncabezados($headerRow);

      $esCapex = strtolower($tipoHoja) === 'capex';
      $esperados = $esCapex
        ? ['area','proyecto','clase coste','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']
        : ['area','ceco','descripcion de actividad','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

      foreach ($esperados as $col) {
        if (!array_key_exists($col, $mapa)) {
          throw new RuntimeException('Encabezado faltante: ' . $col);
        }
      }

      $previewHeaders = $headerRow;
      $dataRows = array_slice($rows, 2);

      if ($accion === 'guardar') {
        $pdo = db();
        $pdo->beginTransaction();

        $cacheAreas = [];
        $cacheClases = [];
        $cacheProyectos = [];
        $monedaId = getMonedaId($pdo, 'CLP');

        $insert = $pdo->prepare(
          'INSERT INTO ceo_presupuesto_mensual (area_id, proyecto_id, ceco, clase_costo_id, anio, mes, monto, moneda_id, origen_hoja)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE monto = VALUES(monto)'
        );

        $meses = [
          'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
          'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
          'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12
        ];

        $totalInsertados = 0;

        foreach ($dataRows as $row) {
          $area = trim((string)($row[$mapa['area']] ?? ''));
          if ($area === '') {
            continue;
          }

          if ($esCapex) {
            $proyecto = trim((string)($row[$mapa['proyecto']] ?? ''));
            $subclase = trim((string)($row[$mapa['clase coste']] ?? ''));
            $codigo = $proyecto !== '' ? mb_substr($proyecto, 0, 50) : 'CAPEX';
            $nombreProyecto = $proyecto !== '' ? $proyecto : 'SIN PROYECTO';
            $ceco = null;
            $tipoClase = 'CAPEX';
          } else {
            $descripcion = trim((string)($row[$mapa['descripcion de actividad']] ?? ''));
            [$codigo, $nombreProyecto] = dividirCodigoNombre($descripcion);
            $ceco = trim((string)($row[$mapa['ceco']] ?? ''));
            $subclase = 'General';
            $tipoClase = 'OPEX';
          }

          $areaId = getAreaId($pdo, $area, $cacheAreas);
          $claseId = getClaseCostoId($pdo, $tipoClase, $subclase !== '' ? $subclase : 'General', $cacheClases);
          $proyectoId = getProyectoId($pdo, $areaId, $codigo, $nombreProyecto !== '' ? $nombreProyecto : $codigo, $cacheProyectos);

          foreach ($meses as $nombreMes => $numeroMes) {
            $monto = limpiarMonto((string)($row[$mapa[$nombreMes]] ?? ''));
            $insert->execute([
              $areaId,
              $proyectoId,
              $ceco !== '' ? $ceco : null,
              $claseId,
              $anio,
              $numeroMes,
              $monto,
              $monedaId,
              $tipoHoja
            ]);
            $totalInsertados++;
          }
        }

        $pdo->commit();
        $guardado = true;
        $mensaje = 'Presupuesto importado correctamente. Registros procesados: ' . $totalInsertados;
      } else {
        $preview = array_slice($dataRows, 0, 15);
      }

    } catch (Throwable $e) {
      $errores[] = $e->getMessage();
    }
  }
}
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Importar Presupuesto</h2>
      <p class="text-secondary mb-0">Carga el Excel con hojas Resumen, Opex y Capex.</p>
    </div>
    <a href="/ceofinanzas/public/presupuesto.php" class="btn btn-outline-primary btn-sm">Ver Presupuesto</a>
  </div>
</div>

<?php if (!empty($mensaje)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errores as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card p-4">
  <form method="post" enctype="multipart/form-data">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Ano</label>
        <input type="number" class="form-control" name="anio" value="<?= htmlspecialchars((string)$anio) ?>" min="2024" max="2100" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Tipo de hoja</label>
        <select class="form-select" name="tipo_hoja" required>
          <option value="Resumen" <?= $tipoHoja === 'Resumen' ? 'selected' : '' ?>>Resumen</option>
          <option value="Opex" <?= $tipoHoja === 'Opex' ? 'selected' : '' ?>>Opex</option>
          <option value="Capex" <?= $tipoHoja === 'Capex' ? 'selected' : '' ?>>Capex</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Archivo</label>
        <input type="file" class="form-control" name="archivo" accept=".xlsx,.xls,.csv" <?= $guardado ? '' : 'required' ?>>
      </div>
      <div class="col-12">
        <p class="form-hint mb-0">
          El sistema ignorara la primera fila de totales y validara encabezados antes de cargar.
        </p>
      </div>
      <div class="col-12 text-end">
        <button type="submit" class="btn btn-primary">Previsualizar</button>
      </div>
    </div>
  </form>
</div>

<?php if (!empty($preview)): ?>
  <div class="card p-4 mt-4">
    <h3 class="h6 section-title mb-3">Previsualizacion</h3>
    <div class="table-responsive">
      <table class="table table-striped">
        <thead>
          <tr>
            <?php foreach ($previewHeaders as $h): ?>
              <th><?= htmlspecialchars((string)$h) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $row): ?>
            <tr>
              <?php foreach ($row as $cell): ?>
                <td><?= htmlspecialchars((string)$cell) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="text-end">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="archivo_guardado" value="<?= htmlspecialchars($archivoGuardado) ?>">
      <input type="hidden" name="tipo_hoja" value="<?= htmlspecialchars($tipoHoja) ?>">
      <input type="hidden" name="anio" value="<?= htmlspecialchars((string)$anio) ?>">
      <button type="submit" class="btn btn-success">Guardar Importacion</button>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
