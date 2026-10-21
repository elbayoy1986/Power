# Power POS — Propuesta de producto

**Plataforma de comidas rápidas con facturación CAI y mini ERP multi-tienda (Honduras)**
Stack: PHP 8.2+ · MySQL 8 / MariaDB 10.6+ · HTML/CSS/JS sin frameworks · responsive (móvil primero)

---

## 1. Resumen ejecutivo

Las cadenas pequeñas y medianas de comida rápida en Honduras suelen operar con un POS por tienda,
hojas de Excel para consolidar ventas y un control manual de los rangos de CAI. Eso genera
tres riesgos: **multas de la SAR** (facturas fuera de rango o con el CAI vencido), **errores en los descuentos de ley**
(tercera y cuarta edad mal calculados o sin respaldo) y **falta de visibilidad** del negocio completo.

Power POS resuelve los tres con una sola plataforma web:

| Problema | Solución |
|---|---|
| Armar combos y personalizarlos es lento | Pantalla de **armado de combos** con grupos de selección, recargos y opción por defecto |
| Promociones difíciles de configurar | Motor **mix & match**: N por precio fijo, NxM (2x1), % y compra X lleva Y, por día, hora y tienda |
| Descuentos de ley calculados a mano | **Tercera edad, cuarta edad y discapacidad** parametrizables, con tope, regla de no acumulación y registro del beneficiario |
| Riesgo fiscal con el CAI | Correlativo **atómico** por establecimiento y punto de emisión, bloqueo por vencimiento o rango agotado, y alertas |
| Ventas dispersas | **Mini ERP** central: ventas, ticket, ranking de tiendas, top de productos, ISV, descuentos e inventario |
| Personal en piso con celular/tablet | Interfaz **responsive** pensada para el teléfono |

---

## 2. Alcance (MVP entregado en este repositorio)

### Incluido
1. **Caja (POS)**: catálogo por categorías, botones grandes, carrito, cotización en vivo desde el servidor y facturación.
2. **Armador de combos**: grupos con mínimo y máximo, recargos por opción (por ejemplo, "agrandar papas +L 15") y cantidades múltiples (Combo Pareja: 2 hamburguesas).
3. **Administración de combos**: alta de combos con grupos dinámicos; activar o desactivar.
4. **Promociones mix & match**: 4 tipos, prioridad, vigencia por fecha, días de la semana, horario y tiendas.
5. **Descuentos de ley (Honduras)**: tercera edad, cuarta edad y discapacidad, con porcentaje, tope, acumulable con promos, exento de ISV y requisito de documento.
6. **Facturación CAI**: registro de CAI, número `EEE-PPP-TT-NNNNNNNN`, rango autorizado, fecha límite, desglose fiscal (exento, exonerado, gravado 15 %, gravado 18 %, ISV), total en letras y formato térmico de 80 mm.
7. **Mini ERP**: tablero consolidado con filtro de fechas, alertas de CAI, inventario bajo mínimo y descarga automática del inventario al vender.
8. **Seguridad**: login con bcrypt, roles (ADMIN, GERENTE, CAJERO), CSRF, consultas preparadas y precios calculados solo en el servidor.

### Fuera del MVP (roadmap)
Modo offline, impresión ESC/POS directa, KDS (pantalla de cocina), recetas e insumos, compras y proveedores,
notas de crédito, arqueo de caja, delivery y apps de terceros, y facturación electrónica cuando la SAR la exija.

---

## 3. Análisis de procesos

### 3.1 Venta en caja
```
Cliente pide → Cajero toca producto/combo → (combo) elige opciones → carrito
 → servidor cotiza (promos + ley + ISV) → ¿descuento de ley? → captura nombre + DNI
 → ¿RTN? → forma de pago → FACTURAR → correlativo CAI → imprime → descarga inventario
```
**Decisiones de diseño**
- El navegador **solo envía IDs y cantidades**. Los precios, recargos y descuentos se recalculan en el servidor en cada cotización y otra vez al facturar. Así no se puede manipular el precio desde el navegador.
- Todo se guarda en **centavos (enteros)**, así que no hay errores de redondeo de punto flotante.
- Los precios del catálogo **incluyen ISV** (práctica local). La base imponible se obtiene como `neto / (1 + tasa)`.

