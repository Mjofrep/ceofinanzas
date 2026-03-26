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
  moneda_id INT NOT NULL,
  pep VARCHAR(80) DEFAULT NULL,
  sociedad VARCHAR(20) NOT NULL DEFAULT 'CL13',
  proyecto_id INT NOT NULL,
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'Registrado',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ceo_ordenes_proyecto (proyecto_id),
  CONSTRAINT fk_ceo_ordenes_moneda FOREIGN KEY (moneda_id) REFERENCES ceo_monedas(id),
  CONSTRAINT fk_ceo_ordenes_proyecto FOREIGN KEY (proyecto_id) REFERENCES ceo_proyectos(id)
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
