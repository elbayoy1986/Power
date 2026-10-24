# Publicar Power POS en Hostinger

Guía para subir el sistema a un plan de Hostinger con PHP (Premium, Business o Cloud) usando solo hPanel:
Administrador de archivos y phpMyAdmin. No hace falta SSH.

## 0. Generar el paquete

```bash
bash deploy/empaquetar-hostinger.sh
```

Crea en `dist/`:

| Archivo | Para qué |
|---|---|
| `subir-a-public_html.zip` | El sitio. Se sube y se extrae dentro de `public_html` |
| `instalar.sql` | La base de datos (estructura + datos de demostración). Se importa en phpMyAdmin |
| `power-pos-hostinger/` | Lo mismo sin comprimir, más los SQL de actualización (`base_de_datos/`) |

Dentro del zip queda esta estructura. Solo `public/` se ve desde el navegador; el `.htaccess` de la raíz bloquea el resto:

```
public_html/
├── .htaccess        ← redirige todo a public/ y bloquea src/, views/ y config/
├── public/          ← index.php, api.php y assets/
├── src/
├── views/
└── config/
    └── config.php   ← aquí van los datos de su base de datos
```

## 1. Versión de PHP

hPanel → **Avanzado → Configuración de PHP** → elija **PHP 8.2** o superior.
La extensión `pdo_mysql` viene activada por defecto.

## 2. Crear la base de datos

hPanel → **Bases de datos → Administración** → cree una base nueva (por ejemplo `power`) con su usuario y clave.
Hostinger les agrega el prefijo de su cuenta: anote los nombres completos, por ejemplo
`u123456789_power` (base) y `u123456789_power` (usuario).

## 3. Importar las tablas

En la misma pantalla, junto a la base, haga clic en **Entrar a phpMyAdmin** → pestaña **Importar** →
seleccione `instalar.sql` → **Importar**. Deben aparecer las tablas (`empresa`, `tiendas`, `usuarios`, `productos`…).

> `instalar.sql` no trae `CREATE DATABASE` ni `USE` porque Hostinger no los permite: la base es la que eligió en phpMyAdmin.
> Si ya tenía instalada la versión 1, importe en su lugar `base_de_datos/actualizar_002_caja_compras_inventario.sql`.

## 4. Subir los archivos

hPanel → **Archivos → Administrador de archivos** → abra `public_html`:

1. Borre `default.php` (la página de bienvenida de Hostinger).
2. Suba `subir-a-public_html.zip`.
3. Clic derecho sobre el zip → **Extraer** → en el destino deje `.` (la misma carpeta `public_html`).
4. Borre el zip.

Revise que exista `public_html/.htaccess`. Si no lo ve, active "Mostrar archivos ocultos" en el Administrador de archivos.

## 5. Conectar la base de datos

En el Administrador de archivos abra `public_html/config/config.php` → **Editar** y reemplace los tres valores:

```php
'dsn'  => 'mysql:host=localhost;port=3306;dbname=u123456789_power;charset=utf8mb4',
'user' => 'u123456789_power',
'pass' => 'la clave que puso en el paso 2',
```

En Hostinger el host siempre es `localhost`.

## 6. HTTPS

hPanel → **Seguridad → SSL** → instale el certificado gratuito y active **Forzar HTTPS**.

## 7. Entrar

Abra `https://su-dominio.com` e ingrese con **admin@power.hn / Power2026!**
(cajero: `caja1@power.hn`, misma clave).

**Cambie esas claves de inmediato:** son públicas porque vienen en el repositorio.
Los CAI de demostración son ficticios: registre los suyos en **CAI** antes de facturar.

Compruebe también que la protección funciona: `https://su-dominio.com/config/config.php` debe responder **403 Prohibido**.

## Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| Error 500 o pantalla en blanco | PHP menor a 8.2 | Paso 1 |
| `could not find driver` | Falta `pdo_mysql` | Configuración de PHP → Extensiones → active `pdo_mysql` |
| `Access denied for user` | Usuario o clave incorrectos | Revise `config/config.php`; puede cambiar la clave en hPanel → Bases de datos |
| `Unknown database` | Nombre de base sin el prefijo `u123…_` | Use el nombre completo que muestra hPanel |
| `Table … doesn't exist` | No se importó el SQL | Paso 3 |
| Se ve el listado de carpetas o un 404 | Falta el `.htaccess` (en Mac queda oculto al comprimir a mano) | Use el zip generado por el script, o súbalo desde `deploy/hostinger/.htaccess` |

## Instalar en un subdominio o subcarpeta

Funciona igual: extraiga el zip en la carpeta del subdominio (por ejemplo `public_html/caja/`)
y abra `https://su-dominio.com/caja/`. Todas las rutas del sistema son relativas.
