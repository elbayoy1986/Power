<?php
// Copie este archivo como config.php y ajuste los valores (config.php está en .gitignore).
return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=power_pos;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    // Alertas de CAI en el tablero
    'cai_alerta_facturas' => 200, // avisar cuando queden menos de N números
    'cai_alerta_dias' => 30,      // avisar cuando falten menos de N días para la fecha límite
];
