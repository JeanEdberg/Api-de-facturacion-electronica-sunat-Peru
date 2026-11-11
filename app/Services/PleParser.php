<?php

namespace App\Services;

use RuntimeException;

/**
 * Lector de los archivos planos del PLE (Anexo N.º 2 de la RS 112-2021) para la
 * importación de períodos anteriores.
 *
 * Reconoce el Registro de Ventas 14.1 (34 campos) y el Registro de Compras 8.1
 * (41 campos) por el número de campos de la primera línea de datos. Devuelve las
 * líneas ya divididas por el separador '|', sin interpretarlas: la conversión a
 * los modelos la hace HistoricalImportService.
 */
class PleParser
{
    public const VENTAS_141 = 34;
    public const COMPRAS_81 = 41;

    /**
     * @return array{tipo:string, lineas:array<int, array<int, string>>}
     *
     * @throws RuntimeException si el archivo no es un PLE reconocible.
     */
    public function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        $filas = [];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $filas[] = $this->campos($linea);
        }

        if ($filas === []) {
            throw new RuntimeException('El archivo no contiene líneas de datos.');
        }

        return ['tipo' => $this->clasificar(count($filas[0])), 'lineas' => $filas];
    }

    /**
     * Divide la línea por '|'. El PLE termina cada línea con un '|', así que se
     * quita solo ese terminador para no perder un último campo vacío.
     *
     * @return array<int, string>
     */
    private function campos(string $linea): array
    {
        $linea = rtrim($linea, "\r\n");
        if (str_ends_with($linea, '|')) {
            $linea = substr($linea, 0, -1);
        }

        return array_map('trim', explode('|', $linea));
    }

    /**
     * @throws RuntimeException si el número de campos no corresponde a 14.1 ni 8.1.
     */
    private function clasificar(int $campos): string
    {
        return match ($campos) {
            self::VENTAS_141 => 'ventas',
            self::COMPRAS_81 => 'compras',
            default => throw new RuntimeException(
                "No se reconoce el archivo: la primera línea tiene {$campos} campos; "
                . 'se esperaban 34 (Registro de Ventas 14.1) o 41 (Registro de Compras 8.1).'
            ),
        };
    }
}
