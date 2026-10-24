-- Migración para instalaciones existentes (schema.sql ya la incluye para instalaciones nuevas).
-- Agrega: roles de mesero y cocina, estaciones de preparación, zonas, mesas, cuentas por mesa,
-- comandas a cocina y propina en las ventas.
USE power_pos;
SET NAMES utf8mb4;

ALTER TABLE usuarios
  MODIFY rol ENUM('ADMIN','GERENTE','CAJERO','MESERO','COCINA') NOT NULL DEFAULT 'CAJERO';

CREATE TABLE estaciones (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre  VARCHAR(60) NOT NULL UNIQUE,
  orden   SMALLINT NOT NULL DEFAULT 0,
  activa  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
-- Todo va a Cocina hasta que configure otras estaciones (Bar, Postres...) en Salón y cocina
INSERT INTO estaciones (nombre, orden) VALUES ('Cocina', 1);

ALTER TABLE categorias
  ADD COLUMN estacion_id INT UNSIGNED NULL,
  ADD FOREIGN KEY (estacion_id) REFERENCES estaciones(id);
ALTER TABLE productos
  ADD COLUMN estacion_id INT UNSIGNED NULL AFTER controla_stock,
  ADD FOREIGN KEY (estacion_id) REFERENCES estaciones(id);

CREATE TABLE zonas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id  INT UNSIGNED NOT NULL,
  nombre     VARCHAR(60) NOT NULL,
  orden      SMALLINT NOT NULL DEFAULT 0,
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_zona (tienda_id, nombre),
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id)
) ENGINE=InnoDB;

CREATE TABLE mesas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id  INT UNSIGNED NOT NULL,
  zona_id    INT UNSIGNED NOT NULL,
  nombre     VARCHAR(20) NOT NULL,
  capacidad  TINYINT UNSIGNED NOT NULL DEFAULT 4,
  orden      SMALLINT NOT NULL DEFAULT 0,
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mesa (tienda_id, nombre),
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id),
  FOREIGN KEY (zona_id)   REFERENCES zonas(id)
) ENGINE=InnoDB;

CREATE TABLE cuentas (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id         INT UNSIGNED NOT NULL,
  mesa_id           INT UNSIGNED NOT NULL,
  mesero_id         INT UNSIGNED NOT NULL,
  comensales        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  estado            ENUM('ABIERTA','POR_COBRAR','COBRADA','ANULADA') NOT NULL DEFAULT 'ABIERTA',
  abierta_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrada_en        DATETIME NULL,
  cerrada_por       INT UNSIGNED NULL,
  motivo_anulacion  VARCHAR(150) NULL,
  mesa_activa       INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN estado IN ('ABIERTA','POR_COBRAR') THEN mesa_id END) STORED,
  UNIQUE KEY uq_mesa_activa (mesa_activa),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (mesa_id)     REFERENCES mesas(id),
  FOREIGN KEY (mesero_id)   REFERENCES usuarios(id),
  FOREIGN KEY (cerrada_por) REFERENCES usuarios(id),
  INDEX idx_cuentas_tienda (tienda_id, estado)
) ENGINE=InnoDB;

ALTER TABLE ventas
  ADD COLUMN cuenta_id BIGINT UNSIGNED NULL AFTER turno_id,
  ADD COLUMN propina INT NOT NULL DEFAULT 0 AFTER monto_efectivo,
  ADD COLUMN propina_efectivo INT NOT NULL DEFAULT 0 AFTER propina,
  ADD FOREIGN KEY (cuenta_id) REFERENCES cuentas(id);

CREATE TABLE cuenta_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id         BIGINT UNSIGNED NOT NULL,
  ronda             SMALLINT UNSIGNED NOT NULL,
  tipo              ENUM('PRODUCTO','COMBO') NOT NULL,
  producto_id       INT UNSIGNED NULL,
  combo_id          INT UNSIGNED NULL,
  descripcion       VARCHAR(150) NOT NULL,
  opciones          VARCHAR(255) NOT NULL DEFAULT '',
  detalle           VARCHAR(255) NOT NULL DEFAULT '',
  cantidad          SMALLINT UNSIGNED NOT NULL,
  nota              VARCHAR(150) NULL,
  estado            ENUM('ACTIVO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  venta_id          BIGINT UNSIGNED NULL,
  agregado_por      INT UNSIGNED NOT NULL,
  agregado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  anulado_por       INT UNSIGNED NULL,
  anulado_en        DATETIME NULL,
  motivo_anulacion  VARCHAR(150) NULL,
  FOREIGN KEY (cuenta_id)    REFERENCES cuentas(id),
  FOREIGN KEY (producto_id)  REFERENCES productos(id),
  FOREIGN KEY (combo_id)     REFERENCES combos(id),
  FOREIGN KEY (venta_id)     REFERENCES ventas(id),
  FOREIGN KEY (agregado_por) REFERENCES usuarios(id),
  FOREIGN KEY (anulado_por)  REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE comandas (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id     INT UNSIGNED NOT NULL,
  estacion_id   INT UNSIGNED NOT NULL,
  cuenta_id     BIGINT UNSIGNED NULL,
  venta_id      BIGINT UNSIGNED NULL,
  referencia    VARCHAR(60) NOT NULL,
  tipo          ENUM('PEDIDO','ANULACION') NOT NULL DEFAULT 'PEDIDO',
  estado        ENUM('EN_COCINA','LISTA','ENTREGADA') NOT NULL DEFAULT 'EN_COCINA',
  enviada_por   INT UNSIGNED NOT NULL,
  creada_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lista_en      DATETIME NULL,
  entregada_en  DATETIME NULL,
  impresa_en    DATETIME NULL,
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (estacion_id) REFERENCES estaciones(id),
  FOREIGN KEY (cuenta_id)   REFERENCES cuentas(id),
  FOREIGN KEY (venta_id)    REFERENCES ventas(id),
  FOREIGN KEY (enviada_por) REFERENCES usuarios(id),
  INDEX idx_comandas_pantalla (tienda_id, estado, estacion_id, creada_en)
) ENGINE=InnoDB;

CREATE TABLE comanda_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comanda_id      BIGINT UNSIGNED NOT NULL,
  cuenta_item_id  BIGINT UNSIGNED NULL,
  cantidad        SMALLINT UNSIGNED NOT NULL,
  descripcion     VARCHAR(150) NOT NULL,
  detalle         VARCHAR(255) NOT NULL DEFAULT '',
  nota            VARCHAR(150) NULL,
  listo           TINYINT(1) NOT NULL DEFAULT 0,
  anulado         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se anuló después de enviarlo: no se prepara',
  FOREIGN KEY (comanda_id)     REFERENCES comandas(id) ON DELETE CASCADE,
  FOREIGN KEY (cuenta_item_id) REFERENCES cuenta_items(id)
) ENGINE=InnoDB;
