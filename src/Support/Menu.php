<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Navegación de la plataforma. Hay DOS menús y se elige por dónde trabaja la persona:
 *
 *  - Menú de TIENDA: usuarios con sucursal asignada (cajeros y gerentes de tienda).
 *    Pocas opciones, pensadas para el celular o la tablet de la sucursal.
 *  - Menú ADMINISTRATIVO: usuarios de oficina central (sin sucursal fija): administradores
 *    y gerentes centrales. Agrupa todo en Ventas, Catálogo, Abastecimiento y Configuración.
 *
 * Este archivo es la única fuente de verdad de rutas y permisos: el router (public/index.php)
 * y la barra de navegación (views/_layout.php) leen de aquí.
 */
final class Menu
{
    public const TODOS = ['ADMIN', 'GERENTE', 'CAJERO'];
    public const GESTION = ['ADMIN', 'GERENTE'];
    public const SOLO_ADMIN = ['ADMIN'];

    /**
     * Rutas: página => [vista, roles, solo oficina central].
     * "central" = true exige un usuario SIN sucursal fija (el catálogo es de toda la cadena).
     */
    public const RUTAS = [
        'pos'           => ['pos', self::TODOS, false],
        'factura'       => ['factura', self::TODOS, false],
        'facturas'      => ['facturas', self::TODOS, false],
        'caja'          => ['caja', self::TODOS, false],
        'cierre'        => ['cierre', self::TODOS, false],
        'cuenta'        => ['cuenta', self::TODOS, false],
        'inventario'    => ['inventario', self::GESTION, false],
        'compras'       => ['compras', self::GESTION, false],
        'erp'           => ['erp', self::GESTION, false],
        'productos'     => ['productos', self::GESTION, true],
        'combos'        => ['combos', self::GESTION, true],
        'promociones'   => ['promociones', self::GESTION, true],
        'configuracion' => ['configuracion', self::SOLO_ADMIN, true],
    ];

    /** Secciones de Configuración: clave => [título, descripción]. */
    public const CONFIGURACION = [
        'empresa'     => ['Empresa', 'Razón social, RTN y datos que salen en la factura'],
        'sucursales'  => ['Sucursales', 'Tiendas, código de establecimiento SAR y estado'],
        'cai'         => ['CAI', 'Rangos autorizados por sucursal y punto de emisión'],
        'usuarios'    => ['Usuarios y roles', 'Quién entra, con qué rol y en qué sucursal'],
        'descuentos'  => ['Descuentos de ley', 'Tercera edad, cuarta edad y discapacidad'],
        'categorias'  => ['Categorías', 'Grupos del menú y su orden en la caja'],
        'proveedores' => ['Proveedores', 'A quién se le compra'],
        'parametros'  => ['Parámetros', 'Alertas de CAI, fondo de caja y pie de factura'],
    ];

    /** true = la persona trabaja en una sucursal y ve el menú de tienda. */
    public static function esDeTienda(array $usuario): bool
    {
        return $usuario['tienda_id'] !== null;
    }

    public static function inicio(array $usuario): string
    {
        return self::esDeTienda($usuario) ? 'pos' : 'erp';
    }

    public static function puede(array $usuario, string $pagina): bool
    {
        $r = self::RUTAS[$pagina] ?? null;
        return $r !== null && in_array($usuario['rol'], $r[1], true) && (!$r[2] || !self::esDeTienda($usuario));
    }

    /**
     * Menú visible para el usuario. Cada elemento es un enlace ['texto', 'p']
     * o un grupo ['texto', 'hijos' => [...]]. Solo incluye lo que el usuario puede abrir.
     */
    public static function para(array $usuario): array
    {
        $menu = self::esDeTienda($usuario) ? self::tienda() : self::administrativo();
        $visible = [];
        foreach ($menu as $item) {
            if (isset($item['hijos'])) {
                $item['hijos'] = array_values(array_filter($item['hijos'], fn ($h) => self::puede($usuario, self::pagina($h['p']))));
                if ($item['hijos']) {
                    $visible[] = $item;
                }
            } elseif (self::puede($usuario, self::pagina($item['p']))) {
                $visible[] = $item;
            }
        }
        return $visible;
    }

    /** "configuracion&s=cai" → "configuracion" */
    public static function pagina(string $p): string
    {
        return explode('&', $p, 2)[0];
    }

    private static function tienda(): array
    {
        return [
            ['texto' => 'Vender', 'p' => 'pos'],
            ['texto' => 'Turno de caja', 'p' => 'caja'],
            ['texto' => 'Facturas', 'p' => 'facturas'],
            ['texto' => 'Mi tienda', 'hijos' => [
                ['texto' => 'Inventario', 'p' => 'inventario', 'nota' => 'Existencias, mermas y traslados'],
                ['texto' => 'Compras', 'p' => 'compras', 'nota' => 'Recibir mercadería'],
                ['texto' => 'Reporte de la tienda', 'p' => 'erp', 'nota' => 'Ventas, ticket y diferencias de caja'],
            ]],
        ];
    }

    private static function administrativo(): array
    {
        $config = [['texto' => 'Resumen', 'p' => 'configuracion', 'nota' => 'Estado de toda la configuración']];
        foreach (self::CONFIGURACION as $clave => [$titulo, $nota]) {
            $config[] = ['texto' => $titulo, 'p' => 'configuracion&s=' . $clave, 'nota' => $nota];
        }
        return [
            ['texto' => 'Tablero', 'p' => 'erp'],
            ['texto' => 'Ventas', 'hijos' => [
                ['texto' => 'Facturas', 'p' => 'facturas', 'nota' => 'Consulta y reimpresión, todas las sucursales'],
                ['texto' => 'Turnos de caja', 'p' => 'caja', 'nota' => 'Aperturas, cierres y arqueos'],
                ['texto' => 'Vender', 'p' => 'pos', 'nota' => 'Caja en la sucursal seleccionada'],
            ]],
            ['texto' => 'Catálogo', 'hijos' => [
                ['texto' => 'Productos', 'p' => 'productos', 'nota' => 'Alta, precios y carga masiva'],
                ['texto' => 'Combos', 'p' => 'combos', 'nota' => 'Grupos de selección y recargos'],
                ['texto' => 'Promociones', 'p' => 'promociones', 'nota' => 'Mix & match por día, hora y sucursal'],
            ]],
            ['texto' => 'Abastecimiento', 'hijos' => [
                ['texto' => 'Compras', 'p' => 'compras', 'nota' => 'Facturas de proveedor'],
                ['texto' => 'Inventario', 'p' => 'inventario', 'nota' => 'Existencias, ajustes, traslados y kárdex'],
            ]],
            ['texto' => 'Configuración', 'hijos' => $config],
        ];
    }
}
