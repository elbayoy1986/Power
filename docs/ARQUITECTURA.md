# Power POS — Arquitectura y modelo de datos

> Los diagramas están en **Mermaid** (GitHub los dibuja automáticamente). También hay versiones en imagen en [`docs/diagramas/`](diagramas/).

---

## 1. Arquitectura general

Es una arquitectura **centralizada en línea**: todas las tiendas usan la misma aplicación web y la misma base de datos.
El consolidado del ERP es en tiempo real y no hay procesos de sincronización.

```mermaid
flowchart LR
    subgraph Tiendas["Tiendas (navegador: celular · tablet · PC)"]
        C1["Caja Tienda 001"]
        C2["Caja Tienda 002"]
        G["Gerente / Admin<br/>(tablero ERP)"]
    end

    subgraph Servidor["Servidor web · PHP 8.2"]
        direction TB
        R["public/index.php<br/>router + login + CSRF"]
        API["public/api.php<br/>JSON: catalogo · cotizar · facturar"]
        V["views/*<br/>pantallas PHP"]
        subgraph Dominio["src/ · lógica de negocio"]
            CS["CarritoService<br/>re-precio y validación de combos"]
            PE["PricingEngine<br/>promos + ley + ISV<br/>(puro, sin BD)"]
            FS["FacturacionService<br/>CAI + venta + inventario"]
            CR["CatalogoRepository"]
        end
    end

    DB[("MySQL 8 / MariaDB<br/>power_pos")]
    IMP["Impresora térmica 80 mm<br/>(impresión del navegador)"]
    BI["Power BI / Excel<br/>(vistas SQL)"]

    C1 & C2 -- HTTPS --> R & API
    G -- HTTPS --> R
    R --> V
    API --> CS --> CR
    CS --> PE
    API --> FS --> CS
    CR & FS & V --> DB
    V -. factura .-> IMP
    DB -. v_ventas_diarias / v_estado_cai .-> BI
```

### Capas y responsabilidades

| Capa | Archivos | Responsabilidad | Regla clave |
|---|---|---|---|
| Presentación | `public/assets/*.css,js`, `views/*.php` | UI responsive, armado del carrito | **Nunca** calcula montos que se cobran |
| Entrada | `public/index.php`, `public/api.php` | Ruteo, sesión, roles, CSRF, JSON | Valida el token en todo POST |
| Aplicación | `src/Ventas/*` | Casos de uso: cotizar y facturar | Transacción única por venta |
| Dominio | `src/Pricing/PricingEngine.php` | Promos, descuentos de ley, ISV | Puro: sin BD, 100 % testeable |
| Datos | `src/Catalogo/*`, `src/Support/Database.php` | Consultas PDO preparadas | Montos en **centavos (INT)** |

### Estructura del repositorio
```
Power/
├── public/                 ← ÚNICO directorio expuesto por Apache/Nginx
│   ├── index.php           router de pantallas
│   ├── api.php             API JSON del POS
│   └── assets/             app.css · pos.js
├── views/                  _layout · login · pos · combos · promociones · descuentos · cai · erp · factura
├── src/
│   ├── bootstrap.php       autoload PSR-4 (App\ → src/), zona horaria America/Tegucigalpa
│   ├── Pricing/            PricingEngine
│   ├── Ventas/             CarritoService · FacturacionService
│   ├── Catalogo/           CatalogoRepository
│   └── Support/            Database · Auth · Money · NumeroALetras
├── config/                 config.example.php (config.php fuera de git)
├── database/               schema.sql · seed.sql
├── tests/run.php           pruebas del motor
└── docs/                   PROPUESTA · ARQUITECTURA · capturas · diagramas
```

---

## 2. Modelo entidad-relación

### 2.1 Vista general por módulos

```mermaid
flowchart TB
    subgraph ORG["🏢 Organización"]
        empresa; tiendas; usuarios
    end
    subgraph CAT["📋 Catálogo"]
        categorias; productos; precios_tienda
    end
    subgraph CMB["🍔 Combos"]
        combos; combo_grupos; combo_opciones
    end
    subgraph PRM["🔥 Promociones"]
        promociones; promocion_items; promocion_tiendas
    end
    subgraph LEY["👴 Descuentos de ley"]
        descuentos_legales
    end
    subgraph FAC["🧾 Facturación SAR"]
        cai; ventas; venta_lineas; venta_linea_opciones
    end
    subgraph INV["📦 Inventario (ERP)"]
        inventario; inventario_movimientos
    end

    tiendas --> usuarios & cai & ventas & inventario & precios_tienda & promocion_tiendas
    categorias --> productos & combos
    productos --> combo_opciones & precios_tienda & inventario
    combos --> combo_grupos --> combo_opciones
    promociones --> promocion_items & promocion_tiendas
    promocion_items -.-> productos & combos & categorias
    cai --> ventas --> venta_lineas --> venta_linea_opciones
    descuentos_legales --> ventas
    usuarios --> ventas
```

