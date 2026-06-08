CREATE TABLE IF NOT EXISTS ceo_areas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_ceo_areas_nombre (nombre)
);

CREATE TABLE IF NOT EXISTS ceo_proyectos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  area_id INT NOT NULL,
  codigo VARCHAR(50) NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_ceo_proyecto (area_id, codigo, nombre),
  KEY idx_ceo_proyectos_area (area_id),
  CONSTRAINT fk_ceo_proyectos_area FOREIGN KEY (area_id) REFERENCES ceo_areas(id)
);

CREATE TABLE IF NOT EXISTS ceo_clase_costo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(10) NOT NULL,
  subclase VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_ceo_clase (tipo, subclase)
);

CREATE TABLE IF NOT EXISTS ceo_monedas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(10) NOT NULL,
  UNIQUE KEY uq_ceo_monedas_codigo (codigo)
);

CREATE TABLE IF NOT EXISTS ceo_proveedores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(200) NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  UNIQUE KEY uq_ceo_proveedores_nombre (nombre)
);

CREATE TABLE IF NOT EXISTS ceo_presupuesto_mensual (
  id INT AUTO_INCREMENT PRIMARY KEY,
  area_id INT NOT NULL,
  proyecto_id INT NOT NULL,
  ceco VARCHAR(50) DEFAULT NULL,
  clase_costo_id INT NOT NULL,
  anio INT NOT NULL,
  mes TINYINT NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  moneda_id INT NOT NULL,
  origen_hoja VARCHAR(20) NOT NULL,
  UNIQUE KEY uq_ceo_presupuesto (area_id, proyecto_id, ceco, clase_costo_id, anio, mes, origen_hoja),
  KEY idx_ceo_presupuesto_anio (anio),
  CONSTRAINT fk_ceo_presupuesto_area FOREIGN KEY (area_id) REFERENCES ceo_areas(id),
  CONSTRAINT fk_ceo_presupuesto_proyecto FOREIGN KEY (proyecto_id) REFERENCES ceo_proyectos(id),
  CONSTRAINT fk_ceo_presupuesto_clase FOREIGN KEY (clase_costo_id) REFERENCES ceo_clase_costo(id),
  CONSTRAINT fk_ceo_presupuesto_moneda FOREIGN KEY (moneda_id) REFERENCES ceo_monedas(id)
);

CREATE TABLE IF NOT EXISTS ceo_ordenes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  oc VARCHAR(50) NOT NULL,
  contrato VARCHAR(50) DEFAULT NULL,
  fecha_entrega DATE DEFAULT NULL,
  fecha_contable DATE DEFAULT NULL,
  moneda_id INT NOT NULL,
  pep VARCHAR(80) DEFAULT NULL,
  tipo_presupuesto VARCHAR(10) NOT NULL DEFAULT 'OPEX',
  observacion VARCHAR(255) DEFAULT NULL,
  sociedad VARCHAR(20) NOT NULL DEFAULT 'CL13',
  proyecto_id INT NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  monto_comprometido DECIMAL(18,2) NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'Registrado',
  estado_detalle VARCHAR(50) DEFAULT NULL,
  estado_detalle_otro VARCHAR(120) DEFAULT NULL,
  hes VARCHAR(50) DEFAULT NULL,
  eliminada TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_ordenes_proyecto (proyecto_id),
  CONSTRAINT fk_ceo_ordenes_moneda FOREIGN KEY (moneda_id) REFERENCES ceo_monedas(id),
  CONSTRAINT fk_ceo_ordenes_proyecto FOREIGN KEY (proyecto_id) REFERENCES ceo_proyectos(id)
);

CREATE TABLE IF NOT EXISTS ceo_import_gastos_lotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archivo_original VARCHAR(255) NOT NULL,
  usuario VARCHAR(120) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ceo_import_gastos (
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
 );

CREATE TABLE IF NOT EXISTS ceo_ordenes_adjuntos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  ruta VARCHAR(255) NOT NULL,
  tipo_mime VARCHAR(100) NOT NULL,
  tamano INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_adjuntos_orden (orden_id),
  CONSTRAINT fk_ceo_adjuntos_orden FOREIGN KEY (orden_id) REFERENCES ceo_ordenes(id)
);

CREATE TABLE IF NOT EXISTS ceo_presupuesto_movimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  fecha DATE NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Registrado',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_movimientos_orden (orden_id),
  CONSTRAINT fk_ceo_movimientos_orden FOREIGN KEY (orden_id) REFERENCES ceo_ordenes(id)
);

CREATE TABLE IF NOT EXISTS ceo_tipo_cambio (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  moneda VARCHAR(10) NOT NULL,
  valor_clp DECIMAL(18,6) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ceo_tipo_cambio (fecha, moneda)
);

CREATE TABLE IF NOT EXISTS ceo_facturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proveedor_id INT NOT NULL,
  proyecto_id INT NOT NULL,
  numero VARCHAR(50) NOT NULL,
  fecha DATE NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  moneda_id INT NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Registrado',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_facturas_proyecto (proyecto_id),
  CONSTRAINT fk_ceo_facturas_proveedor FOREIGN KEY (proveedor_id) REFERENCES ceo_proveedores(id),
  CONSTRAINT fk_ceo_facturas_proyecto FOREIGN KEY (proyecto_id) REFERENCES ceo_proyectos(id),
  CONSTRAINT fk_ceo_facturas_moneda FOREIGN KEY (moneda_id) REFERENCES ceo_monedas(id)
);

CREATE TABLE IF NOT EXISTS ceo_actividad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(120) NOT NULL,
  accion VARCHAR(120) NOT NULL,
  detalle VARCHAR(255) DEFAULT NULL,
  url VARCHAR(255) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ceo_pagos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  factura_id INT NOT NULL,
  fecha_pago DATE NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  moneda_id INT NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Pagado',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_pagos_factura (factura_id),
  CONSTRAINT fk_ceo_pagos_factura FOREIGN KEY (factura_id) REFERENCES ceo_facturas(id),
  CONSTRAINT fk_ceo_pagos_moneda FOREIGN KEY (moneda_id) REFERENCES ceo_monedas(id)
);

INSERT IGNORE INTO ceo_monedas (codigo) VALUES ('CLP'), ('UF'), ('USD'), ('EUR');
