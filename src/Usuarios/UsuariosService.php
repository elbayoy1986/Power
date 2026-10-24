<?php
declare(strict_types=1);

namespace App\Usuarios;

use DomainException;
use PDO;

/**
 * Personal: altas, cambios de rol y de clave, activar y desactivar.
 * - El administrador gestiona a todos.
 * - El gerente gestiona al personal operativo (cajeros, meseros y cocina) de su tienda.
 * - Nadie puede cambiarse el rol ni desactivarse a sí mismo.
 */
final class UsuariosService
{
    public const ROLES = ['ADMIN' => 'Administrador', 'GERENTE' => 'Gerente', 'CAJERO' => 'Cajero', 'MESERO' => 'Mesero', 'COCINA' => 'Cocina'];
    public const ROLES_GERENTE = ['CAJERO', 'MESERO', 'COCINA'];
    /** Roles que trabajan en una sola tienda. */
    public const CON_TIENDA = ['CAJERO', 'MESERO', 'COCINA'];

    public function __construct(private PDO $db)
    {
    }

    public function listar(array $actor): array
    {
        $usuarios = $this->db->query(
            'SELECT u.id, u.nombre, u.email, u.rol, u.tienda_id, u.activo, t.nombre AS tienda
             FROM usuarios u LEFT JOIN tiendas t ON t.id = u.tienda_id ORDER BY u.activo DESC, u.rol, u.nombre'
        )->fetchAll();
        return array_values(array_filter($usuarios, fn ($u) => (int) $u['id'] === $actor['id'] || $this->puedeGestionar($actor, $u)));
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT id, nombre, email, rol, tienda_id, activo FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function puedeGestionar(array $actor, array $u): bool
    {
        if ($actor['rol'] === 'ADMIN') {
            return true;
        }
        return $actor['rol'] === 'GERENTE' && in_array($u['rol'], self::ROLES_GERENTE, true)
            && ($actor['tienda_id'] === null || (int) $u['tienda_id'] === $actor['tienda_id']);
    }

    /** @return string[] roles que el usuario puede asignar */
    public function rolesAsignables(array $actor): array
    {
        return $actor['rol'] === 'ADMIN' ? array_keys(self::ROLES) : self::ROLES_GERENTE;
    }

    /** Crea o actualiza (si trae id). La clave es obligatoria al crear y opcional al editar. */
    public function guardar(array $actor, array $d): int
    {
        $id = (int) ($d['id'] ?? 0);
        $actual = $id ? ($this->obtener($id) ?? throw new DomainException('Usuario no encontrado.')) : null;
        if ($actual && (int) $actual['id'] !== $actor['id'] && !$this->puedeGestionar($actor, $actual)) {
            throw new DomainException('No puede modificar a este usuario.');
        }
        $esUnoMismo = $actual && (int) $actual['id'] === $actor['id'];

        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            throw new DomainException('Escriba el nombre completo (2 a 100 letras).');
        }
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            throw new DomainException('Correo inválido. Es el usuario con que se ingresa.');
        }
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?');
        $st->execute([$email, $id]);
        if ($st->fetchColumn()) {
            throw new DomainException("El correo $email ya lo usa otra persona.");
        }

        $rol = $esUnoMismo ? $actual['rol'] : (string) ($d['rol'] ?? '');
        if (!$esUnoMismo && !in_array($rol, $this->rolesAsignables($actor), true)) {
            throw new DomainException('Rol no permitido.');
        }
        $tienda = $esUnoMismo ? $actual['tienda_id'] : (($d['tienda_id'] ?? '') === '' ? null : (int) $d['tienda_id']);
        if (!$esUnoMismo && $actor['rol'] === 'GERENTE' && $actor['tienda_id'] !== null) {
            $tienda = $actor['tienda_id']; // el gerente de tienda solo da de alta en la suya
        }
        if ($tienda === null && in_array($rol, self::CON_TIENDA, true)) {
            throw new DomainException('Asigne la tienda donde trabaja: cajeros, meseros y cocina pertenecen a una sola tienda.');
        }
        if ($tienda !== null) {
            $st = $this->db->prepare('SELECT 1 FROM tiendas WHERE id = ?');
            $st->execute([$tienda]);
            if (!$st->fetchColumn()) {
                throw new DomainException('Tienda no válida.');
            }
        }

        $clave = (string) ($d['clave'] ?? '');
        if ((!$actual || $clave !== '') && strlen($clave) < 8) {
            throw new DomainException('La contraseña debe tener al menos 8 caracteres.');
        }
        $hash = $clave !== '' ? password_hash($clave, PASSWORD_DEFAULT) : null;

        if ($actual) {
            $this->db->prepare('UPDATE usuarios SET nombre = ?, email = ?, rol = ?, tienda_id = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?')
                ->execute([$nombre, $email, $rol, $tienda, $hash, $id]);
            return $id;
        }
        $this->db->prepare('INSERT INTO usuarios (tienda_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)')
            ->execute([$tienda, $nombre, $email, $hash, $rol]);
        return (int) $this->db->lastInsertId();
    }

    public function alternar(array $actor, int $id): void
    {
        $u = $this->obtener($id) ?? throw new DomainException('Usuario no encontrado.');
        if ((int) $u['id'] === $actor['id']) {
            throw new DomainException('No puede desactivar su propio usuario.');
        }
        if (!$this->puedeGestionar($actor, $u)) {
            throw new DomainException('No puede modificar a este usuario.');
        }
        $this->db->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ?')->execute([$id]);
    }

    /** Quienes pueden atender mesas en la tienda (meseros primero). */
    public function meseros(int $tiendaId): array
    {
        $st = $this->db->prepare(
            "SELECT id, nombre, rol FROM usuarios WHERE activo = 1 AND rol <> 'COCINA' AND (tienda_id IS NULL OR tienda_id = ?)
             ORDER BY rol <> 'MESERO', nombre"
        );
        $st->execute([$tiendaId]);
        return $st->fetchAll();
    }
}