### 2.2 Diagrama entidad-relación completo

```mermaid
erDiagram
    empresa {
        tinyint id PK "siempre 1"
        varchar razon_social
        varchar nombre_comercial
        char rtn "14 dígitos"
        varchar direccion
        varchar telefono
        varchar email
    }
    tiendas {
        int id PK
        varchar codigo UK "TGU-01"
        varchar nombre
        varchar direccion
        varchar telefono
        char codigo_establecimiento "SAR: 001"
        tinyint activa
    }
    usuarios {
        int id PK
        int tienda_id FK "NULL = todas"
        varchar nombre
        varchar email UK
        varchar password_hash "bcrypt"
        enum rol "ADMIN GERENTE CAJERO"
        tinyint activo
    }
    categorias {
        int id PK
        varchar nombre
        smallint orden
    }
    productos {
        int id PK
        int categoria_id FK
        varchar sku UK
        varchar nombre
        int precio_centavos "con ISV"
        enum tipo_impuesto "G15 G18 EXE"
        tinyint vendible_solo
        tinyint controla_stock
        tinyint activo
    }
    precios_tienda {
        int tienda_id PK,FK
        int producto_id PK,FK
        int precio_centavos
    }
    combos {
        int id PK
        int categoria_id FK
        varchar nombre
        varchar descripcion
        int precio_centavos "con ISV"
        enum tipo_impuesto
        tinyint activo
    }
    combo_grupos {
        int id PK
        int combo_id FK
        varchar nombre "Elige tu bebida"
        tinyint min_sel
        tinyint max_sel
        smallint orden
    }
    combo_opciones {
        int id PK
        int grupo_id FK
        int producto_id FK
        int recargo_centavos
        tinyint por_defecto
    }
    promociones {
        int id PK
        varchar nombre
        enum tipo "MIX_PRECIO NXM PORCENTAJE X_LLEVA_Y"
        tinyint cantidad_n
        tinyint cantidad_m
        int precio_centavos
        decimal porcentaje
        smallint prioridad
        date fecha_inicio
        date fecha_fin
        varchar dias_semana "1234567"
        time hora_inicio
        time hora_fin
        tinyint activa
    }
    promocion_items {
        int id PK
        int promocion_id FK
        enum grupo "A=participa B=premio"
        int producto_id FK "opcional"
        int combo_id FK "opcional"
        int categoria_id FK "opcional"
    }
    promocion_tiendas {
        int promocion_id PK,FK
        int tienda_id PK,FK
    }
    descuentos_legales {
        int id PK
        varchar codigo UK "TERCERA_EDAD"
        varchar nombre
        decimal porcentaje
        int tope_centavos "0 = sin tope"
        tinyint edad_minima
        tinyint acumulable_promos
        tinyint exento_isv
        tinyint requiere_documento
        varchar base_legal
        tinyint activo
    }
    cai {
        int id PK
        int tienda_id FK
        char cai UK "37 caracteres"
        char punto_emision "001"
        char tipo_documento "01 factura"
        int rango_inicial
        int rango_final
        int correlativo_actual
        date fecha_limite
        tinyint activo
    }
    ventas {
        bigint id PK
        int tienda_id FK
        int usuario_id FK
        int cai_id FK
        char cai "snapshot"
        char numero_factura UK "001-001-01-00000001"
        varchar rango_autorizado
        date fecha_limite_emision
        datetime fecha
        char cliente_rtn
        varchar cliente_nombre
        int descuento_legal_id FK
        varchar beneficiario_nombre
        varchar beneficiario_documento
        int subtotal
        int descuento_promos
        int descuento_legal
        int importe_exonerado
        int importe_exento
        int importe_gravado_15
        int importe_gravado_18
        int isv_15
        int isv_18
        int total
        varchar total_letras
        enum forma_pago
        enum estado "EMITIDA ANULADA"
    }
    venta_lineas {
        bigint id PK
        bigint venta_id FK
        enum tipo "PRODUCTO COMBO"
        int producto_id
        int combo_id
        varchar descripcion
        smallint cantidad
        int precio_unitario
        int descuento_promo
        int descuento_legal
        enum tipo_impuesto
        int total
    }
    venta_linea_opciones {
        bigint id PK
        bigint venta_linea_id FK
        int producto_id
        varchar descripcion
        int recargo
    }
    inventario {
        int tienda_id PK,FK
        int producto_id PK,FK
        decimal existencia
        decimal minimo
    }
    inventario_movimientos {
        bigint id PK
        int tienda_id FK
        int producto_id FK
        enum tipo "VENTA ENTRADA AJUSTE TRASLADO MERMA"
        decimal cantidad "negativo = salida"
        varchar referencia "No. factura"
        datetime fecha
    }

    tiendas ||--o{ usuarios : "emplea"
    tiendas ||--o{ cai : "autorizado para"
    tiendas ||--o{ ventas : "emite"
    tiendas ||--o{ precios_tienda : "precio local"
    tiendas ||--o{ inventario : "almacena"
    tiendas ||--o{ inventario_movimientos : "registra"
    tiendas ||--o{ promocion_tiendas : "participa"

    categorias ||--o{ productos : "agrupa"
    categorias ||--o{ combos : "agrupa"
    productos ||--o{ precios_tienda : "tiene"
    productos ||--o{ combo_opciones : "es opción de"
    productos ||--o{ inventario : "existencia"
    productos ||--o{ inventario_movimientos : "movimiento"

    combos ||--|{ combo_grupos : "se compone de"
    combo_grupos ||--|{ combo_opciones : "ofrece"

    promociones ||--|{ promocion_items : "aplica a"
    promociones ||--o{ promocion_tiendas : "limitada a"
    productos |o--o{ promocion_items : "producto"
    combos |o--o{ promocion_items : "combo"
    categorias |o--o{ promocion_items : "categoría"

    cai ||--o{ ventas : "numera"
    usuarios ||--o{ ventas : "factura"
    descuentos_legales |o--o{ ventas : "otorgado en"
    ventas ||--|{ venta_lineas : "detalle"
    venta_lineas ||--o{ venta_linea_opciones : "opciones del combo"
```

