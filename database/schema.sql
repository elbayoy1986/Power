-- =====================================================================
-- Power POS · Plataforma de comidas rápidas + mini ERP multi-tienda
-- MySQL 8.0+ · utf8mb4 · Montos en CENTAVOS (INT) para evitar redondeos
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS power_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE power_pos;

-- ---------------------------------------------------------------------
-- Organización
-- ---------------------------------------------------------------------
CREATE TABLE empresa (
  id               TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  razon_social     VARCHAR(150) NOT NULL,
  nombre_comercial VARCHAR(150) NOT NULL,
  rtn              CHAR(14)     NOT NULL,
  direccion        VARCHAR(255) NOT NULL,
  telefono         VARCHAR(30),
  email            VARCHAR(120),
  CONSTRAINT chk_empresa_unica CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE tiendas (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo                  VARCHAR(10)  NOT NULL UNIQUE,
  nombre                  VARCHAR(100) NOT NULL,
  direccion               VARCHAR(255) NOT NULL,
  telefono                VARCHAR(30),
  codigo_establecimiento  CHAR(3)      NOT NULL COMMENT 'Código SAR del establecimiento (000)',
  activa                  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id      INT UNSIGNED NULL COMMENT 'NULL = acceso a todas las tiendas',
  nombre         VARCHAR(100) NOT NULL,
  email          VARCHAR(120) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  rol            ENUM('ADMIN','GERENTE','CAJERO') NOT NULL DEFAULT 'CAJERO',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Catálogo central (precio base) + precio por tienda (opcional)
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  orden  SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE productos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  categoria_id     INT UNSIGNED NOT NULL,
  sku              VARCHAR(30)  NOT NULL UNIQUE,
  nombre           VARCHAR(120) NOT NULL,
  precio_centavos  INT UNSIGNED NOT NULL COMMENT 'Precio de venta CON ISV',
  tipo_impuesto    ENUM('G15','G18','EXE') NOT NULL DEFAULT 'G15'
                   COMMENT 'G15 = ISV 15%, G18 = ISV 18% (bebidas alcohólicas), EXE = exento',
  vendible_solo    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = solo como opción de combo',
  controla_stock   TINYINT(1) NOT NULL DEFAULT 1,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB;

CREATE TABLE precios_tienda (
  tienda_id        INT UNSIGNED NOT NULL,
  producto_id      INT UNSIGNED NOT NULL,
  precio_centavos  INT UNSIGNED NOT NULL,
  PRIMARY KEY (tienda_id, producto_id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Combos: precio fijo + grupos de selección ("Elige tu bebida")
-- ---------------------------------------------------------------------
CREATE TABLE combos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  categoria_id     INT UNSIGNED NOT NULL,
  nombre           VARCHAR(120) NOT NULL,
  descripcion      VARCHAR(255),
  precio_centavos  INT UNSIGNED NOT NULL COMMENT 'Precio CON ISV',
  tipo_impuesto    ENUM('G15','G18','EXE') NOT NULL DEFAULT 'G15',
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB;

CREATE TABLE combo_grupos (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  combo_id  INT UNSIGNED NOT NULL,
  nombre    VARCHAR(80)  NOT NULL,
  min_sel   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_sel   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  orden     SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (combo_id) REFERENCES combos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE combo_opciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id          INT UNSIGNED NOT NULL,
  producto_id       INT UNSIGNED NOT NULL,
  recargo_centavos  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ej. agrandar papas +L 15',
  por_defecto       TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (grupo_id)    REFERENCES combo_grupos(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Promociones mix & match
-- ---------------------------------------------------------------------
CREATE TABLE promociones (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(120) NOT NULL,
  tipo             ENUM('MIX_PRECIO','NXM','PORCENTAJE','X_LLEVA_Y') NOT NULL,
  cantidad_n       TINYINT UNSIGNED NULL COMMENT 'MIX_PRECIO / NXM: cuántos lleva',
  cantidad_m       TINYINT UNSIGNED NULL COMMENT 'NXM: cuántos paga',
  precio_centavos  INT UNSIGNED NULL     COMMENT 'MIX_PRECIO: precio del grupo',
  porcentaje       DECIMAL(5,2) NULL     COMMENT 'PORCENTAJE / X_LLEVA_Y (100 = gratis)',
  prioridad        SMALLINT NOT NULL DEFAULT 100 COMMENT 'Menor = se evalúa primero',
  fecha_inicio     DATE NOT NULL,
  fecha_fin        DATE NOT NULL,
  dias_semana      VARCHAR(7) NOT NULL DEFAULT '1234567' COMMENT 'ISO: 1=lunes ... 7=domingo',
  hora_inicio      TIME NOT NULL DEFAULT '00:00:00',
  hora_fin         TIME NOT NULL DEFAULT '23:59:59',
  activa           TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE promocion_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promocion_id  INT UNSIGNED NOT NULL,
  grupo         ENUM('A','B') NOT NULL DEFAULT 'A' COMMENT 'B = artículos premio en X_LLEVA_Y',
  producto_id   INT UNSIGNED NULL,
  combo_id      INT UNSIGNED NULL,
  categoria_id  INT UNSIGNED NULL,
  FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id)  REFERENCES productos(id),
  FOREIGN KEY (combo_id)     REFERENCES combos(id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB;

CREATE TABLE promocion_tiendas (
  promocion_id INT UNSIGNED NOT NULL,
  tienda_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (promocion_id, tienda_id),
  FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE,
  FOREIGN KEY (tienda_id)    REFERENCES tiendas(id)
) ENGINE=InnoDB COMMENT='Sin filas = aplica a todas las tiendas';

-- ---------------------------------------------------------------------
-- Descuentos de ley (Honduras) — PARAMETRIZABLES, validar con asesor legal
-- ---------------------------------------------------------------------
CREATE TABLE descuentos_legales (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo              VARCHAR(20)  NOT NULL UNIQUE,
  nombre              VARCHAR(100) NOT NULL,
  porcentaje          DECIMAL(5,2) NOT NULL,
  tope_centavos       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = sin tope de consumo',
  edad_minima         TINYINT UNSIGNED NULL,
  acumulable_promos   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = se otorga el mayor beneficio',
  exento_isv          TINYINT(1) NOT NULL DEFAULT 0,
  requiere_documento  TINYINT(1) NOT NULL DEFAULT 1,
  base_legal          VARCHAR(255),
  activo              TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Facturación SAR: CAI por establecimiento + punto de emisión
-- Número de factura: EEE-PPP-TT-NNNNNNNN  (ej. 001-001-01-00000001)
-- ---------------------------------------------------------------------
CREATE TABLE cai (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id           INT UNSIGNED NOT NULL,
  cai                 CHAR(37) NOT NULL UNIQUE COMMENT 'XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX',
  punto_emision       CHAR(3)  NOT NULL DEFAULT '001',
  tipo_documento      CHAR(2)  NOT NULL DEFAULT '01' COMMENT '01 = factura',
  rango_inicial       INT UNSIGNED NOT NULL,
  rango_final         INT UNSIGNED NOT NULL,
  correlativo_actual  INT UNSIGNED NOT NULL COMMENT 'Último número emitido (inicia en rango_inicial - 1)',
  fecha_limite        DATE NOT NULL COMMENT 'Fecha límite de emisión',
  activo              TINYINT(1) NOT NULL DEFAULT 1,
  creado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id),
  CONSTRAINT chk_rango CHECK (rango_final >= rango_inicial),
  INDEX idx_cai_tienda (tienda_id, activo, fecha_limite)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Ventas / facturas (snapshot inmutable de lo emitido)
-- ---------------------------------------------------------------------
CREATE TABLE ventas (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id               INT UNSIGNED NOT NULL,
  usuario_id              INT UNSIGNED NOT NULL,
  cai_id                  INT UNSIGNED NOT NULL,
  cai                     CHAR(37) NOT NULL,
  numero_factura          CHAR(19) NOT NULL UNIQUE,
  rango_autorizado        VARCHAR(45) NOT NULL,
  fecha_limite_emision    DATE NOT NULL,
  fecha                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cliente_rtn             CHAR(14) NULL,
  cliente_nombre          VARCHAR(150) NOT NULL DEFAULT 'CONSUMIDOR FINAL',
  descuento_legal_id      INT UNSIGNED NULL,
  beneficiario_nombre     VARCHAR(150) NULL,
  beneficiario_documento  VARCHAR(30)  NULL COMMENT 'DNI / carné adulto mayor',
  subtotal                INT NOT NULL,
  descuento_promos        INT NOT NULL DEFAULT 0,
  descuento_legal         INT NOT NULL DEFAULT 0,
  importe_exonerado       INT NOT NULL DEFAULT 0,
  importe_exento          INT NOT NULL DEFAULT 0,
  importe_gravado_15      INT NOT NULL DEFAULT 0,
  importe_gravado_18      INT NOT NULL DEFAULT 0,
  isv_15                  INT NOT NULL DEFAULT 0,
  isv_18                  INT NOT NULL DEFAULT 0,
  total                   INT NOT NULL,
  total_letras            VARCHAR(255) NOT NULL,
  forma_pago              ENUM('EFECTIVO','TARJETA','TRANSFERENCIA','MIXTO') NOT NULL DEFAULT 'EFECTIVO',
  estado                  ENUM('EMITIDA','ANULADA') NOT NULL DEFAULT 'EMITIDA',
  FOREIGN KEY (tienda_id)          REFERENCES tiendas(id),
  FOREIGN KEY (usuario_id)         REFERENCES usuarios(id),
  FOREIGN KEY (cai_id)             REFERENCES cai(id),
  FOREIGN KEY (descuento_legal_id) REFERENCES descuentos_legales(id),
  INDEX idx_ventas_tienda_fecha (tienda_id, fecha)
) ENGINE=InnoDB;

CREATE TABLE venta_lineas (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_id         BIGINT UNSIGNED NOT NULL,
  tipo             ENUM('PRODUCTO','COMBO') NOT NULL,
  producto_id      INT UNSIGNED NULL,
  combo_id         INT UNSIGNED NULL,
  descripcion      VARCHAR(255) NOT NULL,
  cantidad         SMALLINT UNSIGNED NOT NULL,
  precio_unitario  INT NOT NULL,
  descuento_promo  INT NOT NULL DEFAULT 0,
  descuento_legal  INT NOT NULL DEFAULT 0,
  tipo_impuesto    ENUM('G15','G18','EXE') NOT NULL,
  total            INT NOT NULL,
  FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE venta_linea_opciones (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_linea_id  BIGINT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NOT NULL,
  descripcion     VARCHAR(150) NOT NULL,
  recargo         INT NOT NULL DEFAULT 0,
  FOREIGN KEY (venta_linea_id) REFERENCES venta_lineas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Mini ERP: inventario por tienda
-- ---------------------------------------------------------------------
CREATE TABLE inventario (
  tienda_id    INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  existencia   DECIMAL(12,3) NOT NULL DEFAULT 0,
  minimo       DECIMAL(12,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (tienda_id, producto_id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

CREATE TABLE inventario_movimientos (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id    INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  tipo         ENUM('VENTA','ENTRADA','AJUSTE','TRASLADO_SALIDA','TRASLADO_ENTRADA','MERMA') NOT NULL,
  cantidad     DECIMAL(12,3) NOT NULL COMMENT 'Negativo = salida',
  referencia   VARCHAR(60),
  fecha        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  INDEX idx_mov_tienda_fecha (tienda_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Vistas para el tablero central (ERP)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_ventas_diarias AS
SELECT v.tienda_id, t.nombre AS tienda, DATE(v.fecha) AS dia,
       COUNT(*) AS facturas, SUM(v.total) AS total, AVG(v.total) AS ticket_promedio,
       SUM(v.descuento_promos) AS descuento_promos, SUM(v.descuento_legal) AS descuento_legal,
       SUM(v.isv_15 + v.isv_18) AS isv
FROM ventas v JOIN tiendas t ON t.id = v.tienda_id
WHERE v.estado = 'EMITIDA'
GROUP BY v.tienda_id, t.nombre, DATE(v.fecha);

CREATE OR REPLACE VIEW v_estado_cai AS
SELECT c.id, t.nombre AS tienda, c.cai, c.punto_emision, c.fecha_limite,
       c.rango_final - c.correlativo_actual AS disponibles,
       DATEDIFF(c.fecha_limite, CURDATE()) AS dias_restantes
FROM cai c JOIN tiendas t ON t.id = c.tienda_id
WHERE c.activo = 1;
