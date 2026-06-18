<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity.php';

$fatal_error = false;
$mensaje = '';
$aviso = '';
$errores = [];
$preview = [];
$preview_headers = [];
$archivo_guardado = '';
$archivo_original = '';
$guardado = false;
$lote_recien_guardado_id = 0;
$monedas_validas = ['CLP', 'UF', 'USD', 'EUR'];

function normalizar_texto(string $value): string
{
  $value = str_replace("\xEF\xBB\xBF", '', $value);
  $value = trim($value);
  $value = preg_replace('/\s+/', ' ', $value);
  $value = str_replace(['º', '°'], '', $value);
  $value = strtr($value, [
    'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
    'Ñ' => 'N', 'ñ' => 'n'
  ]);
  return strtolower($value);
}

function obtener_mapa_encabezados_gastos(array $header): array
{
  $mapa = [];
  foreach ($header as $idx => $value) {
    $norm = normalizar_texto((string)$value);
    if ($norm !== '') {
      $mapa[$norm] = $idx;
    }
  }
  return $mapa;
}

function indice_columna_gastos(array $mapa, string $columna): ?int
{
  static $aliases = [
    'descr. clase de coste' => ['descrip.clases coste'],
  ];

  $candidatos = array_merge([$columna], $aliases[$columna] ?? []);
  foreach ($candidatos as $candidato) {
    if (isset($mapa[$candidato])) {
      return (int)$mapa[$candidato];
    }
  }

  return null;
}

function detectar_fila_encabezado_gastos(array $rows, array $esperados, int $max_filas = 5): array
{
  $max = min($max_filas, count($rows));
  for ($i = 0; $i < $max; $i++) {
    $mapa = obtener_mapa_encabezados_gastos($rows[$i]);
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

function cargar_datos_csv_gastos(string $ruta): array
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

function cargar_datos_excel_gastos(string $ruta): array
{
  if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
    throw new RuntimeException('PhpSpreadsheet no esta instalado.');
  }
  $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
  $sheet = $spreadsheet->getActiveSheet();
  return $sheet->toArray(null, false, false, false);
}

function limpiar_monto_gastos(string $value): float
{
  $value = trim($value);
  if ($value === '' || $value === '-') {
    return 0.0;
  }
  $value = str_replace(' ', '', $value);
  $has_comma = strpos($value, ',') !== false;
  $has_dot = strpos($value, '.') !== false;

  if ($has_comma) {
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  } elseif ($has_dot) {
    $last_dot = strrpos($value, '.');
    $decimals = strlen($value) - $last_dot - 1;
    if ($decimals === 3 || $decimals > 3) {
      $value = str_replace('.', '', $value);
    }
  }

  return is_numeric($value) ? (float)$value : 0.0;
}

function excel_serial_a_fecha(mixed $value): ?string
{
  if ($value === null) {
    return null;
  }

  $string = trim((string)$value);
  if ($string === '') {
    return null;
  }

  if (is_numeric($string)) {
    $serial = (float)$string;
    if ($serial > 20000) {
      $timestamp = (int)(($serial - 25569) * 86400);
      return gmdate('Y-m-d', $timestamp);
    }
  }

  $string = str_replace('/', '-', $string);
  $partes = explode('-', $string);
  if (count($partes) === 3) {
    if (strlen($partes[0]) === 4) {
      return checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0]) ? $string : null;
    }
    return checkdate((int)$partes[1], (int)$partes[0], (int)$partes[2])
      ? sprintf('%04d-%02d-%02d', (int)$partes[2], (int)$partes[1], (int)$partes[0])
      : null;
  }

  return null;
}

function periodo_a_numero(mixed $value): ?int
{
  $string = trim((string)$value);
  if ($string === '' || !ctype_digit($string)) {
    return null;
  }
  $periodo = (int)$string;
  return $periodo >= 1 && $periodo <= 12 ? $periodo : null;
}

function valor_columna(array $row, array $mapa, string $columna): string
{
  $indice = indice_columna_gastos($mapa, $columna);
  if ($indice === null) {
    return '';
  }
  return trim((string)($row[$indice] ?? ''));
}

function resolver_area_id(array $areas_por_nombre, string $area): ?int
{
  $key = normalizar_texto($area);
  return $areas_por_nombre[$key] ?? null;
}

function resolver_proyecto_id(array $proyectos_por_codigo, array $proyectos_por_nombre, string $detalle): ?int
{
  $key = normalizar_texto($detalle);
  if ($key === '') {
    return null;
  }
  if (isset($proyectos_por_codigo[$key])) {
    return $proyectos_por_codigo[$key];
  }
  return $proyectos_por_nombre[$key] ?? null;
}

function es_nombre_persona_gastos(string $proveedor_nombre): bool
{
  $tokens = preg_split('/\s+/', preg_replace('/[^a-z ]+/', ' ', normalizar_texto($proveedor_nombre)) ?? '');
  $tokens = array_values(array_filter($tokens, static fn($token) => $token !== ''));
  if (count($tokens) < 3) {
    return false;
  }

  $marcas_empresa = [
    'spa', 'sa', 'ltda', 'limitada', 'fundacion', 'corporacion', 'consultoria',
    'ingenieria', 'comercial', 'transportes', 'gestion', 'proyectos', 'servicios',
    'sociedad', 'empresa', 'chile',
  ];

  foreach ($tokens as $token) {
    if (in_array($token, $marcas_empresa, true)) {
      return false;
    }
  }

  return true;
}

function resolver_proyecto_regla_gastos(array $proyectos_por_codigo, array $proyectos_por_nombre, string $contrato_marco, string $proveedor_nombre): array
{
  $mapa_contrato_marco = [
    'ja10183646' => 'Pers. Apoyo CEO',
    'ja10161035' => 'Pers. Apoyo CEO',
    'ja10160111' => 'Otros Gastos CEO',
    'ja10135848' => 'Consumo de Articulos de Aseo y oficina',
    'ja10122226' => 'Servicios De Taxi Y Radiotaxi',
    'ja10149193' => 'Servicio de alimentación (café, cocktail, almuerzos)',
    'ja10156352' => 'Habilitaciones',
    'ja10156353' => 'Habilitaciones',
  ];

  $contrato_key = normalizar_texto($contrato_marco);
  if ($contrato_key !== '' && isset($mapa_contrato_marco[$contrato_key])) {
    return [
      resolver_proyecto_id($proyectos_por_codigo, $proyectos_por_nombre, $mapa_contrato_marco[$contrato_key]),
      'contrato_marco',
    ];
  }

  $proveedor_key = normalizar_texto($proveedor_nombre);
  if ($proveedor_key !== '' && str_contains($proveedor_key, 'capacit')) {
    return [
      resolver_proyecto_id($proyectos_por_codigo, $proyectos_por_nombre, 'Formaciones'),
      'nombre_proveedor',
    ];
  }

  if ($proveedor_key !== '' && es_nombre_persona_gastos($proveedor_nombre)) {
    return [
      resolver_proyecto_id($proyectos_por_codigo, $proyectos_por_nombre, 'Alumno en práctica'),
      'nombre_proveedor',
    ];
  }

  return [null, null];
}

function resolver_proyecto_final(?int $proyecto_oc_id, ?int $proyecto_regla_id, ?int $proyecto_actividad_id): array
{
  $candidatos = [];
  if ($proyecto_oc_id !== null) {
    $candidatos['oc_existente'] = $proyecto_oc_id;
  }
  if ($proyecto_regla_id !== null) {
    $candidatos['regla_importacion'] = $proyecto_regla_id;
  }
  if ($proyecto_actividad_id !== null) {
    $candidatos['detalle_actividad'] = $proyecto_actividad_id;
  }

  if (count(array_unique(array_values($candidatos))) > 1) {
    return [null, 'conflicto', 1, 'Conflicto entre proyecto de OC existente y proyecto detectado en la importacion'];
  }

  if ($proyecto_oc_id !== null) {
    return [$proyecto_oc_id, count($candidatos) > 1 ? 'oc_y_importacion' : 'oc_existente', 0, null];
  }

  if ($proyecto_regla_id !== null) {
    return [$proyecto_regla_id, 'regla_importacion', 0, null];
  }

  if ($proyecto_actividad_id !== null) {
    return [$proyecto_actividad_id, 'detalle_actividad', 0, null];
  }

  return [null, 'sin_match', 0, 'No se pudo vincular proyecto'];
}

