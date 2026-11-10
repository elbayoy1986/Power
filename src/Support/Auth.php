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
        // Un usuario de tienda no entra si su sucursal fue desactivada
        $st = Database::pdo()->prepare(
            'SELECT u.* FROM usuarios u LEFT JOIN tiendas t ON t.id = u.tienda_id
             WHERE u.email = ? AND u.activo = 1 AND (u.tienda_id IS NULL OR t.activa = 1)'
        );
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

    /**
     * Vuelve a leer al usuario de la BD en cada petición: si lo desactivaron, le cambiaron el rol
     * o la sucursal, o su sucursal cerró, el cambio surte efecto de inmediato (no hasta que salga).
     * Devuelve null (y cierra la sesión) si ya no puede entrar.
     */
    public static function refrescar(): ?array
    {
        $u = self::usuario();
        if ($u === null) {
            return null;
        }
        $db = Database::pdo();
        $st = $db->prepare(
            'SELECT u.nombre, u.rol, u.tienda_id, u.activo, t.activa AS tienda_activa
             FROM usuarios u LEFT JOIN tiendas t ON t.id = u.tienda_id WHERE u.id = ?'
        );
        $st->execute([$u['id']]);
        $f = $st->fetch();
        if (!$f || !$f['activo'] || ($f['tienda_id'] !== null && !$f['tienda_activa'])) {
            $_SESSION = [];
            session_destroy();
            return null;
        }
        $u = ['id' => (int) $u['id'], 'nombre' => $f['nombre'], 'rol' => $f['rol'],
            'tienda_id' => $f['tienda_id'] !== null ? (int) $f['tienda_id'] : null];
        $_SESSION['usuario'] = $u;
        if ($u['tienda_id'] !== null) {
            $_SESSION['tienda_id'] = $u['tienda_id'];
        } else {
            // Oficina central: la sucursal seleccionada debe seguir activa
            $st = $db->prepare('SELECT 1 FROM tiendas WHERE id = ? AND activa = 1');
            $st->execute([self::tiendaId()]);
            if (!$st->fetchColumn()) {
                $_SESSION['tienda_id'] = (int) $db->query('SELECT MIN(id) FROM tiendas WHERE activa = 1')->fetchColumn();
            }
        }
        return $u;
    }

    /** Usuario vigente o redirección al login. Los permisos por página los decide Menu::puede(). */
    public static function requerir(): array
    {
        $u = self::refrescar();
        if ($u === null) {
            header('Location: index.php?p=login');
            exit;
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
