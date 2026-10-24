# Power POS 🍔

Plataforma web para **comidas rápidas y restaurantes en Honduras**: caja con armado de combos, promociones *mix & match*,
descuentos de ley (tercera edad, cuarta edad, discapacidad), **facturación con CAI (SAR)**, **servicio en mesa con meseros y
comandas a cocina** (en pantalla o impresas) y un **mini ERP** que centraliza todas las tiendas.
Hecha en **PHP + MySQL** y responsive (pensada primero para el teléfono).

📄 Propuesta completa y análisis de procesos: [`docs/PROPUESTA.md`](docs/PROPUESTA.md)
🏗️ Arquitectura, diagrama entidad-relación, flujos y seguridad: [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
🍽️ Restaurante: mesas, meseros, comandas a cocina, cuenta dividida y propina: [`docs/RESTAURANTE.md`](docs/RESTAURANTE.md)
📘 Manual interactivo (guías por rol, simulador de caja, validador de CAI, autoevaluación): [`docs/manual/manual.html`](docs/manual/manual.html)

![Arquitectura](docs/diagramas/1-arquitectura.png)

| Caja (móvil) | Armar combo | Orden + descuento de ley | Tablero ERP |
|---|---|---|---|
| ![](docs/capturas/movil-1-pos.png) | ![](docs/capturas/movil-2-combo.png) | ![](docs/capturas/movil-3-orden.png) | ![](docs/capturas/movil-4-erp.png) |

| Abrir turno | Arqueo | Cierre Z | Compra | Inventario y kárdex |
|---|---|---|---|---|
| ![](docs/capturas/movil-5-abrir-turno.png) | ![](docs/capturas/movil-6-arqueo.png) | ![](docs/capturas/movil-7-cierre-z.png) | ![](docs/capturas/escritorio-compra.png) | ![](docs/capturas/escritorio-inventario-kardex.png) |

| Salón (mesero) | Cuenta de mesa | Pantalla de cocina | Cobro dividido con propina |
|---|---|---|---|
| ![](docs/capturas/movil-8-salon.png) | ![](docs/capturas/movil-9-cuenta-mesa.png) | ![](docs/capturas/escritorio-cocina.png) | ![](docs/capturas/escritorio-cobro-mesa.png) |

## Instalación

Requisitos: PHP 8.2+ con `pdo_mysql`, y MySQL 8 o MariaDB 10.6+.

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql          # datos de demostración
cp config/config.example.php config/config.php # ajuste usuario y clave de la BD
php -S localhost:8080 -t public                # o apunte Apache/Nginx a /public
```

Ingrese en http://localhost:8080 con **admin@power.hn / Power2026!**. Con la misma clave: cajero `caja1@power.hn`,
meseros `mesero1@power.hn` y `mesero2@power.hn`, y pantalla de cocina `cocina1@power.hn`.
Antes de vender, cada cajero **abre su turno** con el fondo inicial.
**Cambie estas claves antes de producción.**

También puede configurar la BD con las variables de entorno `DB_DSN`, `DB_USER` y `DB_PASS`.

## Pruebas

```bash
php tests/run.php          # motor de precios, promos, ley, ISV y montos en letras (sin BD)

# Integración (use una BD de PRUEBA recién cargada con schema.sql + seed.sql antes de cada una)
DB_DSN='mysql:host=127.0.0.1;dbname=power_pos;charset=utf8mb4' DB_USER=root DB_PASS= php tests/integracion.php  # caja, ventas, compras, inventario y CSV
DB_DSN='mysql:host=127.0.0.1;dbname=power_pos;charset=utf8mb4' DB_USER=root DB_PASS= php tests/restaurante.php  # mesas, comandas, cuenta dividida, propina y personal
```

### Publicar en Hostinger

```bash
bash deploy/empaquetar-hostinger.sh   # genera dist/subir-a-public_html.zip y dist/instalar.sql
```

Paso a paso en hPanel (base de datos, phpMyAdmin, Administrador de archivos y SSL): [`docs/HOSTINGER.md`](docs/HOSTINGER.md)

### Actualizar una instalación existente

```bash
mysql -u root -p < database/migraciones/002_caja_compras_inventario.sql   # si viene de la versión 1
mysql -u root -p < database/migraciones/003_mesas_meseros_cocina.sql      # mesas, meseros y cocina
```

## Módulos

| Pantalla | Rol | Qué hace |
|---|---|---|
| Caja | Admin, Gerente, Cajero | Catálogo, armado de combos, carrito, descuento de ley, RTN y facturación; el pedido sale a cocina |
| Mesas | Todos menos Cocina | Salón por zonas en vivo, cuenta por mesa con rondas y notas, envío a cocina, pre-cuenta, transferir mesa, cobro dividido con propina |
| Cocina | Cocina, Gerente, Admin | Pantalla por estación (Cocina, Bar…): marcar listo, despachar, recuperar, sonido e impresión automática de comandas |
| Combos | Admin, Gerente | Crear combos con grupos (mín./máx.), recargos y opción por defecto |
| Promociones | Admin, Gerente | Mix & match, NxM, %, compra X lleva Y, por día, hora y tienda |
| Descuentos de ley | Admin | % y topes parametrizables, acumulable, exento ISV y reporte del mes |
| CAI | Admin | Registro de CAI por tienda y punto de emisión, consumo del rango y alertas |
| Turno de caja | Admin, Gerente, Cajero | Apertura con fondo, retiros e ingresos, corte X, arqueo por denominación (incluye propinas en efectivo), cierre Z con propinas por mesero |
| Productos | Admin, Gerente | Alta y edición, carga masiva CSV o Excel |
| Compras | Admin, Gerente | Proveedores, compras en borrador o recibidas, entrada a inventario con costo |
| Inventario | Admin, Gerente | Existencias, mínimos, ajustes, mermas, traslados y kárdex |
| Personal | Admin, Gerente | Usuarios y roles (el gerente gestiona cajeros, meseros y cocina de su tienda) |
| Salón y cocina | Admin, Gerente | Zonas, mesas, estaciones de cocina y a qué estación va cada categoría |
| Tablero ERP | Admin, Gerente | KPIs consolidados, compras, diferencias de caja, ranking, top de artículos e inventario |

> ⚠️ Los porcentajes de los descuentos de ley y el tratamiento del ISV vienen como **valores por defecto parametrizables**.
> Valídelos con su asesor legal/contable y la normativa vigente de la SAR antes de usar el sistema en producción.
> Los CAI del seed son ficticios.
