<?php
declare(strict_types=1);

namespace App\Support;

final class Auth
{
    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
    }

    public static function login(string $email, string $password): bool
    {
        $st = Database::pdo()->prepare('SELECT * FROM usuarios WHERE email = ? AND activo = 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol'],
            'tienda_id' => $u['tienda_id'] !== null ? (int) $u['tienda_id'] : null,
        ];
        // Admin/gerente sin tienda fija: inicia en la primera tienda activa
        $_SESSION['tienda_id'] = $_SESSION['usuario']['tienda_id']
            ?? (int) Database::pdo()->query('SELECT MIN(id) FROM tiendas WHERE activa = 1')->fetchColumn();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function tiendaId(): int
    {
        return (int) ($_SESSION['tienda_id'] ?? 0);
    }

    public static function requerir(array $roles = ['ADMIN', 'GERENTE', 'CAJERO']): array
    {
        $u = self::usuario();
        if ($u === null) {
            header('Location: index.php?p=login');
            exit;
        }
        if (!in_array($u['rol'], $roles, true)) {
            http_response_code(403);
            exit('Sin permiso para esta sección.');
        }
        return $u;
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function verificarCsrf(?string $token): void
    {
        if (!$token || !hash_equals(self::csrf(), $token)) {
            http_response_code(419);
            exit('Token de seguridad inválido. Recargue la página.');
        }
    }
}