function estado_revision_inicial(?int $proyecto_id, string $oc, float $monto): string
{
  if ($proyecto_id === null) {
    return 'sin_proyecto';
  }
  if ($oc === '' || $monto <= 0) {
    return 'pendiente';
  }
  return 'listo';
}

function observacion_revision_inicial(?int $proyecto_id, string $oc, float $monto): ?string
{
  $observaciones = [];
  if ($proyecto_id === null) {
    $observaciones[] = 'No se pudo vincular proyecto';
  }
  if ($oc === '') {
    $observaciones[] = 'Falta Documento compras';
  }
  if ($monto <= 0) {
    $observaciones[] = 'Monto igual a cero';
  }
  return empty($observaciones) ? null : implode(' | ', $observaciones);
}

function construir_hash_importacion(array $payload): string
{
  return hash('sha256', implode('|', $payload));
}

function construir_observacion_orden(string $texto_pedido, string $numero_doc_refer): ?string
{
  $partes = [];
  if ($texto_pedido !== '') {
    $partes[] = $texto_pedido;
  }
  if ($numero_doc_refer !== '') {
    $partes[] = 'Ref: ' . $numero_doc_refer;
  }
  if (empty($partes)) {
    return null;
  }
  return mb_substr(implode(' | ', $partes), 0, 255);
}

function obtener_usuario_actual(): string
{
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  return $_SESSION['auth']['nombre'] ?? 'Sistema';
}

function obtener_valor_clp(PDO $pdo, array &$cache, string $moneda, string $fecha): ?float
{
  $moneda = strtoupper(trim($moneda));
  if ($moneda === 'CLP') {
    return 1.0;
  }

  $key = $fecha . '|' . $moneda;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $stmt = $pdo->prepare('SELECT valor_clp FROM ceo_tipo_cambio WHERE fecha = ? AND moneda = ?');
  $stmt->execute([$fecha, $moneda]);
  $valor = $stmt->fetchColumn();
  $cache[$key] = $valor !== false ? (float)$valor : null;
  return $cache[$key];
}

function convertir_monto_moneda(PDO $pdo, array &$cache, float $monto, string $moneda_origen, string $moneda_destino, ?string $fecha): array
{
  $moneda_origen = strtoupper(trim($moneda_origen));
  $moneda_destino = strtoupper(trim($moneda_destino));

  if ($moneda_origen === '' || $moneda_destino === '') {
    return [null, null, 'Debe definir moneda para comparar'];
  }

  if ($fecha === null || $fecha === '') {
    return [null, null, 'Falta fecha para conversion de moneda'];
  }

  if ($moneda_origen === $moneda_destino) {
    return [$monto, 'Misma moneda', null];
  }

  $valor_origen = obtener_valor_clp($pdo, $cache, $moneda_origen, $fecha);
  $valor_destino = obtener_valor_clp($pdo, $cache, $moneda_destino, $fecha);

  if ($valor_origen === null || $valor_destino === null) {
    return [null, null, 'Falta tipo de cambio para convertir ' . $moneda_origen . ' a ' . $moneda_destino . ' en ' . $fecha];
  }

  $monto_clp = $moneda_origen === 'CLP' ? $monto : $monto * $valor_origen;
  $convertido = $moneda_destino === 'CLP' ? $monto_clp : $monto_clp / $valor_destino;
  $detalle = $moneda_origen . '->' . $moneda_destino . ' @ ' . $fecha;

  return [$convertido, $detalle, null];
}

function obtener_fecha_referencia_orden(array $orden_existente, ?string $fecha_contable_fallback, ?string $fecha_documento_fallback): ?string
{
  $fecha_contable = trim((string)($orden_existente['fecha_contable'] ?? ''));
  if ($fecha_contable !== '') {
    return $fecha_contable;
  }

  $fecha_entrega = trim((string)($orden_existente['fecha_entrega'] ?? ''));
  if ($fecha_entrega !== '') {
    return $fecha_entrega;
  }

  if (!empty($fecha_contable_fallback)) {
    return $fecha_contable_fallback;
  }

  return $fecha_documento_fallback;
}

function evaluar_monto_existente(PDO $pdo, array &$cache, ?array $orden_existente, ?string $moneda_importada, float $monto_importado, ?string $fecha_contable_fallback, ?string $fecha_documento_fallback): array
{
  if (!$orden_existente) {
    return [null, null, null, null, null, 'sin_orden', null];
  }

  $estado = trim((string)($orden_existente['estado'] ?? ''));
  $moneda_orden = strtoupper(trim((string)($orden_existente['moneda_codigo'] ?? '')));
  $monto_actual = $estado === 'Pagado'
    ? (float)($orden_existente['monto'] ?? 0)
    : (float)($orden_existente['monto_comprometido'] ?? 0);

  $detalle_conversion = $moneda_orden !== '' && $moneda_orden !== 'CLP'
    ? 'La orden existente cambiara de ' . $moneda_orden . ' a CLP'
    : 'Monto en CLP';

  return [
    $estado !== '' ? $estado : null,
    $moneda_orden !== '' ? $moneda_orden : null,
    $monto_actual,
    $monto_importado,
    $detalle_conversion,
    abs((float)$monto_actual - (float)$monto_importado) > 0.0001 ? 'distinto' : 'igual',
    null
  ];
}

function tabla_existe(PDO $pdo, string $tabla): bool
{
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
  $stmt->execute([$tabla]);
  return (bool)$stmt->fetchColumn();
}

function columna_existe(PDO $pdo, string $tabla, string $columna): bool
{
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
  $stmt->execute([$tabla, $columna]);
  return (bool)$stmt->fetchColumn();
}

function indice_existe(PDO $pdo, string $tabla, string $indice): bool
{
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
  $stmt->execute([$tabla, $indice]);
  return (bool)$stmt->fetchColumn();
}

