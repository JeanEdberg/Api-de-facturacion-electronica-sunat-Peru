<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Boleta;
use App\Models\Client;
use App\Models\Compra;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Importación de comprobantes de períodos anteriores.
 *
 * Estos documentos ya fueron declarados en su momento, así que se guardan con
 * estado ACEPTADO y NO se envían a SUNAT: reenviarlos provocaría rechazos por
 * duplicado. Se acepta el TXT del PLE (Ventas 14.1 / Compras 8.1) o una
 * plantilla CSV/XLSX propia.
 */
class ImportacionHistoricaService
{
    public const COLUMNAS = [
        'tipo_documento', 'serie', 'correlativo', 'fecha_emision', 'moneda',
        'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_razon_social',
        'base_gravada', 'igv', 'total',
    ];

    private const PLE_VENTAS = [
        'periodo', 'car_sunat', 'fecha_emision', 'fecha_vencimiento', 'tipo_documento',
        'serie', 'correlativo', 'tipo_doc_cliente', 'num_doc_cliente', 'razon_social',
        'base_gravada', 'igv', 'total',
    ];

    private const PLE_COMPRAS = [
        'periodo', 'car_sunat', 'fecha_emision', 'fecha_vencimiento', 'tipo_documento',
        'serie', 'correlativo', 'tipo_doc_proveedor', 'num_doc_proveedor', 'razon_social',
        'base_gravada', 'igv', 'total',
    ];

    public function __construct(private XlsxReader $xlsxReader)
    {
    }

    /**
     * @return array{total: int, importados: int, actualizados: int, omitidos: array, errores: array}
     */
    public function importar(UploadedFile $archivo, int $companyId, ?int $branchId, string $tipo = 'ventas'): array
    {
        $branchId = $branchId ?: Branch::where('company_id', $companyId)->value('id');

        $resultado = [
            'total' => 0,
            'importados' => 0,
            'actualizados' => 0,
            'omitidos' => [],
            'errores' => [],
        ];

        if (! $branchId) {
            $resultado['errores'][] = ['fila' => 0, 'mensaje' => 'La empresa no tiene sucursales'];
            return $resultado;
        }

        $filas = $this->leerFilas($archivo, $tipo);
        $resultado['total'] = count($filas);

        if (empty($filas)) {
            $resultado['errores'][] = ['fila' => 0, 'mensaje' => 'El archivo está vacío o no tiene la cabecera esperada'];
            return $resultado;
        }

        foreach ($filas as $fila) {
            $numero = $fila['_fila'];

            try {
                $tipoDoc = trim((string) ($fila['tipo_documento'] ?? ''));
                $serie = trim((string) ($fila['serie'] ?? ''));
                $correlativo = trim((string) ($fila['correlativo'] ?? ''));
                $numDoc = trim((string) ($fila['cliente_numero_documento'] ?? ''));
                $fecha = $this->normalizarFecha((string) ($fila['fecha_emision'] ?? ''));

                if ($serie === '' || $correlativo === '') {
                    $resultado['errores'][] = ['fila' => $numero, 'mensaje' => 'Falta serie o correlativo'];
                    continue;
                }

                if ($numDoc === '') {
                    $resultado['errores'][] = ['fila' => $numero, 'mensaje' => 'Falta el documento del cliente'];
                    continue;
                }

                $total = (float) ($fila['total'] ?? 0);
                $base = (float) ($fila['base_gravada'] ?? 0);
                $igv = (float) ($fila['igv'] ?? 0);

                if ($total <= 0) {
                    $resultado['errores'][] = ['fila' => $numero, 'mensaje' => 'El total debe ser mayor que cero'];
                    continue;
                }

                $client = $this->cliente(
                    $companyId,
                    trim((string) ($fila['cliente_tipo_documento'] ?? '6')) ?: '6',
                    $numDoc,
                    trim((string) ($fila['cliente_razon_social'] ?? 'Cliente histórico')) ?: 'Cliente histórico',
                );

                // Los comprobantes recibidos van al registro de compras; los
                // emitidos, a facturas o boletas según su tipo.
                if ($tipo === 'compras') {
                    $creado = $this->guardarCompra($companyId, $branchId, $client, $fila, $serie, $correlativo, $fecha, $base, $igv, $total);
                } else {
                    $creado = $this->guardarVenta($companyId, $branchId, $client, $tipoDoc, $serie, $correlativo, $fecha, $base, $igv, $total);
                }

                if ($creado === null) {
                    $resultado['omitidos'][] = ['fila' => $numero, 'motivo' => 'Ya existe un comprobante con esa serie y correlativo'];
                } elseif ($creado) {
                    $resultado['importados']++;
                } else {
                    $resultado['actualizados']++;
                }
            } catch (\Throwable $e) {
                $resultado['errores'][] = ['fila' => $numero, 'mensaje' => $e->getMessage()];
            }
        }

        return $resultado;
    }