**Cómo leer las relaciones:** `||--o{` uno a muchos (opcional) · `||--|{` uno a muchos (al menos uno) · `|o--o{` cero o uno a muchos.

### 2.3 Decisiones del modelo

| Decisión | Por qué |
|---|---|
| Montos en **INT centavos** | Evita errores de redondeo; `L 120.50` = `12050` |
| Precios **con ISV** | Así se exhiben en el menú; la base se calcula `neto / (1 + tasa)` |
| `ventas` guarda un **snapshot** (CAI, rango, fecha límite, descripciones, precios) | La factura emitida es inmutable aunque luego cambien el catálogo o el CAI |
| `venta_lineas.producto_id/combo_id` **sin FK** | Se puede borrar o renombrar el catálogo sin romper el histórico fiscal |
| `promocion_items` apunta a producto **o** combo **o** categoría | Una promo puede ser "toda la categoría Postres" sin listar productos uno a uno |
| `promocion_tiendas` vacía = todas | Promos globales sin tener que mantener una lista |
| `precios_tienda` opcional | Precio central por defecto con excepciones por plaza (aeropuerto, mall) |
| `cai.correlativo_actual` + `FOR UPDATE` | Correlativo atómico: dos cajas nunca emiten el mismo número |
| `inventario_movimientos` (kárdex) | Auditoría: cada salida queda ligada al número de factura |

### 2.4 Índices y restricciones relevantes

| Tabla | Índice / restricción | Uso |
|---|---|---|
| `ventas` | `UNIQUE numero_factura` | Imposible duplicar factura |
| `ventas` | `idx_ventas_tienda_fecha (tienda_id, fecha)` | Tablero y reportes por período |
| `cai` | `idx_cai_tienda (tienda_id, activo, fecha_limite)` | Buscar el CAI vigente al facturar |
| `cai` | `CHECK rango_final >= rango_inicial`, `UNIQUE cai` | Integridad fiscal |
| `inventario_movimientos` | `idx_mov_tienda_fecha` | Kárdex por tienda |
| `empresa` | `CHECK id = 1` | Una sola empresa emisora |

### 2.5 Vistas para el ERP y BI

| Vista | Columnas | Uso |
|---|---|---|
| `v_ventas_diarias` | tienda, día, facturas, total, ticket_promedio, descuentos, isv | Tablero, Power BI |
| `v_estado_cai` | tienda, cai, punto, fecha_limite, disponibles, dias_restantes | Alertas de renovación del CAI |

---

## 3. Flujos principales

### 3.1 Cotizar y facturar (secuencia)

