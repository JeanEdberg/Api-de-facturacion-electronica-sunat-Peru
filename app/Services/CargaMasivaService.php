<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Services\XlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Emisión masiva de comprobantes desde CSV/XLSX.
 *
 * Cada fila es un ítem. Las filas que comparten `grupo` se unen en un solo
 * comprobante con varias líneas. Una fila con error no detiene el proceso: se
 * anota y se sigue, para que el usuario corrija sólo lo que falló.
 */
class CargaMasivaService
{
    public const COLUMNAS = [
        'grupo', 'tipo_documento', 'serie', 'fecha_emision', 'moneda',
        'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_razon_social',
        'cliente_direccion', 'cliente_email',
        'codigo', 'descripcion', 'unidad', 'cantidad', 'valor_unitario',
        'precio_unitario', 'tipo_igv', 'porcentaje_igv', 'descuento',
    ];

    public function __construct(
        private DocumentService $documentService,
        private XlsxReader $xlsxReader,
    ) {
    }

    /**
     * Procesa el archivo y devuelve el resumen con emitidos y errores.
     *
     * @return array{emitidos: array, errores: array}
     */
    public function procesar(UploadedFile $archivo, int $companyId, ?int $branchId, bool $enviarSunat): array
    {
        $filas = $this->leerFilas($archivo);

        if (empty($filas)) {
            return ['emitidos' => [], 'errores' => [['fila' => 0, 'mensaje' => 'El archivo está vacío o no tiene la cabecera esperada']]];
        }

        $company = Company::find($companyId);
        if (! $company) {
            return ['emitidos' => [], 'errores' => [['fila' => 0, 'mensaje' => 'La empresa no existe']]];
        }

        $branchId = $branchId ?: Branch::where('company_id', $company->id)->value('id');
        if (! $branchId) {
            return ['emitidos' => [], 'errores' => [['fila' => 0, 'mensaje' => 'La empresa no tiene sucursales']]];
        }

        $grupos = $this->agrupar($filas);

        $emitidos = [];
        $errores = [];

        foreach ($grupos as $nombre => $items) {
            $filaInicial = $items[0]['_fila'];

            try {
                $cabecera = $this->validarCabecera($items, $filaInicial);
                $documento = $this->emitir($cabecera, $items, $company->id, $branchId);

                if ($enviarSunat) {
                    $tipo = $cabecera['tipo_documento'] === '01' ? 'invoice' : 'boleta';
                    $this->documentService->sendToSunat($documento, $tipo);
                    $documento->refresh();
                }

                $emitidos[] = [
                    'id' => $documento->id,
                    'numero_completo' => $documento->numero_completo,
                    'tipo_documento' => $documento->tipo_documento,
                    'estado_sunat' => $documento->estado_sunat,
                    'fila_inicial' => $filaInicial,
                ];
            } catch (\Throwable $e) {
                $errores[] = ['fila' => $filaInicial, 'mensaje' => $e->getMessage()];
            }
        }

        return ['emitidos' => $emitidos, 'errores' => $errores];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function leerFilas(UploadedFile $archivo): array
    {
        $ruta = $archivo->getRealPath();
        $extension = strtolower($archivo->getClientOriginalExtension());

        $matriz = $extension === 'xlsx'
            ? ($this->xlsxReader->read($ruta)[0]['rows'] ?? [])
            : $this->leerCsv($ruta);

        if (count($matriz) < 2) {
            return [];
        }

        $cabecera = array_map(fn ($c) => strtolower(trim((string) $c)), array_shift($matriz));

        $filas = [];
        foreach ($matriz as $i => $valores) {
            if (count(array_filter($valores, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $fila = ['_fila' => $i + 2];
            foreach ($cabecera as $pos => $columna) {
                $fila[$columna] = $valores[$pos] ?? null;
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            return [];
        }

        // El BOM de Excel rompe la primera cabecera.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);
        $matriz = [];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $matriz[] = str_getcsv($linea, $this->detectarDelimitador($linea));
        }

        return $matriz;
    }

    protected function detectarDelimitador(string $linea): string
    {
        $puntoYComa = substr_count($linea, ';');
        $coma = substr_count($linea, ',');

        return $puntoYComa > $coma ? ';' : ',';
    }

    /**
     * Agrupa las filas por `grupo`. Sin grupo, cada fila es un comprobante.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function agrupar(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $clave = trim((string) ($fila['grupo'] ?? ''));
            $clave = $clave !== '' ? $clave : 'fila-' . $fila['_fila'];
            $grupos[$clave][] = $fila;
        }

        return $grupos;
    }

    protected function validarCabecera(array $items, int $fila): array
    {
        $primera = $items[0];

        $tipo = trim((string) ($primera['tipo_documento'] ?? ''));
        if (! in_array($tipo, ['01', '03'], true)) {
            throw new \InvalidArgumentException('tipo_documento debe ser 01 (factura) o 03 (boleta)');
        }

        $serie = trim((string) ($primera['serie'] ?? ''));
        if ($serie === '') {
            throw new \InvalidArgumentException('Falta la serie');
        }

        $fecha = trim((string) ($primera['fecha_emision'] ?? ''));
        if ($fecha === '') {
            throw new \InvalidArgumentException('Falta la fecha_emision');
        }

        $numero = trim((string) ($primera['cliente_numero_documento'] ?? ''));
        if ($numero === '') {
            throw new \InvalidArgumentException('Falta el documento del cliente');
        }

        return [
            'tipo_documento' => $tipo,
            'serie' => $serie,
            'fecha_emision' => $this->normalizarFecha($fecha),
            'moneda' => trim((string) ($primera['moneda'] ?? 'PEN')) ?: 'PEN',
            'cliente' => [
                'tipo_documento' => trim((string) ($primera['cliente_tipo_documento'] ?? '6')) ?: '6',
                'numero_documento' => $numero,
                'razon_social' => trim((string) ($primera['cliente_razon_social'] ?? 'Cliente varios')),
                'direccion' => trim((string) ($primera['cliente_direccion'] ?? '')) ?: null,
                'email' => trim((string) ($primera['cliente_email'] ?? '')) ?: null,
            ],
        ];
    }

    /**
     * Excel puede devolver la fecha como serial o como dd/mm/yyyy.
     */
    protected function normalizarFecha(string $valor): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $valor;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valor, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Serial de Excel: días desde 1899-12-30 (Excel cuenta mal el 29/02/1900).
        if (is_numeric($valor)) {
            return (new \DateTimeImmutable('1899-12-30'))
                ->modify('+' . (int) $valor . ' days')
                ->format('Y-m-d');
        }

        return $valor;
    }

    protected function emitir(array $cabecera, array $items, int $companyId, int $branchId)
    {
        $detalles = [];
        foreach ($items as $item) {
            $descripcion = trim((string) ($item['descripcion'] ?? ''));
            if ($descripcion === '') {
                throw new \InvalidArgumentException('Hay una fila sin descripción');
            }

            $cantidad = (float) ($item['cantidad'] ?? 1) ?: 1;
            $valorUnitario = (float) ($item['valor_unitario'] ?? 0);
            $precioUnitario = (float) ($item['precio_unitario'] ?? 0);

            // Nombres de campo que espera DocumentService::calculateTotals.
            $detalles[] = [
                'codigo' => trim((string) ($item['codigo'] ?? '')) ?: 'SIN-CODIGO',
                'descripcion' => $descripcion,
                'unidad' => trim((string) ($item['unidad'] ?? 'NIU')) ?: 'NIU',
                'cantidad' => $cantidad,
                'mto_valor_unitario' => $valorUnitario ?: round($precioUnitario / 1.18, 2),
                'porcentaje_igv' => (float) ($item['porcentaje_igv'] ?? 18),
                'tip_afe_igv' => trim((string) ($item['tipo_igv'] ?? '10')) ?: '10',
                'descuentos' => (float) ($item['descuento'] ?? 0) > 0
                    ? [['monto' => (float) $item['descuento']]]
                    : [],
            ];
        }

        $datos = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'serie' => $cabecera['serie'],
            'fecha_emision' => $cabecera['fecha_emision'],
            'moneda' => $cabecera['moneda'],
            'metodo_envio' => 'individual',
            'client' => $cabecera['cliente'],
            'detalles' => $detalles,
        ];

        return DB::transaction(function () use ($datos, $cabecera) {
            return $cabecera['tipo_documento'] === '01'
                ? $this->documentService->createInvoice($datos)
                : $this->documentService->createBoleta($datos);
        });
    }

    /**
     * Plantilla CSV de carga masiva.
     */
    public function plantilla(): string
    {
        $ejemplo = [
            'G-001', '03', 'B001', '2026-11-23', 'PEN',
            '1', '45678912', 'CLIENTE DE PRUEBA', '', '',
            'P001', 'SERVICIO DE TRANSPORTE', 'NIU', '1', '100.00',
            '118.00', '10', '18', '0',
        ];

        $salida = implode(',', self::COLUMNAS) . "\n";
        $salida .= implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"' . $v . '"' : $v, $ejemplo)) . "\n";

        return $salida;
    }
}
