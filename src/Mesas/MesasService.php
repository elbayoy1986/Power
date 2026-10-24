<?php
declare(strict_types=1);

namespace App\Mesas;

use DomainException;
use PDO;
use PDOException;

/**
 * Configuración del salón (zonas y mesas por tienda), de las estaciones de cocina
 * con su ruta por categoría, y el estado del salón en vivo.
 */
final class MesasService
{
    public function __construct(private PDO $db)
    {
    }

    /** Salón en vivo: zonas con sus mesas y, si está ocupada, el resumen de su cuenta. */
    public function salon(int $tiendaId): array
    {
        $st = $this->db->prepare(
            "SELECT m.id, m.nombre, m.capacidad, z.id AS zona_id, z.nombre AS zona,
                    c.id AS cuenta_id, c.estado, c.comensales, c.mesero_id, u.nombre AS mesero,
                    TIMESTAMPDIFF(MINUTE, c.abierta_en, NOW()) AS minutos,
                    (SELECT COALESCE(SUM(i.cantidad), 0) FROM cuenta_items i WHERE i.cuenta_id = c.id AND i.estado = 'ACTIVO') AS articulos,
                    (SELECT COUNT(*) FROM comandas k WHERE k.cuenta_id = c.id AND k.tipo = 'PEDIDO' AND k.estado = 'EN_COCINA') AS en_cocina,
                    (SELECT COUNT(*) FROM comandas k WHERE k.cuenta_id = c.id AND k.tipo = 'PEDIDO' AND k.estado = 'LISTA') AS listas
             FROM mesas m JOIN zonas z ON z.id = m.zona_id
             LEFT JOIN cuentas c ON c.mesa_activa = m.id
             LEFT JOIN usuarios u ON u.id = c.mesero_id
             WHERE m.tienda_id = ? AND m.activa = 1 AND z.activa = 1
             ORDER BY z.orden, z.id, m.orden, m.id"
        );
        $st->execute([$tiendaId]);
        $zonas = [];
        foreach ($st->fetchAll() as $m) {
            $zonas[$m['zona_id']] ??= ['id' => (int) $m['zona_id'], 'nombre' => $m['zona'], 'mesas' => []];
            $zonas[$m['zona_id']]['mesas'][] = [
                'id' => (int) $m['id'], 'nombre' => $m['nombre'], 'capacidad' => (int) $m['capacidad'],
                'cuenta' => $m['cuenta_id'] === null ? null : [
                    'id' => (int) $m['cuenta_id'], 'estado' => $m['estado'], 'comensales' => (int) $m['comensales'],
                    'mesero_id' => (int) $m['mesero_id'], 'mesero' => $m['mesero'], 'minutos' => (int) $m['minutos'],
                    'articulos' => (int) $m['articulos'], 'en_cocina' => (int) $m['en_cocina'], 'listas' => (int) $m['listas'],
                ],
            ];
        }
        return array_values($zonas);
    }

    // ---------- Zonas ----------

    public function zonas(int $tiendaId): array
    {
        $st = $this->db->prepare(
            'SELECT z.*, (SELECT COUNT(*) FROM mesas m WHERE m.zona_id = z.id AND m.activa = 1) AS mesas
             FROM zonas z WHERE z.tienda_id = ? ORDER BY z.activa DESC, z.orden, z.id'
        );
        $st->execute([$tiendaId]);
        return $st->fetchAll();
    }

    public function guardarZona(int $tiendaId, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            throw new DomainException('Escriba el nombre de la zona (máximo 60 letras), por ejemplo "Terraza".');
        }
        $orden = (int) ($d['orden'] ?? 0);
        $id = (int) ($d['id'] ?? 0);
        try {
            if ($id) {
                $this->db->prepare('UPDATE zonas SET nombre = ?, orden = ? WHERE id = ? AND tienda_id = ?')->execute([$nombre, $orden, $id, $tiendaId]);
                return $id;
            }
            $this->db->prepare('INSERT INTO zonas (tienda_id, nombre, orden) VALUES (?,?,?)')->execute([$tiendaId, $nombre, $orden]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw $e->getCode() === '23000' ? new DomainException("Ya existe la zona \"$nombre\" en esta tienda.") : $e;
        }
    }

