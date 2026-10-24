-- =====================================================================
-- Power POS · Comidas rápidas y restaurantes (mesas y cocina) + mini ERP multi-tienda
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
  rol            ENUM('ADMIN','GERENTE','CAJERO','MESERO','COCINA') NOT NULL DEFAULT 'CAJERO'
                 COMMENT 'MESERO = toma pedidos en mesa sin cobrar; COCINA = solo ve la pantalla de su estación',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Estaciones de preparación (Cocina, Bar, Postres...). Cada una recibe sus
-- comandas en pantalla y/o impresora. Un producto va a la estación de su
-- categoría, salvo que el producto indique otra.
-- ---------------------------------------------------------------------
CREATE TABLE estaciones (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre  VARCHAR(60) NOT NULL UNIQUE,
  orden   SMALLINT NOT NULL DEFAULT 0,
  activa  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Catálogo central (precio base) + precio por tienda (opcional)
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(80) NOT NULL,
  orden       SMALLINT NOT NULL DEFAULT 0,
  estacion_id INT UNSIGNED NULL COMMENT 'Dónde se prepara; NULL = primera estación activa',
  FOREIGN KEY (estacion_id) REFERENCES estaciones(id)
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
  estacion_id      INT UNSIGNED NULL COMMENT 'NULL = la estación de su categoría',
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  FOREIGN KEY (estacion_id)  REFERENCES estaciones(id)
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
-- Caja: turnos (apertura / cierre con arqueo) y movimientos de efectivo
-- ---------------------------------------------------------------------
CREATE TABLE turnos_caja (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id          INT UNSIGNED NOT NULL,
  usuario_id         INT UNSIGNED NOT NULL COMMENT 'Cajero responsable del efectivo',
  apertura           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fondo_inicial      INT UNSIGNED NOT NULL COMMENT 'Efectivo con que abre (centavos)',
  cierre             DATETIME NULL,
  cerrado_por        INT UNSIGNED NULL,
  efectivo_esperado  INT NULL COMMENT 'fondo + ventas en efectivo + ingresos - retiros',
  efectivo_contado   INT NULL,
  diferencia         INT NULL COMMENT 'contado - esperado (negativo = faltante)',
  conteo             TEXT NULL COMMENT 'JSON de denominaciones contadas',
  notas              VARCHAR(255) NULL,
  estado             ENUM('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  -- Solo un turno ABIERTO por usuario: la columna vale 1 si está abierto y NULL si no (NULL no choca en UNIQUE)
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
  tipo        ENUM('INGRESO','RETIRO') NOT NULL COMMENT 'RETIRO = remesa a bóveda, pago menor...',
  monto       INT UNSIGNED NOT NULL,
  motivo      VARCHAR(150) NOT NULL,
  fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (turno_id)   REFERENCES turnos_caja(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Restaurante: salón por zonas, mesas y cuentas abiertas por mesa
-- ---------------------------------------------------------------------
CREATE TABLE zonas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id  INT UNSIGNED NOT NULL,
  nombre     VARCHAR(60) NOT NULL COMMENT 'Salón, Terraza, VIP...',
  orden      SMALLINT NOT NULL DEFAULT 0,
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_zona (tienda_id, nombre),
  FOREIGN KEY (tienda_id) REFERENCES tiendas(id)
) ENGINE=InnoDB;

CREATE TABLE mesas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id  INT UNSIGNED NOT NULL,
  zona_id    INT UNSIGNED NOT NULL,
  nombre     VARCHAR(20) NOT NULL COMMENT 'Ej. 12, T3, Barra 1',
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
  estado            ENUM('ABIERTA','POR_COBRAR','COBRADA','ANULADA') NOT NULL DEFAULT 'ABIERTA'
                    COMMENT 'POR_COBRAR = el cliente pidió la cuenta',
  abierta_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrada_en        DATETIME NULL,
  cerrada_por       INT UNSIGNED NULL,
  motivo_anulacion  VARCHAR(150) NULL,
  -- Una sola cuenta activa por mesa: vale mesa_id mientras está activa y NULL después (NULL no choca en UNIQUE)
  mesa_activa       INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN estado IN ('ABIERTA','POR_COBRAR') THEN mesa_id END) STORED,
  UNIQUE KEY uq_mesa_activa (mesa_activa),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (mesa_id)     REFERENCES mesas(id),
  FOREIGN KEY (mesero_id)   REFERENCES usuarios(id),
  FOREIGN KEY (cerrada_por) REFERENCES usuarios(id),
  INDEX idx_cuentas_tienda (tienda_id, estado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Ventas / facturas (snapshot inmutable de lo emitido)
-- ---------------------------------------------------------------------
CREATE TABLE ventas (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id               INT UNSIGNED NOT NULL,
  usuario_id              INT UNSIGNED NOT NULL,
  turno_id                INT UNSIGNED NULL COMMENT 'Turno de caja en que se cobró',
  cuenta_id               BIGINT UNSIGNED NULL COMMENT 'Cuenta de mesa cobrada (NULL = venta de mostrador)',
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
  monto_efectivo          INT NOT NULL DEFAULT 0 COMMENT 'Parte del total cobrada en efectivo (arqueo)',
  propina                 INT NOT NULL DEFAULT 0 COMMENT 'Voluntaria: fuera del total facturado y de la base del ISV',
  propina_efectivo        INT NOT NULL DEFAULT 0 COMMENT 'Parte de la propina recibida en efectivo (arqueo)',
  estado                  ENUM('EMITIDA','ANULADA') NOT NULL DEFAULT 'EMITIDA',
  FOREIGN KEY (tienda_id)          REFERENCES tiendas(id),
  FOREIGN KEY (usuario_id)         REFERENCES usuarios(id),
  FOREIGN KEY (turno_id)           REFERENCES turnos_caja(id),
  FOREIGN KEY (cuenta_id)          REFERENCES cuentas(id),
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
-- Pedidos de mesa y comandas a cocina
--   cuenta_items: lo que se pidió en la mesa, ronda por ronda (snapshot del nombre)
--   comandas:     lo que recibe cada estación; un combo se reparte entre estaciones
--                 (la hamburguesa a Cocina, el refresco a Bar)
-- ---------------------------------------------------------------------
CREATE TABLE cuenta_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id         BIGINT UNSIGNED NOT NULL,
  ronda             SMALLINT UNSIGNED NOT NULL COMMENT '1 = primer envío a cocina, 2 = segundo...',
  tipo              ENUM('PRODUCTO','COMBO') NOT NULL,
  producto_id       INT UNSIGNED NULL,
  combo_id          INT UNSIGNED NULL,
  descripcion       VARCHAR(150) NOT NULL,
  opciones          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'IDs de producto elegidos en el combo, separados por coma',
  detalle           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Opciones del combo en texto',
  cantidad          SMALLINT UNSIGNED NOT NULL,
  nota              VARCHAR(150) NULL COMMENT 'Sin cebolla, término medio...',
  estado            ENUM('ACTIVO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  venta_id          BIGINT UNSIGNED NULL COMMENT 'Factura con que se cobró; permite dividir la cuenta',
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
  cuenta_id     BIGINT UNSIGNED NULL COMMENT 'Pedido de mesa',
  venta_id      BIGINT UNSIGNED NULL COMMENT 'Pedido de mostrador (caja rápida)',
  referencia    VARCHAR(60) NOT NULL COMMENT 'Mesa 5 / Mostrador 00000012',
  tipo          ENUM('PEDIDO','ANULACION') NOT NULL DEFAULT 'PEDIDO',
  estado        ENUM('EN_COCINA','LISTA','ENTREGADA') NOT NULL DEFAULT 'EN_COCINA',
  enviada_por   INT UNSIGNED NOT NULL,
  creada_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lista_en      DATETIME NULL,
  entregada_en  DATETIME NULL,
  impresa_en    DATETIME NULL COMMENT 'La primera impresora que la toma la marca: nunca sale dos veces',
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
  detalle         VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Componentes del combo que prepara esta estación',
  nota            VARCHAR(150) NULL,
  listo           TINYINT(1) NOT NULL DEFAULT 0,
  anulado         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se anuló después de enviarlo: no se prepara',
  FOREIGN KEY (comanda_id)     REFERENCES comandas(id) ON DELETE CASCADE,
  FOREIGN KEY (cuenta_item_id) REFERENCES cuenta_items(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Mini ERP: inventario por tienda
-- ---------------------------------------------------------------------
CREATE TABLE inventario (
  tienda_id    INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  existencia   DECIMAL(12,3) NOT NULL DEFAULT 0,
  minimo       DECIMAL(12,3) NOT NULL DEFAULT 0,
  costo_promedio INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Costo unitario promedio ponderado SIN ISV (centavos)',
  PRIMARY KEY (tienda_id, producto_id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

CREATE TABLE inventario_movimientos (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tienda_id    INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  tipo         ENUM('VENTA','COMPRA','ENTRADA','AJUSTE','TRASLADO_SALIDA','TRASLADO_ENTRADA','MERMA') NOT NULL,
  cantidad     DECIMAL(12,3) NOT NULL COMMENT 'Negativo = salida',
  referencia   VARCHAR(60),
  costo_unitario INT UNSIGNED NULL COMMENT 'Costo sin ISV en compras y traslados',
  usuario_id   INT UNSIGNED NULL,
  motivo       VARCHAR(150) NULL COMMENT 'Obligatorio en ajustes y mermas',
  fecha        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  INDEX idx_mov_tienda_fecha (tienda_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Compras a proveedores (entran al inventario al RECIBIR)
-- ---------------------------------------------------------------------
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
  numero_documento  VARCHAR(40) NOT NULL COMMENT 'No. de factura del proveedor',
  fecha_documento   DATE NOT NULL,
  estado            ENUM('BORRADOR','RECIBIDA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
  subtotal          INT NOT NULL DEFAULT 0,
  isv               INT NOT NULL DEFAULT 0,
  total             INT NOT NULL DEFAULT 0,
  notas             VARCHAR(255),
  creada_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recibida_en       DATETIME NULL,
  recibida_por      INT UNSIGNED NULL,
  UNIQUE KEY uq_doc_proveedor (proveedor_id, numero_documento) COMMENT 'Evita cargar dos veces la misma factura',
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
  costo_unitario  INT UNSIGNED NOT NULL COMMENT 'Sin ISV (centavos)',
  tipo_impuesto   ENUM('G15','G18','EXE') NOT NULL DEFAULT 'G15',
  subtotal        INT NOT NULL,
  isv             INT NOT NULL,
  FOREIGN KEY (compra_id)   REFERENCES compras(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT chk_compra_cantidad CHECK (cantidad > 0)
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