```mermaid
sequenceDiagram
    autonumber
    actor Cajero
    participant UI as pos.js (navegador)
    participant API as api.php
    participant CS as CarritoService
    participant PE as PricingEngine
    participant FS as FacturacionService
    participant DB as MySQL

    Cajero->>UI: toca combo / producto
    UI->>API: POST cotizar {items: IDs + cantidades, descuento_legal_id}
    API->>CS: cotizar()
    CS->>DB: precios de tienda, combos, promos vigentes, regla de ley
    CS->>CS: valida grupos del combo (mín./máx.)
    CS->>PE: calcular(lineas, promos, ley)
    PE-->>UI: líneas + resumen fiscal (en centavos)
    Cajero->>UI: Facturar (DNI beneficiario, RTN, pago)
    UI->>API: POST facturar + X-CSRF-Token
    API->>FS: emitir()
    FS->>CS: re-cotiza en el servidor (nunca confía en el cliente)
    FS->>DB: BEGIN
    FS->>DB: SELECT cai ... FOR UPDATE (vigente y con rango)
    alt CAI vencido o agotado
        FS-->>UI: 422 "No hay CAI vigente"
    else OK
        FS->>DB: UPDATE cai.correlativo_actual + 1
        FS->>DB: INSERT ventas, venta_lineas, opciones
        FS->>DB: UPDATE inventario / INSERT kárdex
        FS->>DB: COMMIT
        FS-->>UI: venta_id → abre factura para imprimir
    end
```

### 3.2 Orden de cálculo del motor de precios

```mermaid
flowchart TD
    A[Líneas del carrito] --> B[Expandir a unidades<br/>precio en centavos]
    B --> C{Promociones vigentes<br/>ordenadas por prioridad}
    C -->|MIX_PRECIO| D[N unidades de mayor precio → precio fijo]
    C -->|NXM| E[Por cada N, las N−M más baratas gratis]
    C -->|PORCENTAJE| F[% por unidad]
    C -->|X_LLEVA_Y| G[Cada X descuenta el Y más barato]
    D & E & F & G --> H[Prorratear ahorro entre las unidades del grupo<br/>cada unidad en UNA sola promo]
    H --> I{¿Descuento de ley?}
    I -->|No| L
    I -->|Sí| J[Solo líneas del beneficiario · aplica tope]
    J --> K{¿Acumulable?}
    K -->|Sí| K1[% sobre el neto después de la promo]
    K -->|No| K2[Mayor beneficio entre promo y ley]
    K1 & K2 --> X{¿Exento ISV?}
    X -->|Sí| X1[Neto / 1+tasa → importe exento]
    X -->|No| L
    X1 --> L[Desglose SAR: gravado 15 · gravado 18 · exento · ISV]
    L --> M[Total + total en letras]
```

### 3.3 Ciclo de vida del CAI

```mermaid
stateDiagram-v2
    [*] --> Registrado: Admin registra CAI (SAR)
    Registrado --> Vigente: activo=1 y fecha ≥ hoy
    Vigente --> Alerta: < 200 números o < 30 días
    Alerta --> Vigente: se registra CAI nuevo
    Vigente --> Agotado: correlativo = rango_final
    Alerta --> Agotado
    Vigente --> Vencido: hoy > fecha_limite
    Alerta --> Vencido
    Agotado --> [*]: caja bloqueada hasta CAI nuevo
    Vencido --> [*]: caja bloqueada hasta CAI nuevo
    Vigente --> Inactivo: Admin desactiva
```

---

## 4. Seguridad y roles

| Rol | Caja | Combos / Promos | Descuentos de ley / CAI | Tablero ERP |
|---|---|---|---|---|
| CAJERO | ✅ su tienda | ❌ | ❌ | ❌ |
| GERENTE | ✅ | ✅ | ❌ | ✅ (su tienda si tiene una asignada) |
| ADMIN | ✅ todas | ✅ | ✅ | ✅ todas |

Controles: contraseñas con bcrypt · `session_regenerate_id` al iniciar sesión · cookies HttpOnly/SameSite · token CSRF en formularios y API ·
PDO con `EMULATE_PREPARES=false` · salida escapada con `htmlspecialchars` · solo `public/` expuesto · precios calculados en el servidor.

## 5. Despliegue recomendado

```mermaid
flowchart LR
    U[Cajas y gerentes] -->|HTTPS| CF[Cloudflare / TLS]
    CF --> W[VPS o hosting cPanel<br/>Nginx/Apache + PHP-FPM 8.2]
    W --> M[(MySQL 8<br/>respaldo diario + binlog)]
    M -.réplica de lectura.-> R[(Réplica para BI)]
    R -.-> PBI[Power BI]
```

- **Respaldo**: `mysqldump` diario y retención de 30 días; el binlog permite recuperar hasta un punto en el tiempo.
- **Escala**: un VPS de 2 vCPU y 4 GB soporta decenas de cajas concurrentes; la réplica separa la analítica de la operación.
