<?php

namespace App\Services;

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Compra;
use App\Models\Correlative;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Importación de comprobantes de períodos anteriores (histórico).
 *
 * Acepta dos orígenes:
 *   - Archivos planos del PLE: Registro de Ventas 14.1 (34 campos) y Registro de
 *     Compras 8.1 (41 campos), tal como los descarga el contribuyente de SUNAT.
 *   - Hojas de cálculo CSV/XLSX con la plantilla de importación histórica.
 *
 * Los comprobantes se guardan SIN enviarlos a SUNAT (ya fueron declarados en su
 * momento): quedan con estado ACEPTADO para que alimenten los libros y la
 * propuesta del SIRE. La operación es idempotente: reimportar el mismo archivo
 * actualiza en vez de duplicar.
 */
class HistoricalImportService
{
    public function __construct(
        private PleParser $ple,
        private SpreadsheetReader $reader,
    ) {}

    /**
     * Detecta el formato y procesa el archivo.
     *
     * @return array{tipo:string, total:int, importados:int, actualizados:int, omitidos:array<int,array<string,mixed>>, errores:array<int,array<string,mixed>>}
     */
    public function procesar(string $ruta, Company $company, ?int $branchId = null, ?string $extension = null): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        // El PLE es un TXT delimitado por '|'; el resto se lee como hoja de cálculo.
        if (str_contains(substr($contenido, 0, 2000), '|')) {
            return $this->procesarPle($contenido, $company, $branchId);
        }

