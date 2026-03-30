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
$archivoOriginal = '';

function normalizarEncabezado(string $value): string
{
  $value = str_replace("\xEF\xBB\xBF", '', $value);
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

function detectarFilaEncabezado(array $rows, array $esperados, int $maxFilas = 5): array
{
  $max = min($maxFilas, count($rows));
  for ($i = 0; $i < $max; $i++) {
    $mapa = obtenerMapaEncabezados($rows[$i]);
    $ok = true;
    foreach ($esperados as $col) {
      if (!array_key_exists($col, $mapa)) {
        $ok = false;
        break;
      }
    }
    if ($ok) {
      return [$i, $rows[$i], $mapa];
    }
  }
  return [-1, [], []];
}

function parseMonedaAnioDesdeNombre(string $nombre): array
{
  $nombre = str_replace("\xEF\xBB\xBF", '', $nombre);
  $nombre = strtolower(trim($nombre));
  $nombre = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

  $moneda = '';
  if (preg_match('/\buf\b/', $nombre)) {
    $moneda = 'UF';
  } elseif (preg_match('/\bdolar\b|\busd\b|\bus\$/', $nombre)) {
    $moneda = 'USD';
  } elseif (preg_match('/\beuro\b|\beur\b/', $nombre)) {
    $moneda = 'EUR';
  } elseif (preg_match('/\bclp\b|\bpesos?\b/', $nombre)) {
    $moneda = 'CLP';
  }

  $anio = 0;
  if (preg_match('/(20\d{2})/', $nombre, $m)) {
    $anio = (int)$m[1];
  }

  return [$moneda, $anio];
}

function limpiarMonto(string $value): float
{
  $value = trim($value);
  if ($value === '' || $value === '-') {
    return 0.0;
  }
  $value = str_replace(' ', '', $value);
  $hasComma = strpos($value, ',') !== false;
  $hasDot = strpos($value, '.') !== false;

  if ($hasComma) {
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  } elseif ($hasDot) {
    $lastDot = strrpos($value, '.');
    $decimals = strlen($value) - $lastDot - 1;
    if ($decimals === 3 || $decimals > 3) {
      $value = str_replace('.', '', $value);
    }
  }
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

function cargarDatosExcel(string $ruta, ?string $sheetName): array
{
  if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
    throw new RuntimeException('PhpSpreadsheet no esta instalado.');
  }
  $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
  $sheet = $sheetName ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getActiveSheet();
  if ($sheet === null) {
    throw new RuntimeException('No se encontro la hoja solicitada.');
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
    $archivoOriginal = $_POST['archivo_original'] ?? '';
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
        $archivoOriginal = $_FILES['archivo']['name'] ?? '';
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
      $sheetName = '';
      if (in_array($ext, ['xlsx', 'xls'], true)) {
        require_once __DIR__ . '/../vendor/autoload.php';
        if (strtolower($tipoHoja) === 'tipo_cambio') {
          $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($archivoGuardado);
          $sheet = $spreadsheet->getActiveSheet();
          $sheetName = $sheet->getTitle();
          $rows = $sheet->toArray(null, false, false, false);
        } else {
          $rows = cargarDatosExcel($archivoGuardado, $tipoHoja);
        }
      } else {
        $rows = cargarDatosCsv($archivoGuardado);
      }

      if (count($rows) < 2) {
        throw new RuntimeException('Archivo sin datos.');
      }

      $esTipoCambio = strtolower($tipoHoja) === 'tipo_cambio';
      $esCapex = strtolower($tipoHoja) === 'capex';
      $esperados = $esTipoCambio
        ? ['moneda','fecha','tipo cambio']
        : ($esCapex
          ? ['area','proyecto','clase coste','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']
          : ['area','ceco','descripcion de actividad','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']);
      $esperadosCalendario = ['dia','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

      if ($esTipoCambio) {
        [$headerIndex, $headerRow, $mapa] = detectarFilaEncabezado($rows, $esperados, 6);
        $calIndex = -1;
        $calHeader = [];
        $calMap = [];
        if ($headerIndex === -1) {
          [$calIndex, $calHeader, $calMap] = detectarFilaEncabezado($rows, $esperadosCalendario, 6);
        }

        if ($headerIndex === -1 && $calIndex === -1) {
          throw new RuntimeException('Encabezado faltante: moneda');
        }

        if ($calIndex !== -1) {
          $previewHeaders = $calHeader;
          $dataRows = array_slice($rows, $calIndex + 1);
        } else {
          $previewHeaders = $headerRow;
          $dataRows = array_slice($rows, $headerIndex + 1);
        }
      } else {
        $headerRow = $rows[1];
        $mapa = obtenerMapaEncabezados($headerRow);
        foreach ($esperados as $col) {
          if (!array_key_exists($col, $mapa)) {
            throw new RuntimeException('Encabezado faltante: ' . $col);
          }
        }
        $previewHeaders = $headerRow;
        $dataRows = array_slice($rows, 2);
      }

      if ($accion === 'guardar') {
        $pdo = db();
        $pdo->beginTransaction();

        $totalInsertados = 0;

        if ($esTipoCambio) {
          $stmtTc = $pdo->prepare('INSERT INTO ceo_tipo_cambio (fecha, moneda, valor_clp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor_clp = VALUES(valor_clp)');

          $usaCalendario = isset($calIndex) && $calIndex !== -1;
          if ($usaCalendario) {
            $nombreArchivo = $archivoOriginal !== ''
              ? pathinfo($archivoOriginal, PATHINFO_FILENAME)
              : pathinfo($archivoGuardado, PATHINFO_FILENAME);
            $fuente = $sheetName !== '' ? $sheetName : $nombreArchivo;
            [$moneda, $anio] = parseMonedaAnioDesdeNombre($fuente);
            if ($moneda === '' || $anio === 0) {
              throw new RuntimeException('No se pudo determinar moneda/anio desde el nombre de la hoja o archivo.');
            }

            $meses = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

            foreach ($dataRows as $row) {
              $diaRaw = trim((string)($row[$calMap['dia']] ?? ''));
              if ($diaRaw === '' || !ctype_digit($diaRaw)) {
                continue;
              }
              $dia = (int)$diaRaw;
              if ($dia < 1 || $dia > 31) {
                continue;
              }
              foreach ($meses as $mesNombre => $mesNum) {
                if (!isset($calMap[$mesNombre])) {
                  continue;
                }
                $valor = limpiarMonto((string)($row[$calMap[$mesNombre]] ?? ''));
                if ($valor <= 0) {
                  continue;
                }
                $fecha = sprintf('%04d-%02d-%02d', $anio, $mesNum, $dia);
                $stmtTc->execute([$fecha, $moneda, $valor]);
                $totalInsertados++;
              }
            }
          } else {
            foreach ($dataRows as $row) {
              $moneda = strtoupper(trim((string)($row[$mapa['moneda']] ?? '')));
              $fecha = trim((string)($row[$mapa['fecha']] ?? ''));
              $valor = limpiarMonto((string)($row[$mapa['tipo cambio']] ?? ''));
              if ($moneda === '' || $fecha === '' || $valor <= 0) {
                continue;
              }
              $stmtTc->execute([$fecha, $moneda, $valor]);
              $totalInsertados++;
            }
          }
        } else {
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
        }

        $pdo->commit();
        $guardado = true;
        $mensaje = $esTipoCambio
          ? 'Tipo de cambio importado correctamente. Registros procesados: ' . $totalInsertados
          : 'Presupuesto importado correctamente. Registros procesados: ' . $totalInsertados;
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
          <option value="Tipo_Cambio" <?= $tipoHoja === 'Tipo_Cambio' ? 'selected' : '' ?>>Tipo de Cambio</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Archivo</label>
        <input type="file" class="form-control" name="archivo" accept=".xlsx,.xls,.csv" <?= $guardado ? '' : 'required' ?>>
      </div>
      <div class="col-12">
        <p class="form-hint mb-0">
          Presupuesto: ignora la primera fila de totales y valida encabezados. Tipo de cambio: columnas Moneda, Fecha, Tipo Cambio o formato calendario Dia/Ene...Dic.
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
      <input type="hidden" name="archivo_original" value="<?= htmlspecialchars($archivoOriginal) ?>">
      <input type="hidden" name="tipo_hoja" value="<?= htmlspecialchars($tipoHoja) ?>">
      <input type="hidden" name="anio" value="<?= htmlspecialchars((string)$anio) ?>">
      <button type="submit" class="btn btn-success">Guardar Importacion</button>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