function ensure_import_gastos_schema(PDO $pdo): void
{
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS ceo_import_gastos_lotes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      archivo_original VARCHAR(255) NOT NULL,
      usuario VARCHAR(120) NOT NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
  );

  if (!tabla_existe($pdo, 'ceo_import_gastos')) {
    $pdo->exec(
      "CREATE TABLE ceo_import_gastos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lote_id INT NOT NULL,
        origen_archivo VARCHAR(255) NOT NULL,
        hash_unico CHAR(64) NOT NULL,
        pep VARCHAR(120) DEFAULT NULL,
        fecha_documento DATE DEFAULT NULL,
        periodo TINYINT DEFAULT NULL,
        fecha_contable DATE DEFAULT NULL,
        numero_doc_refer VARCHAR(50) DEFAULT NULL,
        documento_compra VARCHAR(50) DEFAULT NULL,
        clase_costo_codigo VARCHAR(50) DEFAULT NULL,
        clase_costo_descripcion VARCHAR(255) DEFAULT NULL,
        texto_cabecera VARCHAR(255) DEFAULT NULL,
        texto_pedido VARCHAR(255) DEFAULT NULL,
        ceco VARCHAR(50) DEFAULT NULL,
        monto DECIMAL(18,2) NOT NULL DEFAULT 0,
        moneda_codigo VARCHAR(10) NOT NULL DEFAULT 'CLP',
        proveedor_nombre VARCHAR(200) DEFAULT NULL,
        contrato_marco VARCHAR(80) DEFAULT NULL,
        validador VARCHAR(255) DEFAULT NULL,
        tipo_proyecto VARCHAR(20) DEFAULT NULL,
        area_nombre VARCHAR(120) DEFAULT NULL,
        categoria_pxq VARCHAR(120) DEFAULT NULL,
        detalle_actividad VARCHAR(255) DEFAULT NULL,
        comentarios VARCHAR(255) DEFAULT NULL,
        detalle_actividad_mes VARCHAR(255) DEFAULT NULL,
        area_id INT DEFAULT NULL,
        proyecto_id INT DEFAULT NULL,
        proyecto_oc_id INT DEFAULT NULL,
        proyecto_actividad_id INT DEFAULT NULL,
        origen_proyecto VARCHAR(30) DEFAULT NULL,
        conflicto_proyecto TINYINT(1) NOT NULL DEFAULT 0,
        estado_revision VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        observacion_revision VARCHAR(255) DEFAULT NULL,
        orden_existente_id INT DEFAULT NULL,
        estado_orden_existente VARCHAR(20) DEFAULT NULL,
        moneda_importada_codigo VARCHAR(10) DEFAULT NULL,
        moneda_orden_existente_codigo VARCHAR(10) DEFAULT NULL,
        monto_orden_existente DECIMAL(18,2) DEFAULT NULL,
        monto_convertido_orden DECIMAL(18,6) DEFAULT NULL,
        detalle_conversion VARCHAR(120) DEFAULT NULL,
        comparacion_monto VARCHAR(20) DEFAULT NULL,
        pasado_a_orden_id INT DEFAULT NULL,
        pasado_en TIMESTAMP NULL DEFAULT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ceo_import_gastos_hash (hash_unico),
        KEY idx_ceo_import_gastos_lote (lote_id),
        KEY idx_ceo_import_gastos_documento_compra (documento_compra),
        KEY idx_ceo_import_gastos_proyecto (proyecto_id),
        KEY idx_ceo_import_gastos_proyecto_oc (proyecto_oc_id),
        KEY idx_ceo_import_gastos_proyecto_actividad (proyecto_actividad_id),
        KEY idx_ceo_import_gastos_conflicto_proyecto (conflicto_proyecto),
        KEY idx_ceo_import_gastos_estado (estado_revision),
        KEY idx_ceo_import_gastos_orden_existente (orden_existente_id),
        KEY idx_ceo_import_gastos_comparacion (comparacion_monto),
        KEY idx_ceo_import_gastos_pasado_orden (pasado_a_orden_id),
        CONSTRAINT fk_ceo_import_gastos_lote FOREIGN KEY (lote_id) REFERENCES ceo_import_gastos_lotes(id),
        CONSTRAINT fk_ceo_import_gastos_area FOREIGN KEY (area_id) REFERENCES ceo_areas(id),
        CONSTRAINT fk_ceo_import_gastos_proyecto FOREIGN KEY (proyecto_id) REFERENCES ceo_proyectos(id),
        CONSTRAINT fk_ceo_import_gastos_proyecto_oc FOREIGN KEY (proyecto_oc_id) REFERENCES ceo_proyectos(id),
        CONSTRAINT fk_ceo_import_gastos_proyecto_actividad FOREIGN KEY (proyecto_actividad_id) REFERENCES ceo_proyectos(id),
        CONSTRAINT fk_ceo_import_gastos_orden_existente FOREIGN KEY (orden_existente_id) REFERENCES ceo_ordenes(id),
        CONSTRAINT fk_ceo_import_gastos_orden FOREIGN KEY (pasado_a_orden_id) REFERENCES ceo_ordenes(id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    return;
  }

  $columnas = [
    'lote_id' => 'ALTER TABLE ceo_import_gastos ADD COLUMN lote_id INT NOT NULL DEFAULT 1 AFTER id',
    'proyecto_oc_id' => 'ALTER TABLE ceo_import_gastos ADD COLUMN proyecto_oc_id INT DEFAULT NULL AFTER proyecto_id',
    'proyecto_actividad_id' => 'ALTER TABLE ceo_import_gastos ADD COLUMN proyecto_actividad_id INT DEFAULT NULL AFTER proyecto_oc_id',
    'origen_proyecto' => 'ALTER TABLE ceo_import_gastos ADD COLUMN origen_proyecto VARCHAR(30) DEFAULT NULL AFTER proyecto_actividad_id',
    'conflicto_proyecto' => 'ALTER TABLE ceo_import_gastos ADD COLUMN conflicto_proyecto TINYINT(1) NOT NULL DEFAULT 0 AFTER origen_proyecto',
    'orden_existente_id' => 'ALTER TABLE ceo_import_gastos ADD COLUMN orden_existente_id INT DEFAULT NULL AFTER observacion_revision',
    'estado_orden_existente' => 'ALTER TABLE ceo_import_gastos ADD COLUMN estado_orden_existente VARCHAR(20) DEFAULT NULL AFTER orden_existente_id',
    'moneda_importada_codigo' => 'ALTER TABLE ceo_import_gastos ADD COLUMN moneda_importada_codigo VARCHAR(10) DEFAULT NULL AFTER estado_orden_existente',
    'moneda_orden_existente_codigo' => 'ALTER TABLE ceo_import_gastos ADD COLUMN moneda_orden_existente_codigo VARCHAR(10) DEFAULT NULL AFTER moneda_importada_codigo',
    'monto_orden_existente' => 'ALTER TABLE ceo_import_gastos ADD COLUMN monto_orden_existente DECIMAL(18,2) DEFAULT NULL AFTER estado_orden_existente',
    'monto_convertido_orden' => 'ALTER TABLE ceo_import_gastos ADD COLUMN monto_convertido_orden DECIMAL(18,6) DEFAULT NULL AFTER monto_orden_existente',
    'detalle_conversion' => 'ALTER TABLE ceo_import_gastos ADD COLUMN detalle_conversion VARCHAR(120) DEFAULT NULL AFTER monto_convertido_orden',
    'comparacion_monto' => 'ALTER TABLE ceo_import_gastos ADD COLUMN comparacion_monto VARCHAR(20) DEFAULT NULL AFTER detalle_conversion',
  ];

  foreach ($columnas as $columna => $sql) {
    if (!columna_existe($pdo, 'ceo_import_gastos', $columna)) {
      $pdo->exec($sql);
    }
  }

  $indices = [
    'idx_ceo_import_gastos_lote' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_lote (lote_id)',
    'idx_ceo_import_gastos_proyecto_oc' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_proyecto_oc (proyecto_oc_id)',
    'idx_ceo_import_gastos_proyecto_actividad' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_proyecto_actividad (proyecto_actividad_id)',
    'idx_ceo_import_gastos_conflicto_proyecto' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_conflicto_proyecto (conflicto_proyecto)',
    'idx_ceo_import_gastos_orden_existente' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_orden_existente (orden_existente_id)',
    'idx_ceo_import_gastos_comparacion' => 'ALTER TABLE ceo_import_gastos ADD KEY idx_ceo_import_gastos_comparacion (comparacion_monto)',
  ];

  foreach ($indices as $indice => $sql) {
    if (!indice_existe($pdo, 'ceo_import_gastos', $indice)) {
      $pdo->exec($sql);
    }
  }
}

try {
  $pdo = db();
  ensure_import_gastos_schema($pdo);

  $areas = $pdo->query('SELECT id, nombre FROM ceo_areas ORDER BY nombre')->fetchAll();
  $proyectos = $pdo->query(
    'SELECT p.id, p.codigo, p.nombre, p.area_id, a.nombre AS area_nombre
     FROM ceo_proyectos p
     INNER JOIN ceo_areas a ON a.id = p.area_id
     ORDER BY p.codigo, p.nombre'
  )->fetchAll();
  $monedas = $pdo->query('SELECT id, codigo FROM ceo_monedas ORDER BY codigo')->fetchAll();

  $areas_por_nombre = [];
  foreach ($areas as $area) {
    $areas_por_nombre[normalizar_texto((string)$area['nombre'])] = (int)$area['id'];
  }

  $proyectos_por_codigo = [];
  $proyectos_por_nombre = [];
  $proyectos_meta = [];
  foreach ($proyectos as $proyecto) {
    $proyectos_por_codigo[normalizar_texto((string)$proyecto['codigo'])] = (int)$proyecto['id'];
    $proyectos_por_nombre[normalizar_texto((string)$proyecto['nombre'])] = (int)$proyecto['id'];
    $proyectos_meta[(int)$proyecto['id']] = [
      'area_id' => (int)$proyecto['area_id'],
      'area_nombre' => (string)$proyecto['area_nombre'],
    ];
  }

  $moneda_clp_id = 0;
  $moneda_ids_por_codigo = [];
  foreach ($monedas as $moneda) {
    $moneda_ids_por_codigo[(string)$moneda['codigo']] = (int)$moneda['id'];
    if (($moneda['codigo'] ?? '') === 'CLP') {
      $moneda_clp_id = (int)$moneda['id'];
    }
  }

  $monedas_validas = ['CLP', 'UF', 'USD', 'EUR'];

  if ($moneda_clp_id <= 0) {
    $errores[] = 'No existe la moneda CLP configurada.';
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errores)) {
    $accion = $_POST['accion'] ?? 'preview';

  if ($accion === 'guardar_importacion') {
    $archivo_guardado = $_POST['archivo_guardado'] ?? '';
    $archivo_original = $_POST['archivo_original'] ?? '';
    if ($archivo_guardado === '' || !is_file($archivo_guardado)) {
      $errores[] = 'No se encontro el archivo para importar.';
    }
  }

  if (in_array($accion, ['preview', 'guardar_importacion'], true)) {
    if ($accion === 'preview') {
      if (!empty($_FILES['archivo']['tmp_name'])) {
        $uploads_dir = __DIR__ . '/../uploads';
        if (!is_dir($uploads_dir)) {
          mkdir($uploads_dir, 0775, true);
        }
        $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
        $archivo_guardado = $uploads_dir . '/gastos_' . date('Ymd_His') . '.' . $ext;
        $archivo_original = $_FILES['archivo']['name'] ?? '';
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $archivo_guardado)) {
          $errores[] = 'No se pudo guardar el archivo.';
        }
      } else {
        $errores[] = 'Debe seleccionar un archivo.';
      }
    }

    if (empty($errores)) {
      try {
        $ext = strtolower(pathinfo($archivo_guardado, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls'], true)) {
          require_once __DIR__ . '/../vendor/autoload.php';
          $rows = cargar_datos_excel_gastos($archivo_guardado);
        } else {
          $rows = cargar_datos_csv_gastos($archivo_guardado);
        }

        if (count($rows) < 2) {
          throw new RuntimeException('Archivo sin datos.');
        }

        $esperados = [
          'elemento pep',
          'fecha de documento',
          'periodo',
          'fe.contabilizacion',
          'n docum.refer.',
          'documento compras',
          'valor/moneda objeto'
        ];

        [$header_index, $header_row, $mapa] = detectar_fila_encabezado_gastos($rows, $esperados, 5);
        if ($header_index === -1) {
          throw new RuntimeException('No se reconocieron los encabezados del archivo.');
        }

        $preview_headers = $header_row;
        $data_rows = array_slice($rows, $header_index + 1);

        if ($accion === 'guardar_importacion') {
          $stmt_lote = $pdo->prepare(
            'INSERT INTO ceo_import_gastos_lotes (archivo_original, usuario) VALUES (?, ?)'
          );

          $stmt_orden_existente_import = $pdo->prepare(
            'SELECT o.id, o.estado, o.monto, o.monto_comprometido, o.proyecto_id, o.fecha_contable, o.fecha_entrega, m.codigo AS moneda_codigo
             FROM ceo_ordenes o
             INNER JOIN ceo_monedas m ON m.id = o.moneda_id
             WHERE o.oc = ?
             LIMIT 1'
          );

          $stmt_import_existente = $pdo->prepare(
            'SELECT id
             FROM ceo_import_gastos
             WHERE hash_unico = ?
             LIMIT 1'
          );

          $insert = $pdo->prepare(
            'INSERT INTO ceo_import_gastos (
              lote_id,
              origen_archivo, hash_unico, pep, fecha_documento, periodo, fecha_contable, numero_doc_refer,
              documento_compra, clase_costo_codigo, clase_costo_descripcion, texto_cabecera, texto_pedido,
              ceco, monto, moneda_codigo, proveedor_nombre, contrato_marco, validador, tipo_proyecto,
              area_nombre, categoria_pxq, detalle_actividad, comentarios, detalle_actividad_mes,
              area_id, proyecto_id, proyecto_oc_id, proyecto_actividad_id, origen_proyecto, conflicto_proyecto, estado_revision, observacion_revision,
              orden_existente_id, estado_orden_existente, moneda_importada_codigo, moneda_orden_existente_codigo, monto_orden_existente, monto_convertido_orden, detalle_conversion, comparacion_monto
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              lote_id = VALUES(lote_id),
              origen_archivo = VALUES(origen_archivo),
              pep = VALUES(pep),
              fecha_documento = VALUES(fecha_documento),
              periodo = VALUES(periodo),
              fecha_contable = VALUES(fecha_contable),
              numero_doc_refer = VALUES(numero_doc_refer),
              documento_compra = VALUES(documento_compra),
              clase_costo_codigo = VALUES(clase_costo_codigo),
              clase_costo_descripcion = VALUES(clase_costo_descripcion),
              texto_cabecera = VALUES(texto_cabecera),
              texto_pedido = VALUES(texto_pedido),
              ceco = VALUES(ceco),
              monto = VALUES(monto),
              moneda_codigo = VALUES(moneda_codigo),
              proveedor_nombre = VALUES(proveedor_nombre),
              contrato_marco = VALUES(contrato_marco),
              validador = VALUES(validador),
              tipo_proyecto = VALUES(tipo_proyecto),
              area_nombre = VALUES(area_nombre),
              categoria_pxq = VALUES(categoria_pxq),
              detalle_actividad = VALUES(detalle_actividad),
              comentarios = VALUES(comentarios),
              detalle_actividad_mes = VALUES(detalle_actividad_mes),
              area_id = VALUES(area_id),
              proyecto_id = VALUES(proyecto_id),
              proyecto_oc_id = VALUES(proyecto_oc_id),
              proyecto_actividad_id = VALUES(proyecto_actividad_id),
              origen_proyecto = VALUES(origen_proyecto),
              conflicto_proyecto = VALUES(conflicto_proyecto),
              estado_revision = CASE
                WHEN pasado_a_orden_id IS NOT NULL THEN "pasado"
                ELSE VALUES(estado_revision)
              END,
              observacion_revision = CASE
                WHEN pasado_a_orden_id IS NOT NULL THEN observacion_revision
                ELSE VALUES(observacion_revision)
              END,
              orden_existente_id = VALUES(orden_existente_id),
              estado_orden_existente = VALUES(estado_orden_existente),
              moneda_importada_codigo = VALUES(moneda_importada_codigo),
              moneda_orden_existente_codigo = VALUES(moneda_orden_existente_codigo),
              monto_orden_existente = VALUES(monto_orden_existente),
              monto_convertido_orden = VALUES(monto_convertido_orden),
              detalle_conversion = VALUES(detalle_conversion),
              comparacion_monto = VALUES(comparacion_monto)' 
          );

          $pdo->beginTransaction();
          $nombre_archivo_lote = $archivo_original !== '' ? $archivo_original : basename($archivo_guardado);
          $stmt_lote->execute([$nombre_archivo_lote, obtener_usuario_actual()]);
          $lote_id = (int)$pdo->lastInsertId();
          $tc_cache = [];
          $insertados = 0;
          $actualizados = 0;

          foreach ($data_rows as $row) {
            $pep = valor_columna($row, $mapa, 'elemento pep');
            $fecha_documento_idx = indice_columna_gastos($mapa, 'fecha de documento');
            $periodo_idx = indice_columna_gastos($mapa, 'periodo');
            $fecha_contable_idx = indice_columna_gastos($mapa, 'fe.contabilizacion');
            $monto_idx = indice_columna_gastos($mapa, 'valor/moneda objeto');
            $fecha_documento = excel_serial_a_fecha($fecha_documento_idx !== null ? ($row[$fecha_documento_idx] ?? null) : null);
            $periodo = periodo_a_numero($periodo_idx !== null ? ($row[$periodo_idx] ?? null) : null);
            $fecha_contable = excel_serial_a_fecha($fecha_contable_idx !== null ? ($row[$fecha_contable_idx] ?? null) : null);
            $numero_doc_refer = valor_columna($row, $mapa, 'n docum.refer.');
            $documento_compra = valor_columna($row, $mapa, 'documento compras');
            $clase_costo_codigo = valor_columna($row, $mapa, 'clase de coste');
            $clase_costo_descripcion = valor_columna($row, $mapa, 'descr. clase de coste');
            $texto_cabecera = valor_columna($row, $mapa, 'texto de cabecera de documento');
            $texto_pedido = valor_columna($row, $mapa, 'texto de pedido');
            $ceco = valor_columna($row, $mapa, 'ceco responsable');
            $monto = limpiar_monto_gastos((string)($monto_idx !== null ? ($row[$monto_idx] ?? '') : ''));
            $proveedor_nombre = valor_columna($row, $mapa, 'nombre proveedor');
            $contrato_marco = valor_columna($row, $mapa, 'contrato marco');
            $validador = valor_columna($row, $mapa, 'validador');
            $tipo_proyecto = strtoupper(valor_columna($row, $mapa, 'tipo proyecto'));
            $area_nombre = valor_columna($row, $mapa, 'area');
            $categoria_pxq = valor_columna($row, $mapa, 'categoria pxq');
            $detalle_actividad = valor_columna($row, $mapa, 'detalle actividad');
            $comentarios = valor_columna($row, $mapa, 'comentarios');
            $detalle_actividad_mes = valor_columna($row, $mapa, 'detalle actividad x mes');

            if ($pep === '' && $documento_compra === '' && $numero_doc_refer === '' && $detalle_actividad === '' && $monto <= 0) {
              continue;
            }

            $area_id = resolver_area_id($areas_por_nombre, $area_nombre);
            $proyecto_actividad_id = resolver_proyecto_id($proyectos_por_codigo, $proyectos_por_nombre, $detalle_actividad);
            [$proyecto_regla_id, $origen_regla] = resolver_proyecto_regla_gastos(
              $proyectos_por_codigo,
              $proyectos_por_nombre,
              $contrato_marco,
              $proveedor_nombre
            );
            $orden_existente_id = null;
            $estado_orden_existente = null;
            $moneda_importada_codigo = 'CLP';
            $moneda_orden_existente_codigo = null;
            $monto_orden_existente = null;
            $monto_convertido_orden = null;
            $detalle_conversion = null;
            $comparacion_monto = 'sin_orden';
            $proyecto_oc_id = null;
            $observacion_conversion = null;

            if ($documento_compra !== '') {
              $stmt_orden_existente_import->execute([$documento_compra]);
              $orden_existente_import = $stmt_orden_existente_import->fetch();
              if ($orden_existente_import) {
                $orden_existente_id = (int)$orden_existente_import['id'];
                $proyecto_oc_id = !empty($orden_existente_import['proyecto_id']) ? (int)$orden_existente_import['proyecto_id'] : null;
                [$estado_orden_existente, $moneda_orden_existente_codigo, $monto_orden_existente, $monto_convertido_orden, $detalle_conversion, $comparacion_monto, $observacion_conversion] = evaluar_monto_existente(
                  $pdo,
                  $tc_cache,
                  $orden_existente_import,
                  $moneda_importada_codigo,
                  $monto,
                  $fecha_contable,
                  $fecha_documento
                );
              }
            }

            [$proyecto_id, $origen_proyecto, $conflicto_proyecto, $observacion_conflicto] = resolver_proyecto_final(
              $proyecto_oc_id,
              $proyecto_regla_id,
              $proyecto_actividad_id
            );
            if ($origen_proyecto === 'regla_importacion' && $origen_regla !== null) {
              $origen_proyecto = $origen_regla;
            }

            if ($area_id === null && $proyecto_id !== null && isset($proyectos_meta[$proyecto_id])) {
              $area_id = $proyectos_meta[$proyecto_id]['area_id'];
              if ($area_nombre === '') {
                $area_nombre = $proyectos_meta[$proyecto_id]['area_nombre'];
              }
            }

            if (!in_array($tipo_proyecto, ['OPEX', 'CAPEX'], true)) {
              $tipo_proyecto = 'OPEX';
            }
            $estado_revision = $conflicto_proyecto === 1
              ? 'conflicto_proyecto'
              : estado_revision_inicial($proyecto_id, $documento_compra, $monto);
            $observacion_revision = $observacion_conflicto ?? observacion_revision_inicial($proyecto_id, $documento_compra, $monto);

            if (!empty($observacion_conversion)) {
              $estado_revision = 'pendiente';
              $observacion_revision = $observacion_conversion;
            }

            if ($observacion_revision === null && $comparacion_monto === 'distinto') {
              $observacion_revision = 'La OC ya existe y el monto es distinto';
            }

            $hash_unico = construir_hash_importacion([
              $documento_compra,
              $numero_doc_refer,
              $fecha_contable ?? '',
              number_format($monto, 2, '.', ''),
              normalizar_texto($detalle_actividad),
              normalizar_texto($proveedor_nombre)
            ]);

            $stmt_import_existente->execute([$hash_unico]);
            $import_existente = $stmt_import_existente->fetch();

            $insert->execute([
              $lote_id,
              $nombre_archivo_lote,
              $hash_unico,
              $pep !== '' ? $pep : null,
              $fecha_documento,
              $periodo,
              $fecha_contable,
              $numero_doc_refer !== '' ? $numero_doc_refer : null,
              $documento_compra !== '' ? $documento_compra : null,
              $clase_costo_codigo !== '' ? $clase_costo_codigo : null,
              $clase_costo_descripcion !== '' ? $clase_costo_descripcion : null,
              $texto_cabecera !== '' ? $texto_cabecera : null,
              $texto_pedido !== '' ? $texto_pedido : null,
              $ceco !== '' ? $ceco : null,
              $monto,
              'CLP',
              $proveedor_nombre !== '' ? $proveedor_nombre : null,
              $contrato_marco !== '' ? $contrato_marco : null,
              $validador !== '' ? $validador : null,
              $tipo_proyecto !== '' ? $tipo_proyecto : 'OPEX',
              $area_nombre !== '' ? $area_nombre : null,
              $categoria_pxq !== '' ? $categoria_pxq : null,
              $detalle_actividad !== '' ? $detalle_actividad : null,
              $comentarios !== '' ? $comentarios : null,
              $detalle_actividad_mes !== '' ? $detalle_actividad_mes : null,
              $area_id,
              $proyecto_id,
              $proyecto_oc_id,
              $proyecto_actividad_id,
              $origen_proyecto,
              $conflicto_proyecto,
              $estado_revision,
              $observacion_revision,
              $orden_existente_id,
              $estado_orden_existente,
              $moneda_importada_codigo,
              $moneda_orden_existente_codigo,
              $monto_orden_existente,
              $monto_convertido_orden,
              $detalle_conversion,
              $comparacion_monto
            ]);

            if ($import_existente) {
              $actualizados++;
            } else {
              $insertados++;
            }
          }

          $pdo->commit();
          $guardado = true;
          if ($insertados > 0 || $actualizados > 0) {
            $lote_recien_guardado_id = $lote_id;
          }
          $mensaje = 'Importacion de gastos completada. Lote #' . $lote_id . '. Nuevos registros: ' . $insertados . '. Registros actualizados: ' . $actualizados . '.';
          if ($insertados === 0 && $actualizados > 0) {
            $aviso = 'La carga se guardo como lote #' . $lote_id . '. No hubo registros nuevos, pero los movimientos existentes se actualizaron y quedaron asociados a este lote.';
          } elseif ($insertados === 0) {
            $aviso = 'La carga se guardo como lote #' . $lote_id . ', pero no genero registros nuevos para revision.';
          }
          registrar_actividad($pdo, 'Importar gastos', $nombre_archivo_lote . ' | Lote: ' . $lote_id . ' | Nuevos: ' . $insertados . ' | Actualizados: ' . $actualizados);
        } else {
          $preview = array_slice($data_rows, 0, 15);
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $errores[] = $e->getMessage();
      }
    }
  }

  if ($accion === 'asignar_proyecto') {
    $seleccionados = array_map('intval', $_POST['seleccionados'] ?? []);
    $proyecto_asignado_id = (int)($_POST['proyecto_asignado_id'] ?? 0);

    if (empty($seleccionados)) {
      $errores[] = 'Debe seleccionar al menos un registro para asignar proyecto.';
    } elseif ($proyecto_asignado_id <= 0) {
      $errores[] = 'Debe seleccionar un proyecto para asignar.';
    } else {
      $stmt_proyecto = $pdo->prepare('SELECT p.id, p.area_id, a.nombre AS area_nombre FROM ceo_proyectos p INNER JOIN ceo_areas a ON a.id = p.area_id WHERE p.id = ?');
      $stmt_proyecto->execute([$proyecto_asignado_id]);
      $proyecto = $stmt_proyecto->fetch();

      if (!$proyecto) {
        $errores[] = 'El proyecto seleccionado no existe.';
      } else {
        $placeholders = implode(',', array_fill(0, count($seleccionados), '?'));
        $params = [$proyecto_asignado_id, (int)$proyecto['area_id'], $proyecto['area_nombre'], ...$seleccionados];
        $stmt_update = $pdo->prepare(
          "UPDATE ceo_import_gastos
           SET proyecto_id = ?, area_id = ?, area_nombre = COALESCE(area_nombre, ?),
                origen_proyecto = 'manual', conflicto_proyecto = 0,
                estado_revision = CASE WHEN documento_compra IS NOT NULL AND documento_compra <> '' AND monto > 0 THEN 'listo' ELSE 'pendiente' END,
                observacion_revision = CASE
                  WHEN comparacion_monto = 'distinto' THEN 'La OC ya existe y el monto es distinto'
                  WHEN documento_compra IS NOT NULL AND documento_compra <> '' AND monto > 0 THEN NULL
                  ELSE 'Falta Documento compras o monto'
                END
           WHERE pasado_a_orden_id IS NULL AND id IN ({$placeholders})"
        );
        $stmt_update->execute($params);
        $mensaje = 'Proyecto asignado a los registros seleccionados.';
        registrar_actividad($pdo, 'Asignar proyecto importacion gastos', 'Proyecto ID ' . $proyecto_asignado_id . ' | Registros: ' . count($seleccionados));
      }
    }
  }

  if ($accion === 'pasar_a_ordenes') {
    $seleccionados = array_map('intval', $_POST['seleccionados'] ?? []);

    if (empty($seleccionados)) {
      $errores[] = 'Debe seleccionar al menos un registro para pasar a ordenes.';
    } else {
      try {
        $placeholders = implode(',', array_fill(0, count($seleccionados), '?'));
        $stmt_rows = $pdo->prepare(
          "SELECT *
           FROM ceo_import_gastos
           WHERE id IN ({$placeholders})
           ORDER BY id"
        );
        $stmt_rows->execute($seleccionados);
        $rows = $stmt_rows->fetchAll();

        if (empty($rows)) {
          throw new RuntimeException('No se encontraron registros seleccionados.');
        }

        $stmt_orden_existente = $pdo->prepare(
          'SELECT o.id, o.estado, o.monto, o.monto_comprometido, o.fecha_contable, o.fecha_entrega, m.codigo AS moneda_codigo
           FROM ceo_ordenes o
           INNER JOIN ceo_monedas m ON m.id = o.moneda_id
           WHERE o.oc = ?
           LIMIT 1'
        );

        $stmt_insert_orden = $pdo->prepare(
          'INSERT INTO ceo_ordenes (
             oc, contrato, fecha_entrega, fecha_contable, moneda_id, pep, tipo_presupuesto,
             observacion, sociedad, proyecto_id, monto, monto_comprometido, estado,
             estado_detalle, estado_detalle_otro, hes, eliminada
           ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
        );

        $stmt_update_import = $pdo->prepare(
          'UPDATE ceo_import_gastos
           SET pasado_a_orden_id = ?, pasado_en = NOW(), estado_revision = ?, observacion_revision = ?
           WHERE id = ?'
        );

        $stmt_update_orden_pagada = $pdo->prepare(
          'UPDATE ceo_ordenes SET monto = ?, moneda_id = ? WHERE id = ?'
        );

        $stmt_update_orden_comprometida = $pdo->prepare(
          'UPDATE ceo_ordenes SET monto_comprometido = ?, moneda_id = ? WHERE id = ?'
        );

        $pdo->beginTransaction();
        $creadas = 0;
        $omitidas = 0;

        foreach ($rows as $row) {
          $import_id = (int)$row['id'];
          if (!empty($row['pasado_a_orden_id'])) {
            $omitidas++;
            continue;
          }

          $oc = trim((string)($row['documento_compra'] ?? ''));
          $proyecto_id = (int)($row['proyecto_id'] ?? 0);
          $monto = (float)($row['monto'] ?? 0);
          $pep = trim((string)($row['pep'] ?? ''));
          $fecha_contable = $row['fecha_contable'] ?: null;
          $fecha_documento = $row['fecha_documento'] ?: null;
          $moneda_importada = 'CLP';

          if ((int)($row['conflicto_proyecto'] ?? 0) === 1) {
            $stmt_update_import->execute([null, 'conflicto_proyecto', 'Conflicto de proyecto sin resolver manualmente', $import_id]);
            $omitidas++;
            continue;
          }

          if ($oc === '' || $proyecto_id <= 0 || $monto <= 0) {
            $stmt_update_import->execute([null, 'pendiente', 'No cumple condiciones para pasar a ordenes', $import_id]);
            $omitidas++;
            continue;
          }

          $stmt_orden_existente->execute([$oc]);
          $orden_existente = $stmt_orden_existente->fetch();
          if ($orden_existente) {
            $orden_id = (int)$orden_existente['id'];
            $estado_actual = trim((string)($orden_existente['estado'] ?? ''));
            $es_pagada = $estado_actual === 'Pagado';
            $monto_convertido_orden = $monto;
            $moneda_orden_existente = strtoupper(trim((string)($orden_existente['moneda_codigo'] ?? '')));
            $comparacion_monto = abs((float)($es_pagada ? $orden_existente['monto'] : $orden_existente['monto_comprometido']) - $monto) > 0.0001 ? 'distinto' : 'igual';

            if ($comparacion_monto === 'distinto' || $moneda_orden_existente !== 'CLP') {
              if ($es_pagada) {
                $stmt_update_orden_pagada->execute([$monto_convertido_orden, $moneda_clp_id, $orden_id]);
                $stmt_update_import->execute([$orden_id, 'pasado', 'Orden existente actualizada: monto real y moneda CLP', $import_id]);
              } else {
                $stmt_update_orden_comprometida->execute([$monto_convertido_orden, $moneda_clp_id, $orden_id]);
                $stmt_update_import->execute([$orden_id, 'pasado', 'Orden existente actualizada: monto comprometido y moneda CLP', $import_id]);
              }
            } else {
              $stmt_update_import->execute([$orden_id, 'pasado', 'Orden existente vinculada sin cambios', $import_id]);
            }

            $omitidas++;
            continue;
          }

          $tipo_presupuesto = strtoupper(trim((string)($row['tipo_proyecto'] ?? 'OPEX')));
          if (!in_array($tipo_presupuesto, ['OPEX', 'CAPEX'], true)) {
            $tipo_presupuesto = str_starts_with(strtoupper($pep), 'NTD') ? 'CAPEX' : 'OPEX';
          }

          $stmt_insert_orden->execute([
            $oc,
            ($row['contrato_marco'] ?? '') !== '' ? $row['contrato_marco'] : null,
            $fecha_documento,
            $fecha_contable,
            $moneda_ids_por_codigo[$moneda_importada],
            $pep !== '' ? $pep : null,
            $tipo_presupuesto,
            construir_observacion_orden((string)($row['texto_pedido'] ?? ''), (string)($row['numero_doc_refer'] ?? '')),
            'CL13',
            $proyecto_id,
            $monto,
            0,
            'Registrado',
            'Ingresado',
            null,
            null,
            0
          ]);

          $orden_id = (int)$pdo->lastInsertId();
          $stmt_update_import->execute([$orden_id, 'pasado', null, $import_id]);
          $creadas++;
        }

        $pdo->commit();
        $mensaje = 'Paso a ordenes completado. Nuevas ordenes: ' . $creadas . '. Omitidas: ' . $omitidas . '.';
        registrar_actividad($pdo, 'Pasar importacion gastos a ordenes', 'Nuevas: ' . $creadas . ' | Omitidas: ' . $omitidas);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $errores[] = $e->getMessage();
      }
    }
  }
  }

  $filtro_estado = trim((string)($_GET['estado'] ?? $_POST['filtro_estado'] ?? ''));
  $filtro_archivo = trim((string)($_GET['archivo'] ?? $_POST['filtro_archivo'] ?? ''));
  $filtro_lote = (int)($_GET['lote_id'] ?? $_POST['filtro_lote_id'] ?? 0);
  $filtro_proyecto = (int)($_GET['proyecto_id'] ?? $_POST['filtro_proyecto_id'] ?? 0);
  $solo_no_pasados = isset($_GET['solo_no_pasados']) || isset($_POST['filtro_solo_no_pasados']);
  $solo_oc_existente = isset($_GET['solo_oc_existente']) || isset($_POST['filtro_solo_oc_existente']);
  $solo_monto_distinto = isset($_GET['solo_monto_distinto']) || isset($_POST['filtro_solo_monto_distinto']);
  $solo_conflicto_proyecto = isset($_GET['solo_conflicto_proyecto']) || isset($_POST['filtro_solo_conflicto_proyecto']);
  $busqueda = trim((string)($_GET['q'] ?? $_POST['filtro_q'] ?? ''));

  if ($filtro_lote <= 0 && $lote_recien_guardado_id > 0) {
    $filtro_lote = $lote_recien_guardado_id;
  }

  $sql = 'SELECT ig.*, p.codigo AS proyecto_codigo, p.nombre AS proyecto_nombre,
                 l.archivo_original AS lote_archivo, l.creado_en AS lote_creado_en
          FROM ceo_import_gastos ig
          LEFT JOIN ceo_proyectos p ON p.id = ig.proyecto_id
          INNER JOIN ceo_import_gastos_lotes l ON l.id = ig.lote_id
          WHERE 1=1';
  $params = [];

  if ($filtro_estado !== '') {
    $sql .= ' AND ig.estado_revision = ?';
    $params[] = $filtro_estado;
  }

  if ($filtro_archivo !== '') {
    $sql .= ' AND ig.origen_archivo = ?';
    $params[] = $filtro_archivo;
  }

  if ($filtro_lote > 0) {
    $sql .= ' AND ig.lote_id = ?';
    $params[] = $filtro_lote;
  }

  if ($filtro_proyecto > 0) {
    $sql .= ' AND ig.proyecto_id = ?';
    $params[] = $filtro_proyecto;
  }

  if ($solo_no_pasados) {
    $sql .= ' AND ig.pasado_a_orden_id IS NULL';
  }

  if ($solo_oc_existente) {
    $sql .= ' AND ig.orden_existente_id IS NOT NULL';
  }

  if ($solo_monto_distinto) {
    $sql .= ' AND ig.comparacion_monto = ?';
    $params[] = 'distinto';
  }

  if ($solo_conflicto_proyecto) {
    $sql .= ' AND ig.conflicto_proyecto = 1';
  }

  if ($busqueda !== '') {
    $like = '%' . $busqueda . '%';
    $sql .= ' AND (ig.documento_compra LIKE ? OR ig.numero_doc_refer LIKE ? OR ig.proveedor_nombre LIKE ? OR ig.detalle_actividad LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  $sql .= ' ORDER BY ig.id DESC LIMIT 300';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $registros = $stmt->fetchAll();

  $archivos = $pdo->query('SELECT DISTINCT origen_archivo FROM ceo_import_gastos ORDER BY origen_archivo DESC')->fetchAll();
  $lotes = $pdo->query(
    'SELECT l.id, l.archivo_original, l.creado_en, COUNT(ig.id) AS total_registros
     FROM ceo_import_gastos_lotes l
     LEFT JOIN ceo_import_gastos ig ON ig.lote_id = l.id
     GROUP BY l.id, l.archivo_original, l.creado_en
     ORDER BY l.id DESC'
  )->fetchAll();
  $lote_activo = null;
  foreach ($lotes as $lote) {
    if ((int)$lote['id'] === $filtro_lote) {
      $lote_activo = $lote;
      break;
    }
  }
  $estados_revision = ['listo', 'sin_proyecto', 'conflicto_proyecto', 'pendiente', 'pasado'];
} catch (Throwable $e) {
  $fatal_error = true;
  $errores[] = 'No fue posible cargar Importar Gastos: ' . $e->getMessage();
  $areas = [];
  $proyectos = [];
  $monedas = [];
  $registros = [];
  $archivos = [];
  $lotes = [];
  $lote_activo = null;
  $estados_revision = ['listo', 'sin_proyecto', 'conflicto_proyecto', 'pendiente', 'pasado'];
  $filtro_estado = '';
  $filtro_archivo = '';
  $filtro_lote = 0;
  $filtro_proyecto = 0;
  $solo_no_pasados = false;
  $solo_oc_existente = false;
  $solo_monto_distinto = false;
  $solo_conflicto_proyecto = false;
  $busqueda = '';
}
?>

<div class="card p-4 mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h2 class="h5 mb-1">Importar Gastos Realizados</h2>
      <p class="text-secondary mb-0">Carga el Excel de gastos, revisa el match con proyectos y pasa filas seleccionadas a ordenes.</p>
    </div>
    <a href="/ceofinanzas/public/ejecucion.php" class="btn btn-outline-primary btn-sm">Ver Ordenes</a>
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

<?php if ($aviso !== ''): ?>
  <div class="alert alert-warning"><?= htmlspecialchars($aviso) ?></div>
<?php endif; ?>

<?php if ($lote_activo !== null): ?>
  <div class="alert alert-info">
    Mostrando lote #<?= (int)$lote_activo['id'] ?>
    (<?= htmlspecialchars($lote_activo['archivo_original']) ?>)
    cargado el <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string)$lote_activo['creado_en']))) ?>.
    Registros en revision: <?= (int)($lote_activo['total_registros'] ?? 0) ?>.
  </div>
