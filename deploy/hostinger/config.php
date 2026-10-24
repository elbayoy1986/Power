<?php
// Configuración para Hostinger. Copie aquí los datos de hPanel > Bases de datos.
// En Hostinger el host es "localhost" y el nombre de la base y el usuario llevan el prefijo de su cuenta (u123456789_...).
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=u000000000_power;charset=utf8mb4',
        'user' => 'u000000000_power',
        'pass' => 'ESCRIBA_AQUI_LA_CLAVE',
    ],
    // Alertas de CAI en el tablero
    'cai_alerta_facturas' => 200, // avisar cuando queden menos de N números
    'cai_alerta_dias' => 30,      // avisar cuando falten menos de N días para la fecha límite
    // Restaurante
    'propina_sugerida' => 10,     // % que se sugiere en la pre-cuenta y al cobrar (la propina es voluntaria)
    'comandas_mostrador' => true, // las ventas de la caja rápida también salen a la pantalla/impresora de cocina
];