### 3.2 Armado de combos
- Un combo tiene un **precio base** y **grupos** ("Hamburguesa", "Acompañante", "Bebida") con `min`/`max`.
- Cada opción puede tener un **recargo**. El precio de la línea es `precio_combo + Σ recargos`.
- El servidor valida que cada grupo cumpla su mínimo y máximo y que ninguna opción sea ajena al combo.
- Al vender, se descuenta del inventario **cada componente** elegido.

### 3.3 Promociones mix & match
| Tipo | Ejemplo | Regla |
|---|---|---|
| `MIX_PRECIO` | 2 hamburguesas (cualquiera) por L 199 | Agrupa las unidades elegibles de mayor precio (mayor beneficio para el cliente) |
| `NXM` | Martes 2x1 en postres | Por cada N, las (N−M) más baratas salen gratis |
| `PORCENTAJE` | 20 % en bebidas | Porcentaje por unidad elegible |
| `X_LLEVA_Y` | Combo + postre al 50 % | Cada disparador X descuenta el Y más barato |

- Los artículos se definen por **producto, combo o categoría completa**.
- **Cada unidad participa en una sola promoción**; se evalúan por `prioridad`.
- El ahorro se **prorratea** entre todas las unidades del grupo (método del residuo mayor), así el desglose por línea cuadra al centavo.

### 3.4 Descuentos de ley (Honduras)
| Beneficio | Valor por defecto | Base |
|---|---|---|
| Tercera edad (60+) | 25 % | Ley Integral de Protección al Adulto Mayor y Jubilados |
| Cuarta edad (80+) | 30 % | Reformas de cuarta edad |
| Discapacidad | 25 % | Ley de Equidad y Desarrollo Integral para Personas con Discapacidad |

> ⚠️ **Validación legal obligatoria.** Los porcentajes, topes y el tratamiento del ISV son **parámetros** que el administrador
> puede cambiar sin programar. Antes de salir a producción deben validarse con un asesor legal/contable y la normativa vigente de la SAR.

Reglas implementadas:
- Aplica **solo al consumo del beneficiario**: el cajero desmarca las líneas de los acompañantes.
- **Tope de consumo** opcional (por ejemplo, el descuento solo sobre los primeros L 200).
- **No acumulable** (por defecto): el cliente recibe **el mayor** beneficio entre la promo y la ley, nunca la suma.
- **Exento de ISV** opcional: el consumo del beneficiario se factura como importe exento.
- Guarda **nombre y DNI/carné** del beneficiario en la factura, como respaldo ante auditorías.

### 3.5 Facturación CAI (SAR)
- Cada tienda es un **establecimiento** (`001`, `002`…) y cada CAI pertenece a un **punto de emisión**.
- El número se toma con `SELECT … FOR UPDATE` dentro de la transacción de la venta, así que **dos cajas nunca duplican un número** y, si la venta falla, el número no se consume.
- La facturación se **bloquea** si el CAI venció o se agotó el rango. Se generan alertas cuando quedan menos de 200 números o menos de 30 días (ambos valores son configurables).
- La factura incluye: RTN del emisor, CAI, número, fecha, cliente y RTN (opcional), detalle, descuentos y rebajas,
  importe exonerado, importe exento, gravado 15 %, gravado 18 %, ISV 15 %, ISV 18 %, total, **total en letras**, rango autorizado y fecha límite de emisión.

### 3.6 Mini ERP centralizado
- **Una sola base de datos central**. Las tiendas operan en línea contra ella, así el consolidado es en tiempo real y no hay procesos de sincronización.
- Catálogo central con **precio por tienda** opcional (`precios_tienda`).
- Promociones globales o por tienda.
- Tablero: ventas, facturas, ticket promedio, descuentos de promo y de ley, ISV, ventas por día y por hora, ranking de tiendas, top de artículos e inventario bajo mínimo.
- Un gerente con tienda asignada solo ve su tienda; el administrador ve todas y puede cambiar de tienda.