<?php endif; ?>

<div class="card p-4 mb-4">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="preview">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label">Archivo</label>
        <input type="file" class="form-control" name="archivo" accept=".xlsx,.xls,.csv" required>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Previsualizar</button>
      </div>
      <div class="col-12">
        <p class="form-hint mb-0">Se usa `Documento compras` como OC y `Nº docum.refer.` como referencia adicional. Toda la carga se trata en CLP; si la orden existente esta en otra moneda, al procesar se actualiza a CLP.</p>
      </div>
    </div>
  </form>
</div>

<?php if (!empty($preview)): ?>
  <div class="card p-4 mb-4">
    <h3 class="h6 section-title mb-3">Previsualizacion</h3>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <?php foreach ($preview_headers as $header): ?>
              <th><?= htmlspecialchars((string)$header) ?></th>
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
    <form method="post" class="text-end mt-3">
      <input type="hidden" name="accion" value="guardar_importacion">
      <input type="hidden" name="archivo_guardado" value="<?= htmlspecialchars($archivo_guardado) ?>">
      <input type="hidden" name="archivo_original" value="<?= htmlspecialchars($archivo_original) ?>">
      <button type="submit" class="btn btn-success">Guardar Importacion</button>
    </form>
  </div>
<?php endif; ?>

<div class="card p-4 mb-4">
  <h3 class="h6 section-title mb-3">Revision de Carga</h3>
  <form class="row g-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Estado</label>
      <select class="form-select" name="estado">
        <option value="">Todos</option>
        <?php foreach ($estados_revision as $estado): ?>
          <option value="<?= htmlspecialchars($estado) ?>" <?= $filtro_estado === $estado ? 'selected' : '' ?>><?= htmlspecialchars($estado) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Archivo</label>
      <select class="form-select" name="archivo">
        <option value="">Todos</option>
        <?php foreach ($archivos as $archivo): ?>
          <option value="<?= htmlspecialchars($archivo['origen_archivo']) ?>" <?= $filtro_archivo === $archivo['origen_archivo'] ? 'selected' : '' ?>><?= htmlspecialchars($archivo['origen_archivo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Lote</label>
      <select class="form-select" name="lote_id">
        <option value="0">Todos</option>
        <?php foreach ($lotes as $lote): ?>
          <option value="<?= (int)$lote['id'] ?>" <?= $filtro_lote === (int)$lote['id'] ? 'selected' : '' ?>>#<?= (int)$lote['id'] ?> | <?= htmlspecialchars($lote['archivo_original']) ?> | <?= (int)($lote['total_registros'] ?? 0) ?> reg.</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Proyecto</label>
      <select class="form-select" name="proyecto_id">
        <option value="0">Todos</option>
        <?php foreach ($proyectos as $proyecto): ?>
          <option value="<?= (int)$proyecto['id'] ?>" <?= $filtro_proyecto === (int)$proyecto['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($proyecto['codigo'] . ' ' . $proyecto['nombre'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Buscar</label>
      <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="OC, proveedor, actividad">
    </div>
    <div class="col-md-3">
      <div class="form-check mt-4 pt-2">
        <input class="form-check-input" type="checkbox" name="solo_no_pasados" id="solo_no_pasados" <?= $solo_no_pasados ? 'checked' : '' ?>>
        <label class="form-check-label" for="solo_no_pasados">Solo no pasados</label>
      </div>
    </div>
    <div class="col-md-3">
      <div class="form-check mt-4 pt-2">
        <input class="form-check-input" type="checkbox" name="solo_oc_existente" id="solo_oc_existente" <?= $solo_oc_existente ? 'checked' : '' ?>>
        <label class="form-check-label" for="solo_oc_existente">Solo OC existente</label>
      </div>
    </div>
    <div class="col-md-3">
      <div class="form-check mt-4 pt-2">
        <input class="form-check-input" type="checkbox" name="solo_monto_distinto" id="solo_monto_distinto" <?= $solo_monto_distinto ? 'checked' : '' ?>>
        <label class="form-check-label" for="solo_monto_distinto">Solo monto distinto</label>
      </div>
    </div>
    <div class="col-md-3">
      <div class="form-check mt-4 pt-2">
        <input class="form-check-input" type="checkbox" name="solo_conflicto_proyecto" id="solo_conflicto_proyecto" <?= $solo_conflicto_proyecto ? 'checked' : '' ?>>
        <label class="form-check-label" for="solo_conflicto_proyecto">Solo conflicto proyecto</label>
      </div>
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100">Filtrar</button>
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <a href="/ceofinanzas/public/import_gastos.php" class="btn btn-outline-secondary w-100">Limpiar filtros</a>
    </div>
  </form>
</div>

<div class="card p-4">
  <form method="post">
    <input type="hidden" name="filtro_estado" value="<?= htmlspecialchars($filtro_estado) ?>">
    <input type="hidden" name="filtro_archivo" value="<?= htmlspecialchars($filtro_archivo) ?>">
    <input type="hidden" name="filtro_lote_id" value="<?= (int)$filtro_lote ?>">
    <input type="hidden" name="filtro_proyecto_id" value="<?= (int)$filtro_proyecto ?>">
    <input type="hidden" name="filtro_q" value="<?= htmlspecialchars($busqueda) ?>">
    <?php if ($solo_no_pasados): ?>
      <input type="hidden" name="filtro_solo_no_pasados" value="1">
    <?php endif; ?>
    <?php if ($solo_oc_existente): ?>
      <input type="hidden" name="filtro_solo_oc_existente" value="1">
    <?php endif; ?>
    <?php if ($solo_monto_distinto): ?>
      <input type="hidden" name="filtro_solo_monto_distinto" value="1">
    <?php endif; ?>
    <?php if ($solo_conflicto_proyecto): ?>
      <input type="hidden" name="filtro_solo_conflicto_proyecto" value="1">
    <?php endif; ?>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Asignar proyecto a seleccionados</label>
        <select class="form-select" name="proyecto_asignado_id">
          <option value="0">Seleccione...</option>
          <?php foreach ($proyectos as $proyecto): ?>
            <option value="<?= (int)$proyecto['id'] ?>"><?= htmlspecialchars(trim($proyecto['codigo'] . ' ' . $proyecto['nombre'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
        <button type="submit" class="btn btn-outline-secondary" name="accion" value="asignar_proyecto">Asignar Proyecto</button>
        <button type="submit" class="btn btn-success" name="accion" value="pasar_a_ordenes">Pasar a Ordenes</button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('[data-import-check]').forEach(cb => cb.checked = this.checked);"></th>
            <th>ID</th>
            <th>Lote</th>
            <th>OC</th>
            <th>Ref.</th>
            <th>Fecha contable</th>
            <th>Proveedor</th>
            <th>Actividad</th>
            <th>Proyecto</th>
            <th>Proyecto OC</th>
            <th>Proyecto actividad</th>
            <th>Origen proyecto</th>
            <th class="text-end">Monto</th>
            <th>Moneda imp.</th>
            <th>OC existente</th>
            <th>Moneda orden</th>
            <th class="text-end">Monto orden</th>
            <th class="text-end">Monto convertido</th>
            <th>Comparacion</th>
            <th>Contrato</th>
            <th>Estado</th>
            <th>Observacion</th>
            <th>Orden</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
          <tr>
            <td colspan="23" class="text-center text-secondary">
              <?php if ($lote_activo !== null && (int)($lote_activo['total_registros'] ?? 0) === 0): ?>
                Este lote no tiene registros para revision.
              <?php else: ?>
                Sin registros para la revision.
              <?php endif; ?>
            </td>
          </tr>
        <?php else: ?>
            <?php foreach ($registros as $registro): ?>
              <?php
                $puede_seleccionar = empty($registro['pasado_a_orden_id']);
                $clase_estado = match ($registro['estado_revision']) {
                  'listo' => 'text-success',
                  'sin_proyecto' => 'text-danger',
                  'conflicto_proyecto' => 'text-warning fw-semibold',
                  'pasado' => 'text-primary',
                  default => 'text-secondary',
                };
                $clase_comparacion = match ($registro['comparacion_monto']) {
                  'distinto' => 'text-danger fw-semibold',
                  'igual' => 'text-success',
                  'sin_moneda' => 'text-warning fw-semibold',
                  'sin_tc' => 'text-danger',
                  default => 'text-secondary',
                };
              ?>
              <tr>
                <td>
                  <?php if ($puede_seleccionar): ?>
                    <input type="checkbox" class="form-check-input" name="seleccionados[]" value="<?= (int)$registro['id'] ?>" data-import-check>
                  <?php endif; ?>
                </td>
                <td><?= (int)$registro['id'] ?></td>
                <td>
                  <div class="fw-semibold">#<?= (int)$registro['lote_id'] ?></div>
                  <div class="small text-secondary"><?= htmlspecialchars((string)($registro['lote_creado_en'] ?? '')) ?></div>
                </td>
                <td><?= htmlspecialchars($registro['documento_compra'] ?? '-') ?></td>
                <td><?= htmlspecialchars($registro['numero_doc_refer'] ?? '-') ?></td>
                <td><?= htmlspecialchars($registro['fecha_contable'] ?? '-') ?></td>
                <td><?= htmlspecialchars($registro['proveedor_nombre'] ?? '-') ?></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($registro['detalle_actividad'] ?? '-') ?></div>
                  <div class="small text-secondary"><?= htmlspecialchars($registro['texto_pedido'] ?? '') ?></div>
                </td>
                <td>
                  <?php if (!empty($registro['proyecto_id'])): ?>
                    <div class="fw-semibold"><?= htmlspecialchars((string)($registro['proyecto_codigo'] ?? '')) ?></div>
                    <div class="small text-secondary"><?= htmlspecialchars((string)($registro['proyecto_nombre'] ?? '')) ?></div>
                  <?php else: ?>
                    <span class="text-danger">Sin proyecto</span>
                  <?php endif; ?>
                </td>
                <td><?= !empty($registro['proyecto_oc_id']) ? (int)$registro['proyecto_oc_id'] : '-' ?></td>
                <td><?= !empty($registro['proyecto_actividad_id']) ? (int)$registro['proyecto_actividad_id'] : '-' ?></td>
                <td><?= htmlspecialchars((string)($registro['origen_proyecto'] ?? '-')) ?></td>
                <td class="text-end"><?= number_format((float)$registro['monto'], 0, ',', '.') ?></td>
                <td>CLP</td>
                <td>
                  <?php if (!empty($registro['orden_existente_id'])): ?>
                    <span class="badge bg-warning text-dark">Si</span>
                    <div class="small text-secondary">#<?= (int)$registro['orden_existente_id'] ?> <?= htmlspecialchars((string)($registro['estado_orden_existente'] ?? '')) ?></div>
                  <?php else: ?>
                    <span class="text-secondary">No</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)($registro['moneda_orden_existente_codigo'] ?? '-')) ?></td>
                <td class="text-end"><?= $registro['monto_orden_existente'] !== null ? number_format((float)$registro['monto_orden_existente'], 0, ',', '.') : '-' ?></td>
                <td class="text-end"><?= $registro['monto_convertido_orden'] !== null ? number_format((float)$registro['monto_convertido_orden'], 0, ',', '.') : number_format((float)$registro['monto'], 0, ',', '.') ?></td>
                <td class="<?= $clase_comparacion ?>"><?= htmlspecialchars((string)($registro['comparacion_monto'] ?? '-')) ?></td>
                <td><?= htmlspecialchars($registro['contrato_marco'] ?? '-') ?></td>
                <td class="<?= $clase_estado ?>"><?= htmlspecialchars($registro['estado_revision']) ?></td>
                <td><?= htmlspecialchars($registro['observacion_revision'] ?? '-') ?></td>
                <td>
                  <?php if (!empty($registro['pasado_a_orden_id'])): ?>
                    <a href="/ceofinanzas/public/ejecucion.php" class="btn btn-sm btn-outline-primary">#<?= (int)$registro['pasado_a_orden_id'] ?></a>
                  <?php else: ?>
                    <span class="text-secondary">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
