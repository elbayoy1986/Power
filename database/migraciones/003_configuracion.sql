-- Migración 003: parámetros generales editables desde Configuración
-- y punto de emisión (caja) por turno, para sucursales con varios CAI.
USE power_pos;
SET NAMES utf8mb4;

ALTER TABLE turnos_caja ADD COLUMN punto_emision CHAR(3) NULL AFTER usuario_id;

CREATE TABLE IF NOT EXISTS parametros (
  clave        VARCHAR(50) PRIMARY KEY,
  valor        VARCHAR(255) NOT NULL,
  descripcion  VARCHAR(255) NOT NULL,
  actualizado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO parametros (clave, valor, descripcion) VALUES
('cai_alerta_facturas', '200', 'Avisar cuando a un CAI le queden menos de estos números'),
('cai_alerta_dias',     '30',  'Avisar cuando falten menos de estos días para la fecha límite del CAI'),
('fondo_caja_sugerido', '1000.00', 'Fondo inicial que se sugiere al abrir turno (L)'),
('mensaje_factura',     '¡Gracias por su compra!', 'Mensaje al pie de la factura');