---

## 4. Arquitectura

> Detalle completo con diagramas (arquitectura, entidad-relación, secuencia de facturación, motor de precios, ciclo del CAI y despliegue): **[ARQUITECTURA.md](ARQUITECTURA.md)**

```
public/           ← raíz web (único directorio expuesto)
  index.php       ← router de pantallas + login + CSRF
  api.php         ← JSON: catalogo, cotizar, facturar
  assets/         ← app.css (mobile-first), pos.js
views/            ← pantallas PHP (pos, combos, promociones, descuentos, cai, erp, factura)
src/
  Pricing/PricingEngine.php      ← motor puro (sin BD): promos + ley + ISV
  Ventas/CarritoService.php      ← re-precio en el servidor y validación de combos
  Ventas/FacturacionService.php  ← transacción: correlativo CAI + venta + inventario
  Catalogo/CatalogoRepository.php
  Support/ (Database, Auth, Money, NumeroALetras)
database/schema.sql, seed.sql
tests/run.php     ← 36 pruebas del motor y de los montos en letras
```

**Por qué sin framework:** se despliega en cualquier hosting compartido con PHP y MySQL (cPanel), lo que baja el costo de entrada.
El motor de precios está aislado y probado, así que se puede migrar a Laravel o Symfony sin reescribir la lógica de negocio.

### Modelo de datos (principal)
`empresa` · `tiendas` · `usuarios` · `categorias` · `productos` · `precios_tienda` · `combos` → `combo_grupos` → `combo_opciones` ·
`promociones` → `promocion_items` / `promocion_tiendas` · `descuentos_legales` · `cai` · `ventas` → `venta_lineas` → `venta_linea_opciones` ·
`inventario` · `inventario_movimientos` · vistas `v_ventas_diarias`, `v_estado_cai`.

---

## 5. Plan de proyecto

| Fase | Semanas | Entregables |
|---|---|---|
| 0. Descubrimiento | 1 | Validación legal de los descuentos, menú real, CAI reales, número de tiendas y cajas |
| 1. MVP (este repo) | 2–4 | POS, combos, mix & match, ley, CAI, tablero |
| 2. Piloto 1 tienda | 2 | Capacitación, impresora térmica, ajustes de UX y monitoreo |
| 3. Operación | 3 | Arqueo de caja, anulaciones/notas de crédito, recetas e insumos, compras |
| 4. Escala | 3 | Rollout a todas las tiendas, KDS, delivery, respaldos y alta disponibilidad |
| 5. Analítica | continuo | Exportación a Power BI, pronóstico de demanda, rentabilidad por combo |

### Equipo sugerido
PM/Analista (½) · Dev PHP senior (1) · Dev front (½) · QA (½) · Contador/asesor fiscal (consultoría).

### Riesgos y mitigación
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Normativa de descuentos o ISV distinta a la parametrizada | Alto | Parámetros editables y validación legal en la fase 0 |
| Caída de internet en tienda | Alto | Fase 4: modo offline con un rango CAI dedicado por caja y sincronización |
| CAI vencido o agotado | Alto | Bloqueo, alertas a 30 días o 200 números y tablero |
| Fraude en descuentos de ley | Medio | DNI obligatorio, reporte mensual por beneficiario y cajero |
| Concurrencia entre cajas | Medio | Bloqueo de fila del CAI dentro de la transacción |

### KPIs del producto
Tiempo promedio por orden (< 45 s) · % de facturas con error (0) · Ticket promedio · Uso de combos y promociones ·
Descuento de ley sobre ventas · Días de anticipación al renovar el CAI.

---

## 6. Ideas para contenido y redes (analítica)
El tablero y las vistas SQL permiten publicar piezas como "Hora pico por tienda", "Combo más vendido del mes" o
"Impacto de la promo 2x1 en el ticket promedio". Las vistas `v_ventas_diarias` y `v_estado_cai` se pueden conectar directo a Power BI.
