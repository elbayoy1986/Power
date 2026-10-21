<?php
declare(strict_types=1);

namespace App\Catalogo;

use App\Inventario\InventarioService;
use App\Support\Money;
use DomainException;
use PDO;
use Throwable;

/** Alta, edición y carga masiva (CSV) del catálogo central. */
final class ProductosService
{
    public const COLUMNAS_CSV = ['sku', 'nombre', 'categoria', 'precio', 'impuesto', 'vendible_solo', 'controla_stock', 'minimo'];

    public function __construct(private PDO $db, private InventarioService $inventario)
    {
    }

    /** Crea o actualiza (si trae id) un producto. Devuelve el id. */
    public function guardar(array $d): int
    {
        $p = $this->normalizar($d);
        $id = (int) ($d['id'] ?? 0);

        $st = $this->db->prepare('SELECT id FROM productos WHERE sku = ? AND id <> ?');
        $st->execute([$p['sku'], $id]);
        if ($st->fetchColumn()) {
            throw new DomainException("El SKU {$p['sku']} ya existe en otro producto.");
        }
        $p['categoria_id'] = $this->categoriaId($d);

        if ($id) {
            $this->db->prepare(
                'UPDATE productos SET categoria_id = ?, sku = ?, nombre = ?, precio_centavos = ?, tipo_impuesto = ?,
                        vendible_solo = ?, controla_stock = ? WHERE id = ?'
            )->execute([$p['categoria_id'], $p['sku'], $p['nombre'], $p['precio'], $p['impuesto'], $p['vendible_solo'], $p['controla_stock'], $id]);
        } else {
            $this->db->prepare(
                'INSERT INTO productos (categoria_id, sku, nombre, precio_centavos, tipo_impuesto, vendible_solo, controla_stock)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$p['categoria_id'], $p['sku'], $p['nombre'], $p['precio'], $p['impuesto'], $p['vendible_solo'], $p['controla_stock']]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->inventario->asegurarFilas($id);
        return $id;
    }

    /**
     * Carga masiva. Columnas (con encabezado): sku,nombre,categoria,precio,impuesto[,vendible_solo,controla_stock,minimo]
     * - Si el SKU existe se actualiza; si no, se crea. La categoría se crea si no existe.
     * - "minimo" se aplica en todas las tiendas.
     * - Todo o nada: si una fila tiene error no se guarda ninguna y se reportan todas las filas con error.
     * @return array{creados:int, actualizados:int}
     */
    public function importarCsv(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido); // BOM de Excel
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido));
        if (count($lineas) < 2) {
            throw new DomainException('El archivo no tiene filas de datos. La primera fila debe ser el encabezado.');
        }
        $sep = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ','; // Excel en español usa ;
        $enc = array_map(fn ($c) => strtolower(trim($c)), str_getcsv($lineas[0], $sep));
        foreach (['sku', 'nombre', 'categoria', 'precio'] as $req) {
            if (!in_array($req, $enc, true)) {
                throw new DomainException("Falta la columna \"$req\". Encabezado esperado: " . implode(',', self::COLUMNAS_CSV));
            }
        }

        $filas = [];
        $errores = [];
        $skus = [];
        foreach (array_slice($lineas, 1) as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $n = $i + 2;
            $vals = str_getcsv($linea, $sep);
            $fila = array_combine($enc, array_pad(array_slice($vals, 0, count($enc)), count($enc), ''));
            try {
                $p = $this->normalizar($fila);
                if (isset($skus[$p['sku']])) {
                    throw new DomainException("SKU repetido en el archivo (fila {$skus[$p['sku']]}).");
                }
                $skus[$p['sku']] = $n;
                $filas[] = $fila;
            } catch (DomainException $e) {
                $errores[] = "Fila $n: " . $e->getMessage();
            }
        }
        if ($errores) {
            throw new DomainException('No se importó nada. Corrija y vuelva a cargar: ' . implode(' · ', array_slice($errores, 0, 15))
                . (count($errores) > 15 ? ' · y ' . (count($errores) - 15) . ' más.' : ''));
        }

        $creados = $actualizados = 0;
        $this->db->beginTransaction();
        try {
            $buscar = $this->db->prepare('SELECT id FROM productos WHERE sku = ?');
            foreach ($filas as $fila) {
                $buscar->execute([strtoupper(trim($fila['sku']))]);
                $fila['id'] = (int) $buscar->fetchColumn();
                $fila['id'] ? $actualizados++ : $creados++;
                $id = $this->guardar($fila);
                if (($fila['minimo'] ?? '') !== '') {
                    $this->db->prepare('UPDATE inventario SET minimo = ? WHERE producto_id = ?')->execute([max(0, (float) $fila['minimo']), $id]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['creados' => $creados, 'actualizados' => $actualizados];
    }

    private function normalizar(array $d): array
    {
        $sku = strtoupper(trim((string) ($d['sku'] ?? '')));
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $precioTxt = trim((string) ($d['precio'] ?? ''));
        $imp = strtoupper(trim((string) ($d['impuesto'] ?? $d['tipo_impuesto'] ?? 'G15'))) ?: 'G15';
        $imp = ['15' => 'G15', '18' => 'G18', 'EXENTO' => 'EXE', '0' => 'EXE'][$imp] ?? $imp;

        if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,29}$/', $sku)) {
            throw new DomainException('SKU inválido: use letras, números, guion o punto (máx. 30).');
        }
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            throw new DomainException("Nombre vacío o muy largo ($sku).");
        }
        if (!preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $precioTxt)) {
            throw new DomainException("Precio inválido \"$precioTxt\" ($sku). Use 120.00");
        }
        if (!in_array($imp, ['G15', 'G18', 'EXE'], true)) {
            throw new DomainException("Impuesto \"$imp\" inválido ($sku). Use G15, G18 o EXE.");
        }
        if (trim((string) ($d['categoria'] ?? '')) === '' && empty($d['categoria_id'])) {
            throw new DomainException("Falta la categoría ($sku).");
        }
        $bool = fn ($v, $def) => ($v === null || $v === '') ? $def : (int) in_array(strtolower(trim((string) $v)), ['1', 'si', 'sí', 'true', 'x', 'on'], true);
        return [
            'sku' => $sku, 'nombre' => $nombre, 'impuesto' => $imp,
            'precio' => Money::aCentavos(str_replace(',', '.', $precioTxt)),
            'vendible_solo' => $bool($d['vendible_solo'] ?? null, 1),
            'controla_stock' => $bool($d['controla_stock'] ?? null, 1),
        ];
    }

    private function categoriaId(array $d): int
    {
        if (!empty($d['categoria_id'])) {
            return (int) $d['categoria_id'];
        }
        $nombre = trim((string) $d['categoria']);
        $st = $this->db->prepare('SELECT id FROM categorias WHERE nombre = ?');
        $st->execute([$nombre]);
        if ($id = $st->fetchColumn()) {
            return (int) $id;
        }
        $this->db->prepare('INSERT INTO categorias (nombre, orden) SELECT ?, COALESCE(MAX(orden), 0) + 1 FROM categorias')->execute([$nombre]);
        return (int) $this->db->lastInsertId();
    }
}
