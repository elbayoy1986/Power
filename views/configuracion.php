<?php
// Configuración: una sola entrada con secciones (?s=empresa|sucursales|cai|usuarios|...).
use App\Support\Menu;

require __DIR__ . '/_layout.php';

/** Encabezado común de las secciones: migas, pestañas y título. */
function config_inicio(string $seccion, PDO $db): void
{
    [$titulo] = Menu::CONFIGURACION[$seccion];
    layout_inicio($titulo . ' · Configuración', 'configuracion&s=' . $seccion, $db);
    echo '<nav class="migas" aria-label="Ruta"><a href="index.php?p=configuracion">Configuración</a> › ' . e($titulo) . '</nav>';
    echo '<div class="tabs" role="navigation" aria-label="Secciones de configuración">';
    foreach (Menu::CONFIGURACION as $clave => [$t]) {
        echo '<a href="index.php?p=configuracion&s=' . $clave . '" class="' . ($clave === $seccion ? 'activo' : '') . '">' . e($t) . '</a>';
    }
    echo '</div>';
}

/** Vuelve a la sección con un mensaje (verde) o un error (rojo). */
function config_volver(string $seccion, string $msg, bool $error = false, string $extra = ''): never
{
    redirigir('configuracion&s=' . $seccion . $extra, $msg, $error);
}

$s = (string) ($_GET['s'] ?? '');
// Solo se incluyen archivos de la lista conocida (nunca una ruta armada con lo que envía el navegador)
require __DIR__ . '/config/' . (isset(Menu::CONFIGURACION[$s]) ? $s : 'inicio') . '.php';