    protected function cliente(int $companyId, string $tipoDoc, string $numero, string $razonSocial): Client
    {
        // La clave única de `clients` es (tipo_documento, numero_documento).
        return Client::firstOrCreate(
            ['tipo_documento' => $tipoDoc, 'numero_documento' => $numero],
            ['razon_social' => $razonSocial],
        );
    }

    protected function guardarVenta(int $companyId, int $branchId, Client $client, string $tipoDoc, string $serie, string $correlativo, string $fecha, float $base, float $igv, float $total): ?bool
    {
        $modelo = $tipoDoc === '01' ? Invoice::class : Boleta::class;

        $existente = $modelo::where('company_id', $companyId)
            ->where('serie', $serie)
            ->where('correlativo', $correlativo)
            ->first();

        $atributos = [
            'branch_id' => $branchId,
            'client_id' => $client->id,
            'tipo_documento' => $tipoDoc === '01' ? '01' : '03',
            'numero_completo' => $serie . '-' . $correlativo,
            'fecha_emision' => $fecha,
            'moneda' => 'PEN',
            'valor_venta' => $base,
            'mto_oper_gravadas' => $base,
            'mto_igv' => $igv,
            'total_impuestos' => $igv,
            'sub_total' => $base,
            'mto_imp_venta' => $total,
            'detalles' => [[
                'descripcion' => 'Importado del histórico',
                'cantidad' => 1,
                'mto_valor_unitario' => $base,
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
            ]],
            'estado_sunat' => 'ACEPTADO',
            'usuario_creacion' => 'importacion-historica',
        ];

        if ($existente) {
            // Reimportar el mismo archivo corrige los datos en vez de duplicarlos.
            $existente->update($atributos);

            return false;
        }

        $modelo::create($atributos + [
            'company_id' => $companyId,
            'serie' => $serie,
            'correlativo' => $correlativo,
        ]);

        return true;
    }

    protected function guardarCompra(int $companyId, int $branchId, Client $client, array $fila, string $serie, string $correlativo, string $fecha, float $base, float $igv, float $total): ?bool
    {
        $existente = Compra::where('company_id', $companyId)
            ->where('serie', $serie)
            ->where('correlativo', $correlativo)
            ->where('tipo_documento', trim((string) ($fila['tipo_documento'] ?? '01')))
            ->first();

        $atributos = [
            'fecha_emision' => $fecha,
            'proveedor_tipo_doc' => $client->tipo_documento,
            'proveedor_num_doc' => $client->numero_documento,
            'proveedor_razon_social' => $client->razon_social,
            'moneda' => 'PEN',
            'mto_oper_gravadas' => $base,
            'mto_igv' => $igv,
            'mto_imp_venta' => $total,
            'origen' => 'historico',
            'estado_sunat' => 'ACEPTADO',
        ];

        if ($existente) {
            $existente->update($atributos);

            return false;
        }

        Compra::create($atributos + [
            'company_id' => $companyId,
            'tipo_documento' => trim((string) ($fila['tipo_documento'] ?? '01')) ?: '01',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $serie . '-' . $correlativo,
        ]);

        return true;
    }

