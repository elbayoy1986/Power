<?php
declare(strict_types=1);

spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($ruta)) {
            require $ruta;
        }
    }
});

date_default_timezone_set('America/Tegucigalpa');

$archivoConfig = dirname(__DIR__) . '/config/config.php';
$GLOBALS['config'] = is_file($archivoConfig)
    ? require $archivoConfig
    : require dirname(__DIR__) . '/config/config.example.php';
