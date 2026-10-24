-- Datos de demostración. Usuario: admin@power.hn / Power2026!  (cámbielo en producción)
USE power_pos;
SET NAMES utf8mb4;

INSERT INTO empresa (id, razon_social, nombre_comercial, rtn, direccion, telefono, email) VALUES
(1, 'Inversiones Power S. de R.L.', 'Power Burgers', '08019999999999',
 'Blvd. Morazán, Tegucigalpa, Honduras', '+504 2222-0000', 'facturacion@power.hn');

INSERT INTO tiendas (codigo, nombre, direccion, telefono, codigo_establecimiento) VALUES
('TGU-01', 'Power Morazán',      'Blvd. Morazán, Tegucigalpa',         '+504 2222-0001', '001'),
('SPS-01', 'Power Circunvalación','Av. Circunvalación, San Pedro Sula', '+504 2550-0001', '002'),
('CEI-01', 'Power La Ceiba',     'Av. San Isidro, La Ceiba',           '+504 2440-0001', '003');

INSERT INTO usuarios (tienda_id, nombre, email, password_hash, rol) VALUES
(NULL, 'Administrador', 'admin@power.hn', '$2y$10$av9mu7uADXyy211p7zApduHwsmQuENH8Ep1gdEvQ7uwhyr363B99i', 'ADMIN'),
(1,    'Cajero Morazán', 'caja1@power.hn', '$2y$10$av9mu7uADXyy211p7zApduHwsmQuENH8Ep1gdEvQ7uwhyr363B99i', 'CAJERO'),
(1,    'Ana (mesera)',   'mesero1@power.hn', '$2y$10$av9mu7uADXyy211p7zApduHwsmQuENH8Ep1gdEvQ7uwhyr363B99i', 'MESERO'),
(1,    'Luis (mesero)',  'mesero2@power.hn', '$2y$10$av9mu7uADXyy211p7zApduHwsmQuENH8Ep1gdEvQ7uwhyr363B99i', 'MESERO'),
(1,    'Cocina Morazán', 'cocina1@power.hn', '$2y$10$av9mu7uADXyy211p7zApduHwsmQuENH8Ep1gdEvQ7uwhyr363B99i', 'COCINA');

-- Estaciones de preparación: cada categoría manda sus comandas a una
INSERT INTO estaciones (id, nombre, orden) VALUES (1, 'Cocina', 1), (2, 'Bar', 2);

INSERT INTO categorias (id, nombre, orden, estacion_id) VALUES
(1, 'Combos', 1, 1), (2, 'Hamburguesas', 2, 1), (3, 'Pollo', 3, 1), (4, 'Acompañantes', 4, 1),
(5, 'Bebidas', 5, 2), (6, 'Postres', 6, 1), (7, 'Cervezas', 7, 2);

INSERT INTO productos (id, categoria_id, sku, nombre, precio_centavos, tipo_impuesto, vendible_solo) VALUES
(1,  2, 'HAM-CLA', 'Hamburguesa Clásica',     12000, 'G15', 1),
(2,  2, 'HAM-DOB', 'Hamburguesa Doble',       16500, 'G15', 1),
(3,  2, 'HAM-POL', 'Hamburguesa de Pollo',    13000, 'G15', 1),
(4,  3, 'POL-2PZ', 'Pollo Frito 2 piezas',    13500, 'G15', 1),
(5,  3, 'ALI-6',   'Alitas BBQ (6)',          15000, 'G15', 1),
(6,  4, 'PAP-MED', 'Papas Medianas',           4500, 'G15', 1),
(7,  4, 'PAP-GRA', 'Papas Grandes',            6000, 'G15', 1),
(8,  4, 'ARO-CEB', 'Aros de Cebolla',          5500, 'G15', 1),
(9,  5, 'REF-MED', 'Refresco Mediano',         3500, 'G15', 1),
(10, 5, 'REF-GRA', 'Refresco Grande',          4500, 'G15', 1),
(11, 5, 'AGU-500', 'Agua 500 ml',              2500, 'G15', 1),
(12, 6, 'HEL-VAI', 'Helado de Vainilla',       3000, 'G15', 1),
(13, 6, 'PIE-MAN', 'Pie de Manzana',           3500, 'G15', 1),
(14, 7, 'CER-NAC', 'Cerveza Nacional',         5500, 'G18', 1);

INSERT INTO combos (id, categoria_id, nombre, descripcion, precio_centavos, tipo_impuesto) VALUES
(1, 1, 'Combo Clásico', 'Hamburguesa + papas + refresco', 17500, 'G15'),
(2, 1, 'Combo Pollo',   '2 piezas + acompañante + refresco', 18500, 'G15'),
(3, 1, 'Combo Pareja',  '2 hamburguesas + 2 papas + 2 refrescos', 32900, 'G15');

INSERT INTO combo_grupos (id, combo_id, nombre, min_sel, max_sel, orden) VALUES
(1, 1, 'Hamburguesa',  1, 1, 1), (2, 1, 'Acompañante', 1, 1, 2), (3, 1, 'Bebida', 1, 1, 3),
(4, 2, 'Acompañante',  1, 1, 1), (5, 2, 'Bebida', 1, 1, 2),
(6, 3, 'Hamburguesas', 2, 2, 1), (7, 3, 'Acompañantes', 2, 2, 2), (8, 3, 'Bebidas', 2, 2, 3);

