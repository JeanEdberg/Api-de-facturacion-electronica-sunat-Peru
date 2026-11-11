<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Lector mínimo de libros Excel (.xlsx) sin dependencias externas.
 *
 * Un .xlsx es un ZIP (contenedor OOXML). Se leen `xl/sharedStrings.xml` y las
 * hojas `xl/worksheets/sheetN.xml`. Soporta lo que usan los padrones de SUNAT:
 * cadenas compartidas, celdas numéricas y celdas de texto en línea.
 */
class XlsxReader
{
    /**
     * Lee un .xlsx y devuelve sus hojas.
     *
     * @return array<int, array{name: string, rows: array<int, array<int, mixed>>}>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("No existe el archivo: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo .xlsx (ZIP inválido)');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false && $sharedXml !== '') {
            $shared = $this->parseSharedStrings($sharedXml);
        }

        $sheetNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
                $sheetNames[(int) $m[1]] = $name;
            }
        }
        ksort($sheetNames);

        $sheets = [];
        foreach ($sheetNames as $idx => $name) {
            $xml = $zip->getFromName($name);
            $sheets[] = [
                'name' => "Hoja{$idx}",
                'rows' => $this->parseSheet((string) $xml, $shared),
            ];
        }
        $zip->close();

        return $sheets;
    }

    /**
     * Extrae los textos de `sharedStrings.xml` (cada <si>, uniendo sus runs <t>).
     *
     * @return array<int, string>
     */
    private function parseSharedStrings(string $xml): array
    {
        $out = [];
        if (preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $items)) {
            foreach ($items[1] as $item) {
                $text = '';
                if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $item, $runs)) {
                    $text = implode('', $runs[1]);
                }
                $out[] = $this->unescape($text);
            }
        }

        return $out;
    }

    /**
     * Convierte una hoja XML en una matriz de valores (respetando el índice de columna).
     *
     * @param  array<int, string>  $shared
     * @return array<int, array<int, mixed>>
     */
    private function parseSheet(string $xml, array $shared): array
    {
        $rows = [];
        if (!preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rowMatches)) {
            return $rows;
        }

        foreach ($rowMatches[1] as $rowXml) {
            $row = [];
            if (preg_match_all('#<c\b[^>]*/>|<c\b[^>]*>.*?</c>#s', $rowXml, $cells)) {
                foreach ($cells[0] as $cell) {
                    $ref = preg_match('#r="([A-Z]+)\d+"#', $cell, $rm) ? $rm[1] : null;
                    $col = $ref ? $this->colFromRef($ref) : count($row);
                    $row[$col] = $this->cellValue($cell, $shared);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Valor de una celda: cadena compartida, texto en línea o número.
     */
    private function cellValue(string $cell, array $shared): mixed
    {
        $type = preg_match('#t="([^"]+)"#', $cell, $tm) ? $tm[1] : null;

        if ($type === 'inlineStr') {
            $text = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $cell, $runs)) {
                $text = implode('', $runs[1]);
            }

            return $this->unescape($text);
        }

        if (!preg_match('#<v>(.*?)</v>#s', $cell, $vm)) {
            return '';
        }
        $value = $vm[1];

        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }
        if ($type === 'str') {
            return $this->unescape($value);
        }

        return is_numeric($value) ? (float) $value : $this->unescape($value);
    }

    /**
     * Convierte la referencia de columna ('A', 'AB') en índice 0-based.
     */
    private function colFromRef(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private function unescape(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
