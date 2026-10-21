# Power POS 🍔

Plataforma web para **comidas rápidas en Honduras**: caja con armado de combos, promociones *mix & match*,
descuentos de ley (tercera edad, cuarta edad, discapacidad), **facturación con CAI (SAR)** y un **mini ERP** que centraliza todas las tiendas.
Hecha en **PHP + MySQL** y responsive (pensada primero para el teléfono).

📄 Propuesta completa y análisis de procesos: [`docs/PROPUESTA.md`](docs/PROPUESTA.md)
🏗️ Arquitectura, diagrama entidad-relación, flujos y seguridad: [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
📘 Manual interactivo (guías por rol, simulador de caja, validador de CAI, autoevaluación): [`docs/manual/manual.html`](docs/manual/manual.html)

![Arquitectura](docs/diagramas/1-arquitectura.png)

| Caja (móvil) | Armar combo | Orden + descuento de ley | Tablero ERP |
|---|---|---|---|
| ![](docs/capturas/movil-1-pos.png) | ![](docs/capturas/movil-2-combo.png) | ![](docs/capturas/movil-3-orden.png) | ![](docs/capturas/movil-4-erp.png) |

## Instalación

Requisitos: PHP 8.2+ con `pdo_mysql`, y MySQL 8 o MariaDB 10.6+.

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql          # datos de demostración
cp config/config.example.php config/config.php # ajuste usuario y clave de la BD
php -S localhost:8080 -t public                # o apunte Apache/Nginx a /public
```

Ingrese en http://localhost:8080 con **admin@power.hn / Power2026!** (cajero: `caja1@power.hn`, misma clave).
**Cambie estas claves antes de producción.**

También puede configurar la BD con las variables de entorno `DB_DSN`, `DB_USER` y `DB_PASS`.

## Pruebas

```bash
php tests/run.php   # motor de precios, promos, ley, ISV y montos en letras
```

## Módulos

| Pantalla | Rol | Qué hace |
|---|---|---|
| Caja | Todos | Catálogo, armado de combos, carrito, descuento de ley, RTN y facturación |
| Combos | Admin, Gerente | Crear combos con grupos (mín./máx.), recargos y opción por defecto |
| Promociones | Admin, Gerente | Mix & match, NxM, %, compra X lleva Y, por día, hora y tienda |
| Descuentos de ley | Admin | % y topes parametrizables, acumulable, exento ISV y reporte del mes |
| CAI | Admin | Registro de CAI por tienda y punto de emisión, consumo del rango y alertas |
| Tablero ERP | Admin, Gerente | KPIs consolidados, ranking de tiendas, top de artículos e inventario |

> ⚠️ Los porcentajes de los descuentos de ley y el tratamiento del ISV vienen como **valores por defecto parametrizables**.
> Valídelos con su asesor legal/contable y la normativa vigente de la SAR antes de usar el sistema en producción.
> Los CAI del seed son ficticios.
