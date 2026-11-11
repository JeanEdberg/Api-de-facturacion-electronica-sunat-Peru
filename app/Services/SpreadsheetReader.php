<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lector mínimo de hojas de cálculo para la carga masiva.
 *
 * Acepta CSV (delimitado por coma o punto y coma) y XLSX. No se usa una
 * librería externa porque el proyecto no la trae; para XLSX se lee el
 * contenedor ZIP y se interpretan los XML con las reglas OOXML básicas
 * (inlineStr y sharedStrings).
 *
 * Devuelve siempre una matriz de filas con las celdas ya normalizadas a
 * texto, recortando filas y columnas vacías del final.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, array<int, string>>
     *
     * @throws RuntimeException si el archivo no se puede interpretar.
     */
    public function leer(string $ruta, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($ruta, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->leerCsv($ruta),
            'xlsx' => $this->leerXlsx($ruta),
            default => throw new RuntimeException("Formato no soportado: .{$extension}. Usa CSV o XLSX."),
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        // Detectar el separador contando ocurrencias en la primera línea.
        $primeraLinea = strtok($contenido, "\n") ?: '';
        $separador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';

        $filas = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contenido);
        rewind($stream);

        while (($celdas = fgetcsv($stream, 0, $separador)) !== false) {
            $filas[] = array_map(fn ($c) => trim((string) $c), $celdas);
        }
        fclose($stream);

        return $this->limpiar($filas);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function leerXlsx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $sharedStrings = $this->leerSharedStrings($zip);
            $hojaXml = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($hojaXml === false) {
                throw new RuntimeException('El XLSX no contiene la hoja sheet1.xml.');
            }

            return $this->parsearHoja($hojaXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function leerSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sst = $this->simpleXml($xml);
        $strings = [];
        foreach ($sst->si as $si) {
            $strings[] = $this->textoDeSi($si);
        }

        return $strings;
    }

    private function textoDeSi(SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }

        // Texto enriquecido: concatena todos los <t> de los <r>.
        $texto = '';
        foreach ($si->r as $run) {
            $texto .= (string) $run->t;
        }

        return $texto;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parsearHoja(string $hojaXml, array $sharedStrings): array
    {
        $hoja = $this->simpleXml($hojaXml);
        $filas = [];

        foreach ($hoja->sheetData->row as $fila) {
            $celdas = [];
            foreach ($fila->c as $celda) {
                $ref = (string) $celda['r'];
                $indice = $this->columnaIndice($ref);
                $tipo = (string) $celda['t'];
                $valor = (string) $celda->v;

                if ($tipo === 's') {
                    $valor = $sharedStrings[(int) $valor] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $valor = (string) $celda->is->t;
                }

                $celdas[$indice] = trim($valor);
            }

            $filas[] = $this->normalizarFila($celdas);
        }

        return $this->limpiar($filas);
    }

    /**
     * Convierte el mapa [índice => valor] en una lista contigua de columnas.
     *
     * @param  array<int, string>  $celdas
     * @return array<int, string>
     */
    private function normalizarFila(array $celdas): array
    {
        if ($celdas === []) {
            return [];
        }

        $max = max(array_keys($celdas));
        $fila = [];
        for ($i = 0; $i <= $max; $i++) {
            $fila[] = $celdas[$i] ?? '';
        }

        return $fila;
    }

    private function columnaIndice(string $ref): int
    {
        $letras = preg_replace('/[0-9]/', '', $ref);
        $indice = 0;
        foreach (str_split($letras) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }

    private function simpleXml(string $xml): SimpleXMLElement
    {
        $anterior = libxml_use_internal_errors(true);
        $elemento = simplexml_load_string($xml);
        libxml_use_internal_errors($anterior);

        if ($elemento === false) {
            throw new RuntimeException('El XLSX contiene XML inválido.');
        }

        return $elemento;
    }

    /**
     * Elimina filas y columnas vacías al final.
     *
     * @param  array<int, array<int, string>>  $filas
     * @return array<int, array<int, string>>
     */
    private function limpiar(array $filas): array
    {
        $filas = array_values(array_filter($filas, fn ($fila) => $this->filaNoVacia($fila)));

        return array_map(function ($fila) {
            while ($fila !== [] && end($fila) === '') {
                array_pop($fila);
            }

            return $fila;
        }, $filas);
    }

    /**
     * @param  array<int, string>  $fila
     */
    private function filaNoVacia(array $fila): bool
    {
        foreach ($fila as $celda) {
            if (trim((string) $celda) !== '') {
                return true;
            }
        }

        return false;
    }
}