    public function alternarZona(int $tiendaId, int $id): void
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM cuentas c JOIN mesas m ON m.id = c.mesa_activa WHERE m.zona_id = ? AND m.tienda_id = ?'
        );
        $st->execute([$id, $tiendaId]);
        $ocupadas = (int) $st->fetchColumn();
        $st = $this->db->prepare('SELECT activa FROM zonas WHERE id = ? AND tienda_id = ?');
        $st->execute([$id, $tiendaId]);
        $activa = $st->fetchColumn();
        if ($activa === false) {
            throw new DomainException('Zona no encontrada.');
        }
        if ((int) $activa === 1 && $ocupadas > 0) {
            throw new DomainException('Hay mesas ocupadas en esa zona: cóbrelas o transfiéralas antes de desactivarla.');
        }
        $this->db->prepare('UPDATE zonas SET activa = 1 - activa WHERE id = ?')->execute([$id]);
    }

    // ---------- Mesas ----------

    public function mesas(int $tiendaId): array
    {
        $st = $this->db->prepare(
            'SELECT m.*, z.nombre AS zona, c.id AS cuenta_id FROM mesas m JOIN zonas z ON z.id = m.zona_id
             LEFT JOIN cuentas c ON c.mesa_activa = m.id
             WHERE m.tienda_id = ? ORDER BY m.activa DESC, z.orden, z.id, m.orden, m.id'
        );
        $st->execute([$tiendaId]);
        return $st->fetchAll();
    }

    public function guardarMesa(int $tiendaId, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 20) {
            throw new DomainException('Escriba el nombre o número de la mesa (máximo 20 caracteres).');
        }
        $zona = $this->zona($tiendaId, (int) ($d['zona_id'] ?? 0));
        $capacidad = (int) ($d['capacidad'] ?? 4);
        if ($capacidad < 1 || $capacidad > 50) {
            throw new DomainException('La capacidad debe estar entre 1 y 50 personas.');
        }
        $orden = (int) ($d['orden'] ?? 0);
        $id = (int) ($d['id'] ?? 0);
        try {
            if ($id) {
                $this->db->prepare('UPDATE mesas SET zona_id = ?, nombre = ?, capacidad = ?, orden = ? WHERE id = ? AND tienda_id = ?')
                    ->execute([$zona['id'], $nombre, $capacidad, $orden, $id, $tiendaId]);
                return $id;
            }
            $this->db->prepare('INSERT INTO mesas (tienda_id, zona_id, nombre, capacidad, orden) VALUES (?,?,?,?,?)')
                ->execute([$tiendaId, $zona['id'], $nombre, $capacidad, $orden]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw $e->getCode() === '23000' ? new DomainException("Ya existe la mesa \"$nombre\" en esta tienda.") : $e;
        }
    }

    /** Crea varias mesas de una vez: prefijo "T", del 1 al 6 → T1…T6. Las que ya existen se saltan. */
    public function crearVarias(int $tiendaId, int $zonaId, string $prefijo, int $desde, int $hasta, int $capacidad): int
    {
        $zona = $this->zona($tiendaId, $zonaId);
        $prefijo = trim($prefijo);
        if ($desde < 1 || $hasta < $desde || $hasta - $desde >= 100) {
            throw new DomainException('Indique un rango válido (por ejemplo del 1 al 12, máximo 100 mesas).');
        }
        if (mb_strlen($prefijo . $hasta) > 20) {
            throw new DomainException('El prefijo es muy largo.');
        }
        if ($capacidad < 1 || $capacidad > 50) {
            throw new DomainException('La capacidad debe estar entre 1 y 50 personas.');
        }
        $st = $this->db->prepare('INSERT IGNORE INTO mesas (tienda_id, zona_id, nombre, capacidad, orden) VALUES (?,?,?,?,?)');
        $creadas = 0;
        for ($n = $desde; $n <= $hasta; $n++) {
            $st->execute([$tiendaId, $zona['id'], $prefijo . $n, $capacidad, $n]);
            $creadas += $st->rowCount();
        }
        return $creadas;
    }

    public function alternarMesa(int $tiendaId, int $id): void
    {
        $st = $this->db->prepare(
            'SELECT m.activa, c.id AS cuenta FROM mesas m LEFT JOIN cuentas c ON c.mesa_activa = m.id WHERE m.id = ? AND m.tienda_id = ?'
        );
        $st->execute([$id, $tiendaId]);
        $m = $st->fetch() ?: throw new DomainException('Mesa no encontrada.');
        if ($m['activa'] && $m['cuenta']) {
            throw new DomainException('La mesa está ocupada: cobre o transfiera la cuenta antes de desactivarla.');
        }
        $this->db->prepare('UPDATE mesas SET activa = 1 - activa WHERE id = ?')->execute([$id]);
    }

    // ---------- Estaciones de cocina (compartidas por todas las tiendas, como el catálogo) ----------

    public function estaciones(bool $soloActivas = false): array
    {
        return $this->db->query(
            'SELECT e.*, (SELECT COUNT(*) FROM categorias c WHERE c.estacion_id = e.id) AS categorias FROM estaciones e'
            . ($soloActivas ? ' WHERE e.activa = 1' : '') . ' ORDER BY e.activa DESC, e.orden, e.id'
        )->fetchAll();
    }

    public function guardarEstacion(array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 60) {
            throw new DomainException('Escriba el nombre de la estación (máximo 60 letras), por ejemplo "Bar" o "Parrilla".');
        }
        $orden = (int) ($d['orden'] ?? 0);
        $id = (int) ($d['id'] ?? 0);
        try {
            if ($id) {
                $this->db->prepare('UPDATE estaciones SET nombre = ?, orden = ? WHERE id = ?')->execute([$nombre, $orden, $id]);
                return $id;
            }
            $this->db->prepare('INSERT INTO estaciones (nombre, orden) VALUES (?,?)')->execute([$nombre, $orden]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw $e->getCode() === '23000' ? new DomainException("Ya existe la estación \"$nombre\".") : $e;
        }
    }

    public function alternarEstacion(int $id): void
    {
        $st = $this->db->prepare('UPDATE estaciones SET activa = 1 - activa WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Estación no encontrada.');
        }
    }

    /** @param array<int,string|int> $mapa categoria_id => estacion_id ('' = la primera estación activa) */
    public function rutasCategorias(array $mapa): void
    {
        $validas = array_map('intval', array_column($this->estaciones(), 'id'));
        $st = $this->db->prepare('UPDATE categorias SET estacion_id = ? WHERE id = ?');
        foreach ($mapa as $categoria => $estacion) {
            $estacion = $estacion === '' || $estacion === null ? null : (int) $estacion;
            if ($estacion !== null && !in_array($estacion, $validas, true)) {
                throw new DomainException('Estación no válida.');
            }
            $st->execute([$estacion, (int) $categoria]);
        }
    }

    private function zona(int $tiendaId, int $zonaId): array
    {
        $st = $this->db->prepare('SELECT * FROM zonas WHERE id = ? AND tienda_id = ?');
        $st->execute([$zonaId, $tiendaId]);
        return $st->fetch() ?: throw new DomainException('Elija una zona de esta tienda (créela primero si no existe).');
    }
}
