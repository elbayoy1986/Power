-- Migración para instalaciones creadas con la versión 1 (schema.sql ya la incluye para instalaciones nuevas).
-- Agrega: turnos de caja (apertura/cierre), movimientos de efectivo, proveedores, compras y costo de inventario.
USE power_pos;
SET NAMES utf8mb4;

CREATE TABLE turnos_caja (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id          INT UNSIGNED NOT NULL,
  usuario_id         INT UNSIGNED NOT NULL,
  apertura           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fondo_inicial      INT UNSIGNED NOT NULL,
  cierre             DATETIME NULL,
  cerrado_por        INT UNSIGNED NULL,
  efectivo_esperado  INT NULL,
  efectivo_contado   INT NULL,
  diferencia         INT NULL,
  conteo             TEXT NULL,
  notas              VARCHAR(255) NULL,
  estado             ENUM('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  abierto            TINYINT GENERATED ALWAYS AS (CASE WHEN estado = 'ABIERTO' THEN 1 END) STORED,
  UNIQUE KEY uq_un_turno_abierto (usuario_id, abierto),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  FOREIGN KEY (cerrado_por) REFERENCES usuarios(id),
  INDEX idx_turnos_tienda (tienda_id, apertura)
) ENGINE=InnoDB;

CREATE TABLE caja_movimientos (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  turno_id    INT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  tipo        ENUM('INGRESO','RETIRO') NOT NULL,
  monto       INT UNSIGNED NOT NULL,
  motivo      VARCHAR(150) NOT NULL,
  fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (turno_id)   REFERENCES turnos_caja(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

ALTER TABLE ventas
  ADD COLUMN turno_id INT UNSIGNED NULL AFTER usuario_id,
  ADD COLUMN monto_efectivo INT NOT NULL DEFAULT 0 AFTER forma_pago,
  ADD FOREIGN KEY (turno_id) REFERENCES turnos_caja(id);
-- Ventas históricas en efectivo: todo el total fue efectivo
UPDATE ventas SET monto_efectivo = total WHERE forma_pago = 'EFECTIVO';

ALTER TABLE inventario ADD COLUMN costo_promedio INT UNSIGNED NOT NULL DEFAULT 0 AFTER minimo;
ALTER TABLE inventario_movimientos
  MODIFY tipo ENUM('VENTA','COMPRA','ENTRADA','AJUSTE','TRASLADO_SALIDA','TRASLADO_ENTRADA','MERMA') NOT NULL,
  ADD COLUMN costo_unitario INT UNSIGNED NULL AFTER referencia,
  ADD COLUMN usuario_id INT UNSIGNED NULL AFTER costo_unitario,
  ADD COLUMN motivo VARCHAR(150) NULL AFTER usuario_id;

CREATE TABLE proveedores (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rtn       CHAR(14) NULL UNIQUE,
  nombre    VARCHAR(150) NOT NULL,
  telefono  VARCHAR(30),
  email     VARCHAR(120),
  activo    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE compras (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id         INT UNSIGNED NOT NULL,
  proveedor_id      INT UNSIGNED NOT NULL,
  usuario_id        INT UNSIGNED NOT NULL,
  numero_documento  VARCHAR(40) NOT NULL,
  fecha_documento   DATE NOT NULL,
  estado            ENUM('BORRADOR','RECIBIDA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
  subtotal          INT NOT NULL DEFAULT 0,
  isv               INT NOT NULL DEFAULT 0,
  total             INT NOT NULL DEFAULT 0,
  notas             VARCHAR(255),
  creada_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recibida_en       DATETIME NULL,
  recibida_por      INT UNSIGNED NULL,
  UNIQUE KEY uq_doc_proveedor (proveedor_id, numero_documento),
  FOREIGN KEY (tienda_id)    REFERENCES tiendas(id),
  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
  FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
  FOREIGN KEY (recibida_por) REFERENCES usuarios(id),
  INDEX idx_compras_tienda_fecha (tienda_id, fecha_documento)
) ENGINE=InnoDB;

CREATE TABLE compra_lineas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  compra_id       INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  cantidad        DECIMAL(12,3) NOT NULL,
  costo_unitario  INT UNSIGNED NOT NULL,
  tipo_impuesto   ENUM('G15','G18','EXE') NOT NULL DEFAULT 'G15',
  subtotal        INT NOT NULL,
  isv             INT NOT NULL,
  FOREIGN KEY (compra_id)   REFERENCES compras(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT chk_compra_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB;