INSERT INTO combo_opciones (grupo_id, producto_id, recargo_centavos, por_defecto) VALUES
(1, 1, 0, 1), (1, 3, 0, 0), (1, 2, 3500, 0),
(2, 6, 0, 1), (2, 7, 1500, 0), (2, 8, 1500, 0),
(3, 9, 0, 1), (3, 10, 1000, 0), (3, 11, 0, 0),
(4, 6, 0, 1), (4, 8, 1000, 0),
(5, 9, 0, 1), (5, 10, 1000, 0),
(6, 1, 0, 1), (6, 3, 0, 0),
(7, 6, 0, 1), (7, 7, 1500, 0),
(8, 9, 0, 1), (8, 10, 1000, 0);

-- Promociones mix & match de ejemplo
INSERT INTO promociones (id, nombre, tipo, cantidad_n, cantidad_m, precio_centavos, porcentaje, prioridad, fecha_inicio, fecha_fin, dias_semana) VALUES
(1, '2 hamburguesas (cualquiera) por L 199', 'MIX_PRECIO', 2, NULL, 19900, NULL, 10, '2026-01-01', '2026-12-31', '1234567'),
(2, 'Martes 2x1 en postres',                 'NXM',        2, 1,    NULL,  NULL, 20, '2026-01-01', '2026-12-31', '2'),
(3, 'Combo + postre al 50%',                 'X_LLEVA_Y',  NULL, NULL, NULL, 50.00, 30, '2026-01-01', '2026-12-31', '1234567');

INSERT INTO promocion_items (promocion_id, grupo, producto_id, combo_id, categoria_id) VALUES
(1, 'A', NULL, NULL, 2),       -- cualquier producto de la categoría Hamburguesas
(2, 'A', NULL, NULL, 6),       -- cualquier postre
(3, 'A', NULL, NULL, 1),       -- cualquier combo dispara...
(3, 'B', NULL, NULL, 6);       -- ...un postre al 50%

-- Descuentos de ley. Los porcentajes y topes son PARÁMETROS: valídelos con su
-- asesor legal/contable y la normativa vigente de la SAR antes de producción.
INSERT INTO descuentos_legales (codigo, nombre, porcentaje, tope_centavos, edad_minima, acumulable_promos, exento_isv, base_legal) VALUES
('TERCERA_EDAD', 'Tercera edad (60+ años)', 25.00, 0, 60, 0, 0,
 'Ley Integral de Protección al Adulto Mayor y Jubilados (Decreto 199-2006) — VALIDAR'),
('CUARTA_EDAD',  'Cuarta edad (80+ años)',  30.00, 0, 80, 0, 0,
 'Reforma a la Ley del Adulto Mayor (cuarta edad) — VALIDAR porcentaje vigente'),
('DISCAPACIDAD', 'Persona con discapacidad', 25.00, 0, NULL, 0, 0,
 'Ley de Equidad y Desarrollo Integral para Personas con Discapacidad — VALIDAR');

-- CAI de ejemplo (ficticios). correlativo_actual = rango_inicial - 1
INSERT INTO cai (tienda_id, cai, punto_emision, tipo_documento, rango_inicial, rango_final, correlativo_actual, fecha_limite) VALUES
(1, '35D3B5-F1A9C2-4B8E9A-D3E6F0-A1B2C3-7F', '001', '01', 1, 5000, 0, '2027-06-30'),
(2, '8C1E44-0B7D2A-9F3C61-E2A4B8-55D0C7-1A', '001', '01', 1, 5000, 0, '2027-06-30'),
(3, 'F07A2B-6D9E13-C4B850-1A7F3E-90D2B6-3C', '001', '01', 1, 5000, 0, '2026-11-15');

-- Inventario inicial: 200 unidades por tienda, costo de ejemplo = 35% del precio sin ISV
INSERT INTO inventario (tienda_id, producto_id, existencia, minimo, costo_promedio)
SELECT t.id, p.id, 200, 30, ROUND(p.precio_centavos / 1.15 * 0.35) FROM tiendas t CROSS JOIN productos p;

INSERT INTO inventario_movimientos (tienda_id, producto_id, tipo, cantidad, referencia, costo_unitario, usuario_id, motivo)
SELECT i.tienda_id, i.producto_id, 'AJUSTE', i.existencia, 'INICIAL', i.costo_promedio, 1, 'Inventario inicial'
FROM inventario i;

INSERT INTO proveedores (rtn, nombre, telefono, email) VALUES
('08019000000011', 'Distribuidora de Carnes del Valle', '+504 2234-1100', 'ventas@carnesdelvalle.hn'),
('05019000000022', 'Embotelladora Nacional',           '+504 2556-2200', 'pedidos@embotelladora.hn'),
('08019000000033', 'Panificadora La Espiga',           '+504 2238-3300', 'pedidos@laespiga.hn');

-- Salón de Power Morazán: zonas y mesas (las otras tiendas trabajan solo mostrador)
INSERT INTO zonas (id, tienda_id, nombre, orden) VALUES (1, 1, 'Salón', 1), (2, 1, 'Terraza', 2);
INSERT INTO mesas (tienda_id, zona_id, nombre, capacidad, orden) VALUES
(1, 1, '1', 4, 1), (1, 1, '2', 4, 2), (1, 1, '3', 2, 3), (1, 1, '4', 2, 4),
(1, 1, '5', 6, 5), (1, 1, '6', 6, 6), (1, 1, '7', 4, 7), (1, 1, '8', 8, 8),
(1, 2, 'T1', 4, 1), (1, 2, 'T2', 4, 2), (1, 2, 'T3', 2, 3), (1, 2, 'T4', 2, 4);
