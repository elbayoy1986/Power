<?php
use App\Support\Auth;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) {
        redirigir(Auth::usuario()['rol'] === 'CAJERO' ? 'pos' : 'erp');
    }
    $error = 'Correo o contraseña incorrectos.';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingresar · Power POS</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="pantalla-login">
  <form method="post" class="tarjeta login">
    <h1 class="marca">Power<span>POS</span></h1>
    <p class="tenue">Comidas rápidas · Facturación CAI · Multi-tienda</p>
    <?php if ($error): ?><div class="aviso error"><?= e($error) ?></div><?php endif; ?>
    <label>Correo <input type="email" name="email" required autocomplete="username"></label>
    <label>Contraseña <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primario grande">Ingresar</button>
  </form>
</body>
</html>
