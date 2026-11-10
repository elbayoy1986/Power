<?php
declare(strict_types=1);

namespace App\Configuracion;

use DomainException;
use PDO;

/**
 * Usuarios y accesos.
 *  - CAJERO siempre pertenece a una sucursal.
 *  - GERENTE con sucursal = gerente de tienda; sin sucursal = gerente de oficina central.
 *  - ADMIN no tiene sucursal fija (ve todas).
 * Protecciones: nadie se desactiva ni se quita el rol ADMIN a sí mismo, siempre queda un ADMIN activo
 * y no se desactiva a quien tiene un turno de caja abierto.
 */
final class UsuariosService
{
    public const ROLES = ['CAJERO' => 'Cajero', 'GERENTE' => 'Gerente', 'ADMIN' => 'Administrador'];
    public const LARGO_MINIMO_CLAVE = 8;

    public function __construct(private PDO $db)
    {
    }

    public function listar(): array
    {
        return $this->db->query(
            "SELECT u.id, u.nombre, u.email, u.rol, u.tienda_id, u.activo, t.nombre AS tienda,
                (SELECT COUNT(*) FROM turnos_caja tc WHERE tc.usuario_id = u.id AND tc.estado = 'ABIERTO') AS turno_abierto
             FROM usuarios u LEFT JOIN tiendas t ON t.id = u.tienda_id
             ORDER BY u.activo DESC, FIELD(u.rol, 'ADMIN', 'GERENTE', 'CAJERO'), u.nombre"
        )->fetchAll();
    }

    public function guardar(array $d, int $quienEdita): int
    {
        $id = (int) ($d['id'] ?? 0);
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        $rol = (string) ($d['rol'] ?? '');
        $tienda = (int) ($d['tienda_id'] ?? 0) ?: null;
        $activo = !empty($d['activo']) || !$id ? 1 : 0;

        if ($nombre === '') {
            throw new DomainException('Escriba el nombre completo.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Correo inválido: es el usuario para ingresar.');
        }
        if (!isset(self::ROLES[$rol])) {
            throw new DomainException('Seleccione un rol.');
        }
        if ($rol === 'CAJERO' && !$tienda) {
            throw new DomainException('Un cajero debe pertenecer a una sucursal.');
        }
        if ($rol === 'ADMIN') {
            $tienda = null;
        }
        if ($tienda) {
            $st = $this->db->prepare('SELECT activa FROM tiendas WHERE id = ?');
            $st->execute([$tienda]);
            if ((int) $st->fetchColumn() !== 1) {
                throw new DomainException('La sucursal seleccionada no existe o está inactiva.');
            }
        }
        $st = $this->db->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $st->execute([$email, $id]);
        if ($st->fetchColumn()) {
            throw new DomainException("El correo $email ya está registrado.");
        }

        if (!$id) {
            $clave = (string) ($d['clave'] ?? '');
            self::validarClave($clave);
            $this->db->prepare('INSERT INTO usuarios (tienda_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)')
                ->execute([$tienda, $nombre, $email, password_hash($clave, PASSWORD_BCRYPT), $rol]);
            return (int) $this->db->lastInsertId();
        }

        $actual = $this->obtener($id) ?? throw new DomainException('Usuario no encontrado.');
        if ($id === $quienEdita && (!$activo || $rol !== $actual['rol'])) {
            throw new DomainException('No puede desactivarse ni cambiar su propio rol. Pida a otro administrador que lo haga.');
        }
        if ($actual['rol'] === 'ADMIN' && ($rol !== 'ADMIN' || !$activo) && $this->adminsActivos() <= 1) {
            throw new DomainException('Debe quedar al menos un administrador activo.');
        }
        if (!$activo && $this->tieneTurnoAbierto($id)) {
            throw new DomainException('El usuario tiene un turno de caja abierto. Ciérrelo antes de desactivarlo.');
        }
        if ($tienda !== ($actual['tienda_id'] !== null ? (int) $actual['tienda_id'] : null) && $this->tieneTurnoAbierto($id)) {
            throw new DomainException('El usuario tiene un turno abierto: ciérrelo antes de cambiarlo de sucursal.');
        }
        $this->db->prepare('UPDATE usuarios SET nombre = ?, email = ?, rol = ?, tienda_id = ?, activo = ? WHERE id = ?')
            ->execute([$nombre, $email, $rol, $tienda, $activo, $id]);
        return $id;
    }

    /** El administrador asigna una clave temporal (la persona la cambia en Mi cuenta). */
    public function restablecerClave(int $id, string $nueva): void
    {
        self::validarClave($nueva);
        $this->obtener($id) ?? throw new DomainException('Usuario no encontrado.');
        $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_BCRYPT), $id]);
    }

    /** Cambio de clave propio: exige la clave actual. */
    public function cambiarPropiaClave(int $id, string $actual, string $nueva, string $confirmar): void
    {
        $st = $this->db->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if (!password_verify($actual, (string) $st->fetchColumn())) {
            throw new DomainException('La contraseña actual no es correcta.');
        }
        if ($nueva !== $confirmar) {
            throw new DomainException('La nueva contraseña y su confirmación no coinciden.');
        }
        if ($nueva === $actual) {
            throw new DomainException('La nueva contraseña debe ser distinta de la actual.');
        }
        self::validarClave($nueva);
        $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_BCRYPT), $id]);
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function validarClave(string $clave): void
    {
        if (mb_strlen($clave) < self::LARGO_MINIMO_CLAVE || !preg_match('/\d/', $clave) || !preg_match('/\pL/u', $clave)) {
            throw new DomainException('La contraseña debe tener al menos ' . self::LARGO_MINIMO_CLAVE . ' caracteres, con letras y números.');
        }
    }

    private function adminsActivos(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMIN' AND activo = 1")->fetchColumn();
    }

    private function tieneTurnoAbierto(int $id): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM turnos_caja WHERE usuario_id = ? AND estado = 'ABIERTO'");
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }
}