        return $this->procesarPlantilla($ruta, $company, $branchId, $extension);
    }

    /**
     * @return array{tipo:string, total:int, importados:int, actualizados:int, omitidos:array<int,array<string,mixed>>, errores:array<int,array<string,mixed>>}
     */
    private function procesarPle(string $contenido, Company $company, ?int $branchId): array
    {
        ['tipo' => $tipo, 'lineas' => $lineas] = $this->ple->leer($contenido);

        return $tipo === 'ventas'
            ? $this->importarVentasPle($lineas, $company, $branchId)
            : $this->importarComprasPle($lineas, $company, $branchId);
    }

    /**
     * Importa las líneas del Registro de Ventas 14.1.
     *
     * @param  array<int, array<int, string>>  $lineas
     */
    public function importarVentasPle(array $lineas, Company $company, ?int $branchId): array
    {
        $branchId = $this->resolverSucursal($company, $branchId);

        $resultado = $this->resultadoVacio('ventas', count($lineas));
        $maximos = [];

        foreach ($lineas as $indice => $campos) {
            $fila = $indice + 1;

            try {
                $tipoDoc = $this->campo($campos, 5);
                if (! in_array($tipoDoc, ['01', '03'], true)) {
                    $resultado['omitidos'][] = [
                        'fila' => $fila,
                        'motivo' => "Tipo de documento {$tipoDoc} no soportado en la importación histórica de ventas (solo 01 y 03).",
                    ];
                    continue;
                }

                [$creado, $modelo] = $this->guardarVenta($campos, $company, $branchId);
                $resultado[$creado ? 'importados' : 'actualizados']++;

                $serie = $this->campo($campos, 6);
                $maximos[$tipoDoc][$serie] = max(
                    $maximos[$tipoDoc][$serie] ?? 0,
                    (int) $this->campo($campos, 7),
                );
            } catch (\Throwable $e) {
                Log::warning('Error al importar venta histórica', ['fila' => $fila, 'error' => $e->getMessage()]);
                $resultado['errores'][] = ['fila' => $fila, 'mensaje' => $e->getMessage()];
            }
        }

        $this->ajustarCorrelativos($branchId, $maximos);

        return $resultado;
    }

    /**
     * Avanza el correlativo de cada serie hasta el último número importado, para
     * que la próxima emisión continúe después del histórico y no colisione.
     *
     * @param  array<string, array<string, int>>  $maximos
     */
    private function ajustarCorrelativos(int $branchId, array $maximos): void
    {
        foreach ($maximos as $tipoDoc => $series) {
            foreach ($series as $serie => $ultimo) {
                $correlativo = Correlative::firstOrCreate(
                    ['branch_id' => $branchId, 'tipo_documento' => $tipoDoc, 'serie' => $serie],
                    ['correlativo_actual' => 0],
                );

                if ($ultimo > (int) $correlativo->correlativo_actual) {
                    $correlativo->update(['correlativo_actual' => $ultimo]);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $campos
     * @return array{0:bool, 1:\Illuminate\Database\Eloquent\Model}
     */
    private function guardarVenta(array $campos, Company $company, int $branchId): array
    {
        $tipoDoc = $this->campo($campos, 5);
        $serie = $this->campo($campos, 6);
        $correlativo = $this->campo($campos, 7);
        $numeroCompleto = trim($serie . '-' . $correlativo, '-');

        $client = $this->resolverCliente(
            $company,
            $this->campo($campos, 9) ?: '6',
            $this->campo($campos, 10),
            $this->campo($campos, 11),
        );

        $exportacion = $this->numero($campos, 12);
        $gravada = $this->numero($campos, 13);
        $igv = $this->numero($campos, 15);
        $exonerada = $this->numero($campos, 17);
        $inafecta = $this->numero($campos, 18);
        $isc = $this->numero($campos, 19);
        $otrosTributos = $this->numero($campos, 22);
        $total = $this->numero($campos, 23);

        $comun = [
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'client_id' => $client->id,
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numeroCompleto,
            'fecha_emision' => $this->fecha($this->campo($campos, 3)),
            'fecha_vencimiento' => $this->fechaONull($this->campo($campos, 4)),
            'moneda' => $this->campo($campos, 24) ?: 'PEN',
            'tipo_operacion' => '0101',
            'valor_venta' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
            'mto_oper_gravadas' => $gravada,
            'mto_oper_exoneradas' => $exonerada,
            'mto_oper_inafectas' => $inafecta,
            'mto_oper_exportacion' => $exportacion,
            'mto_igv' => $igv,
            'mto_isc' => $isc,
            'mto_otros_tributos' => $otrosTributos,
            'total_impuestos' => round($igv + $isc + $otrosTributos, 2),
            'sub_total' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
            'mto_imp_venta' => $total,
            'detalles' => [[
                'codigo' => 'HIST',
                'descripcion' => 'Importado del PLE 14.1 (histórico)',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
            ]],
            'estado_sunat' => $this->estadoPle($this->campo($campos, 33)),
            'usuario_creacion' => 'importacion-historica',
        ];

        $clase = $tipoDoc === '03' ? Boleta::class : Invoice::class;

        $existente = $clase::where('company_id', $company->id)
            ->where('serie', $serie)
            ->where('correlativo', $correlativo)
            ->first();

        if ($existente) {
            $existente->update($comun);

            return [false, $existente];
        }

        return [true, $clase::create($comun + ['tipo_documento' => $tipoDoc])];
    }

    /**
     * Importa las líneas del Registro de Compras 8.1.
     *
     * @param  array<int, array<int, string>>  $lineas
     */
    public function importarComprasPle(array $lineas, Company $company, ?int $branchId): array
    {
        $resultado = $this->resultadoVacio('compras', count($lineas));

        foreach ($lineas as $indice => $campos) {
            $fila = $indice + 1;

            try {
                $tipoDoc = $this->campo($campos, 5) ?: '01';
                $serie = $this->campo($campos, 6);
                $correlativo = $this->campo($campos, 8);
                $proveedorNum = $this->campo($campos, 11);

                if ($proveedorNum === '') {
                    $resultado['omitidos'][] = ['fila' => $fila, 'motivo' => 'La línea no tiene RUC de proveedor.'];
                    continue;
                }

                // La base y el IGV mixtos y de operaciones no gravadas se suman a
                // sus buckets principales para no perder el importe en el libro.
                $gravada = $this->numero($campos, 13) + $this->numero($campos, 15) + $this->numero($campos, 17);
                $igv = $this->numero($campos, 14) + $this->numero($campos, 16) + $this->numero($campos, 18);

                $datos = [
                    'company_id' => $company->id,
                    'tipo_documento' => $tipoDoc,
                    'serie' => $serie,
                    'correlativo' => $correlativo,
                    'numero_completo' => trim($serie . '-' . $correlativo, '-'),
                    'fecha_emision' => $this->fecha($this->campo($campos, 3)),
                    'fecha_vencimiento' => $this->fechaONull($this->campo($campos, 4)),
                    'moneda' => $this->campo($campos, 23) ?: 'PEN',
                    'tipo_cambio' => $this->numero($campos, 24) ?: 1,
                    'proveedor_tipo_doc' => $this->campo($campos, 10) ?: '6',
                    'proveedor_num_doc' => $proveedorNum,
                    'proveedor_razon_social' => $this->campo($campos, 12) ?: 'PROVEEDOR',
                    'mto_oper_gravadas' => $gravada,
                    'mto_oper_exoneradas' => $this->numero($campos, 19),
                    'mto_igv' => $igv,
                    'mto_isc' => $this->numero($campos, 20),
                    'mto_otros_tributos' => $this->numero($campos, 21),
                    'mto_imp_venta' => $this->numero($campos, 22),
                    'estado_sunat' => $this->estadoPle($this->campo($campos, 40)),
                ];

                $existente = Compra::where('company_id', $company->id)
                    ->where('proveedor_num_doc', $proveedorNum)
                    ->where('tipo_documento', $tipoDoc)
                    ->where('serie', $serie)
                    ->where('correlativo', $correlativo)
                    ->first();

                if ($existente) {
                    $existente->update($datos);
                    $resultado['actualizados']++;
                } else {
                    Compra::create($datos);
                    $resultado['importados']++;
                }
            } catch (\Throwable $e) {
                Log::warning('Error al importar compra histórica', ['fila' => $fila, 'error' => $e->getMessage()]);
                $resultado['errores'][] = ['fila' => $fila, 'mensaje' => $e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * Importa desde la plantilla CSV/XLSX. La cabecera debe incluir una columna
     * 'tipo_registro' (ventas|compras); el resto de columnas son las mismas del
     * modelo correspondiente.
     */
    private function procesarPlantilla(string $ruta, Company $company, ?int $branchId, ?string $extension): array
    {
        $filas = $this->reader->leer($ruta, $extension);
        if ($filas === []) {
            throw new RuntimeException('El archivo no contiene datos.');
        }

        $cabecera = array_map(fn ($c) => strtolower(trim((string) $c)), array_shift($filas));
        $resultado = $this->resultadoVacio('mixto', count($filas));
        $branchId ??= $this->resolverSucursal($company, null);
        $maximos = [];

        foreach ($filas as $indice => $celdas) {
            $fila = $indice + 2; // +2 por la cabecera y el índice base 0
            $mapa = [];
            foreach ($cabecera as $pos => $col) {
                if ($col !== '') {
                    $mapa[$col] = trim((string) ($celdas[$pos] ?? ''));
                }
            }

            try {
                $tipoRegistro = $mapa['tipo_registro'] ?? '';
                if ($tipoRegistro === 'ventas') {
                    [$creado] = $this->guardarVentaDeMapa($mapa, $company, $branchId);
                    $tipoDoc = $mapa['tipo_documento'] ?? '01';
                    $serie = $mapa['serie'] ?? '';
                    $maximos[$tipoDoc][$serie] = max(
                        $maximos[$tipoDoc][$serie] ?? 0,
                        (int) ($mapa['correlativo'] ?? 0),
                    );
                } elseif ($tipoRegistro === 'compras') {
                    $creado = $this->guardarCompraDeMapa($mapa, $company);
                } else {
                    $resultado['omitidos'][] = ['fila' => $fila, 'motivo' => "tipo_registro inválido: '{$tipoRegistro}'."];
                    continue;
                }

                $resultado[$creado ? 'importados' : 'actualizados']++;
            } catch (\Throwable $e) {
                $resultado['errores'][] = ['fila' => $fila, 'mensaje' => $e->getMessage()];
            }
        }

        $this->ajustarCorrelativos($branchId, $maximos);

        return $resultado;
    }

    /**
     * @param  array<string,string>  $mapa
     * @return array{0:bool, 1:\Illuminate\Database\Eloquent\Model}
     */
    private function guardarVentaDeMapa(array $mapa, Company $company, int $branchId): array
    {
        $tipoDoc = $mapa['tipo_documento'] ?? '01';
        if (! in_array($tipoDoc, ['01', '03'], true)) {
            throw new RuntimeException("Tipo de documento {$tipoDoc} no soportado (solo 01 y 03).");
        }

        $client = $this->resolverCliente(
            $company,
            $mapa['cliente_tipo_doc'] ?? '6',
            $mapa['cliente_num_doc'] ?? '',
            $mapa['cliente_razon_social'] ?? '',
        );

        $gravada = (float) ($mapa['mto_oper_gravadas'] ?? 0);
        $exonerada = (float) ($mapa['mto_oper_exoneradas'] ?? 0);
        $inafecta = (float) ($mapa['mto_oper_inafectas'] ?? 0);
        $exportacion = (float) ($mapa['mto_oper_exportacion'] ?? 0);
        $igv = (float) ($mapa['mto_igv'] ?? 0);

        $comun = [
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'client_id' => $client->id,
            'serie' => $mapa['serie'] ?? '',
            'correlativo' => $mapa['correlativo'] ?? '',
            'numero_completo' => trim(($mapa['serie'] ?? '') . '-' . ($mapa['correlativo'] ?? ''), '-'),
            'fecha_emision' => $this->fecha($mapa['fecha_emision'] ?? ''),
            'moneda' => $mapa['moneda'] ?? 'PEN',
            'tipo_operacion' => '0101',
            'valor_venta' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
            'mto_oper_gravadas' => $gravada,
            'mto_oper_exoneradas' => $exonerada,
            'mto_oper_inafectas' => $inafecta,
            'mto_oper_exportacion' => $exportacion,
            'mto_igv' => $igv,
            'mto_isc' => (float) ($mapa['mto_isc'] ?? 0),
            'mto_otros_tributos' => (float) ($mapa['mto_otros_tributos'] ?? 0),
            'total_impuestos' => round($igv + (float) ($mapa['mto_isc'] ?? 0) + (float) ($mapa['mto_otros_tributos'] ?? 0), 2),
            'sub_total' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
            'mto_imp_venta' => (float) ($mapa['mto_imp_venta'] ?? round($gravada + $exonerada + $inafecta + $exportacion + $igv, 2)),
            'detalles' => [[
                'codigo' => 'HIST',
                'descripcion' => 'Importado del histórico (plantilla)',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'mto_valor_unitario' => round($gravada + $exonerada + $inafecta + $exportacion, 2),
                'porcentaje_igv' => 18,
                'tip_afe_igv' => '10',
            ]],
            'estado_sunat' => $mapa['estado_sunat'] ?? 'ACEPTADO',
            'usuario_creacion' => 'importacion-historica',
        ];

        $clase = $tipoDoc === '03' ? Boleta::class : Invoice::class;
        $existente = $clase::where('company_id', $company->id)
            ->where('serie', $comun['serie'])
            ->where('correlativo', $comun['correlativo'])
            ->first();

        if ($existente) {
            $existente->update($comun);

            return [false, $existente];
        }

        return [true, $clase::create($comun + ['tipo_documento' => $tipoDoc])];
    }

    /**
     * @param  array<string,string>  $mapa
     */
    private function guardarCompraDeMapa(array $mapa, Company $company): bool
    {
        $datos = [
            'company_id' => $company->id,
            'tipo_documento' => $mapa['tipo_documento'] ?? '01',
            'serie' => $mapa['serie'] ?? '',
            'correlativo' => $mapa['correlativo'] ?? '',
            'numero_completo' => trim(($mapa['serie'] ?? '') . '-' . ($mapa['correlativo'] ?? ''), '-'),
            'fecha_emision' => $this->fecha($mapa['fecha_emision'] ?? ''),
            'moneda' => $mapa['moneda'] ?? 'PEN',
            'tipo_cambio' => (float) ($mapa['tipo_cambio'] ?? 1) ?: 1,
            'proveedor_tipo_doc' => $mapa['proveedor_tipo_doc'] ?? '6',
            'proveedor_num_doc' => $mapa['proveedor_num_doc'] ?? '',
            'proveedor_razon_social' => $mapa['proveedor_razon_social'] ?? 'PROVEEDOR',
            'mto_oper_gravadas' => (float) ($mapa['mto_oper_gravadas'] ?? 0),
            'mto_oper_exoneradas' => (float) ($mapa['mto_oper_exoneradas'] ?? 0),
            'mto_oper_inafectas' => (float) ($mapa['mto_oper_inafectas'] ?? 0),
            'mto_igv' => (float) ($mapa['mto_igv'] ?? 0),
            'mto_isc' => (float) ($mapa['mto_isc'] ?? 0),
            'mto_otros_tributos' => (float) ($mapa['mto_otros_tributos'] ?? 0),
            'mto_imp_venta' => (float) ($mapa['mto_imp_venta'] ?? 0),
            'estado_sunat' => $mapa['estado_sunat'] ?? 'ACEPTADO',
        ];

        if ($datos['proveedor_num_doc'] === '') {
            throw new RuntimeException('Falta proveedor_num_doc.');
        }

        $existente = Compra::where('company_id', $company->id)
            ->where('proveedor_num_doc', $datos['proveedor_num_doc'])
            ->where('tipo_documento', $datos['tipo_documento'])
            ->where('serie', $datos['serie'])
            ->where('correlativo', $datos['correlativo'])
            ->first();

        if ($existente) {
            $existente->update($datos);

            return false;
        }

        Compra::create($datos);

        return true;
    }

    private function resolverCliente(Company $company, string $tipoDoc, string $numDoc, string $razon): Client
    {
        $numDoc = trim($numDoc);
        if ($numDoc === '') {
            throw new RuntimeException('El comprobante no tiene número de documento del cliente.');
        }

        return Client::firstOrCreate(
            ['tipo_documento' => $tipoDoc, 'numero_documento' => $numDoc],
            ['company_id' => $company->id, 'razon_social' => $razon !== '' ? $razon : 'CLIENTE VARIOS'],
        );
    }

    private function resolverSucursal(Company $company, ?int $branchId): int
    {
        if ($branchId) {
            return $branchId;
        }

        $id = Branch::where('company_id', $company->id)->value('id');
        if (! $id) {
            throw new RuntimeException('La empresa no tiene sucursales; crea una antes de importar.');
        }

        return $id;
    }

    /**
     * @param  array<int, string>  $campos
     */
    private function campo(array $campos, int $indice): string
    {
        return trim((string) ($campos[$indice] ?? ''));
    }

    /**
     * @param  array<int, string>  $campos
     */
    private function numero(array $campos, int $indice): float
    {
        $valor = $this->campo($campos, $indice);
        if ($valor === '') {
            return 0.0;
        }

        if (str_contains($valor, ',') && str_contains($valor, '.')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        } elseif (str_contains($valor, ',')) {
            $valor = str_replace(',', '.', $valor);
        }

        return (float) $valor;
    }

    private function fecha(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            throw new RuntimeException('El comprobante no tiene fecha de emisión.');
        }

        // El PLE usa DD/MM/AAAA; también se acepta AAAA-MM-DD.
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $valor, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return Carbon::parse($valor)->toDateString();
    }

    private function fechaONull(string $valor): ?string
    {
        return trim($valor) === '' ? null : $this->fecha($valor);
    }

    private function estadoPle(string $valor): string
    {
        return $valor === '0' ? 'ANULADO' : 'ACEPTADO';
    }

    /**
     * @return array{tipo:string, total:int, importados:int, actualizados:int, omitidos:array<int,array<string,mixed>>, errores:array<int,array<string,mixed>>}
     */
    private function resultadoVacio(string $tipo, int $total): array
    {
        return [
            'tipo' => $tipo,
            'total' => $total,
            'importados' => 0,
            'actualizados' => 0,
            'omitidos' => [],
            'errores' => [],
        ];
    }
}