    /**
     * Detecta PLE (13 columnas, sin cabecera) o plantilla con cabecera.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function leerFilas(UploadedFile $archivo, string $tipo): array
    {
        $ruta = $archivo->getRealPath();
        $extension = strtolower($archivo->getClientOriginalExtension());

        $matriz = $extension === 'xlsx'
            ? ($this->xlsxReader->read($ruta)[0]['rows'] ?? [])
            : $this->leerCsv($ruta);

        if (empty($matriz)) {
            return [];
        }

        $primera = array_map(fn ($c) => strtolower(trim((string) $c)), $matriz[0]);

        // El PLE no trae cabecera: la primera fila ya es un comprobante.
        $esPle = ! in_array('serie', $primera, true) && count($primera) >= 12;

        if ($esPle) {
            $columnas = $tipo === 'compras' ? self::PLE_COMPRAS : self::PLE_VENTAS;
            $filas = [];
            foreach ($matriz as $i => $valores) {
                if (count(array_filter($valores, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }
                $fila = ['_fila' => $i + 1];
                foreach ($columnas as $pos => $columna) {
                    $fila[$columna] = $valores[$pos] ?? null;
                }
                $filas[] = $this->normalizarColumnasPle($fila, $tipo);
            }

            return $filas;
        }

        $cabecera = array_shift($matriz);
        $filas = [];
        foreach ($matriz as $i => $valores) {
            if (count(array_filter($valores, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $fila = ['_fila' => $i + 2];
            foreach ($cabecera as $pos => $columna) {
                $fila[strtolower(trim((string) $columna))] = $valores[$pos] ?? null;
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * El PLE nombra distinto al cliente según sea venta o compra.
     */
    protected function normalizarColumnasPle(array $fila, string $tipo): array
    {
        if ($tipo === 'compras') {
            $fila['cliente_tipo_documento'] = $fila['tipo_doc_proveedor'] ?? null;
            $fila['cliente_numero_documento'] = $fila['num_doc_proveedor'] ?? null;
            $fila['cliente_razon_social'] = $fila['razon_social'] ?? null;
        } else {
            $fila['cliente_tipo_documento'] = $fila['tipo_doc_cliente'] ?? null;
            $fila['cliente_numero_documento'] = $fila['num_doc_cliente'] ?? null;
            $fila['cliente_razon_social'] = $fila['razon_social'] ?? null;
        }

        return $fila;
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

        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $matriz = [];
        foreach (preg_split('/\r\n|\r|\n/', $contenido) as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $matriz[] = str_getcsv($linea, $this->detectarDelimitador($linea));
        }

        return $matriz;
    }

    protected function detectarDelimitador(string $linea): string
    {
        // El PLE usa pipes; las plantillas de Excel en español, punto y coma.
        foreach (['|', ';', ','] as $candidato) {
            if (substr_count($linea, $candidato) > 0) {
                return $candidato;
            }
        }

        return ',';
    }

    protected function normalizarFecha(string $valor): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $valor;
        }

        // El PLE usa dd/mm/yyyy.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valor, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        if (is_numeric($valor)) {
            return (new \DateTimeImmutable('1899-12-30'))
                ->modify('+' . (int) $valor . ' days')
                ->format('Y-m-d');
        }

        return $valor;
    }

    public function plantilla(string $tipo = 'ventas'): string
    {
        $ejemplo = [
            '03', 'B001', '1', '2025-01-15', 'PEN',
            '1', '45678912', 'CLIENTE DE PRUEBA',
            '100.00', '18.00', '118.00',
        ];

        $salida = implode(',', self::COLUMNAS) . "\n";
        $salida .= implode(',', $ejemplo) . "\n";

        return $salida;
    }
}
