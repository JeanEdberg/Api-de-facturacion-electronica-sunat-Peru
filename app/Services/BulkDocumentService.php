<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Emisión masiva de comprobantes desde una hoja de cálculo.
 *
 * Cada fila representa un ítem. Las filas que comparten el mismo grupo
 * (columna "grupo" o, si no viene, la combinación tipo+serie+fecha+cliente)
 * se consolidan en un solo comprobante con varias líneas.
 *
 * El servicio nunca deja a medias un lote: cada comprobante se emite en su
 * propia transacción y los que fallan se reportan con su número de fila, de
 * modo que el usuario pueda corregir solo esas filas y reenviar.
 */
class BulkDocumentService
{
    /** Columnas aceptadas en la plantilla (cabecera → clave interna). */
    public const COLUMNAS = [
        'grupo',
        'tipo_documento',
        'serie',
        'fecha_emision',
        'fecha_vencimiento',
        'moneda',
        'forma_pago_tipo',
        'cliente_tipo_doc',
        'cliente_num_doc',
        'cliente_razon_social',
        'cliente_direccion',
        'cliente_email',
        'item_codigo',
        'item_descripcion',
        'item_unidad',
        'item_cantidad',
        'item_valor_unitario',
        'item_porcentaje_igv',
        'item_tip_afe_igv',
    ];

    public function __construct(
        private DocumentService $documentService,
        private SpreadsheetReader $reader,
    ) {}

    /**
     * Procesa el archivo y emite los comprobantes.
     *
     * @return array{
     *     total_filas:int,
     *     total_comprobantes:int,
     *     emitidos:array<int,array<string,mixed>>,
     *     errores:array<int,array<string,mixed>>
     * }
     */
    public function procesar(string $ruta, Company $company, ?int $branchId = null, bool $enviarSunat = false, ?string $extension = null): array
    {
        $filas = $this->reader->leer($ruta, $extension);
        [$cabecera, $registros] = $this->separarCabecera($filas);

        if ($cabecera === []) {
            return [
                'total_filas' => 0,
                'total_comprobantes' => 0,
                'emitidos' => [],
                'errores' => [['fila' => 1, 'mensaje' => 'La plantilla no tiene cabecera reconocible.']],
            ];
        }

        $grupos = $this->agrupar($cabecera, $registros);

        $emitidos = [];
        $errores = [];

        foreach ($grupos as $grupo) {
            $resultado = $this->emitirGrupo($grupo, $company, $branchId, $enviarSunat);

            if ($resultado['ok']) {
                $emitidos[] = $resultado['documento'];
            } else {
                $errores[] = $resultado['error'];
            }
        }

        return [
            'total_filas' => count($registros),
            'total_comprobantes' => count($grupos),
            'emitidos' => $emitidos,
            'errores' => $errores,
        ];
    }

    /**
     * Identifica la fila de cabecera y devuelve las filas de datos numeradas.
     *
     * @param  array<int, array<int, string>>  $filas
     * @return array{0: array<int,string>, 1: array<int, array{fila:int, celdas:array<int,string>}>}
     */
    private function separarCabecera(array $filas): array
    {
        foreach ($filas as $indice => $fila) {
            $normalizada = array_map(fn ($c) => strtolower(trim($c)), $fila);
            if (in_array('tipo_documento', $normalizada, true)) {
                $registros = [];
                foreach (array_slice($filas, $indice + 1, null, true) as $i => $datos) {
                    $registros[] = ['fila' => $i + 1, 'celdas' => $datos];
                }

                return [$normalizada, $registros];
            }
        }

        return [[], []];
    }

    /**
     * @param  array<int,string>  $cabecera
     * @param  array<int, array{fila:int, celdas:array<int,string>}>  $registros
     * @return array<int, array{clave:string, filas:array<int,int>, datos:array<string,mixed>, items:array<int,array<string,mixed>>}>
     */
    private function agrupar(array $cabecera, array $registros): array
    {
        $grupos = [];

        foreach ($registros as $registro) {
            $mapa = $this->mapearFila($cabecera, $registro['celdas']);
            $clave = $this->claveDeGrupo($mapa);

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'clave' => $clave,
                    'filas' => [],
                    'datos' => $mapa,
                    'items' => [],
                ];
            }

            $grupos[$clave]['filas'][] = $registro['fila'];
            $grupos[$clave]['items'][] = $this->itemDeFila($mapa, $registro['fila']);
        }

        return array_values($grupos);
    }

    /**
     * @param  array<int,string>  $cabecera
     * @param  array<int,string>  $celdas
     * @return array<string,string>
     */
    private function mapearFila(array $cabecera, array $celdas): array
    {
        $mapa = [];
        foreach ($cabecera as $posicion => $columna) {
            if ($columna === '') {
                continue;
            }
            $mapa[$columna] = trim((string) ($celdas[$posicion] ?? ''));
        }

        return $mapa;
    }

    private function claveDeGrupo(array $mapa): string
    {
        if (! empty($mapa['grupo'])) {
            return 'g:' . $mapa['grupo'];
        }

        return implode('|', [
            'd',
            $mapa['tipo_documento'] ?? '',
            $mapa['serie'] ?? '',
            $mapa['fecha_emision'] ?? '',
            $mapa['cliente_num_doc'] ?? '',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function itemDeFila(array $mapa, int $fila): array
    {
        return [
            'fila' => $fila,
            'codigo' => $mapa['item_codigo'] ?? '',
            'descripcion' => $mapa['item_descripcion'] ?? '',
            'unidad' => $mapa['item_unidad'] ?: 'NIU',
            'cantidad' => $this->aNumero($mapa['item_cantidad'] ?? ''),
            'mto_valor_unitario' => $this->aNumero($mapa['item_valor_unitario'] ?? ''),
            'porcentaje_igv' => $mapa['item_porcentaje_igv'] === '' ? 18.00 : $this->aNumero($mapa['item_porcentaje_igv']),
            'tip_afe_igv' => $mapa['item_tip_afe_igv'] ?: '10',
        ];
    }

    /**
     * @param  array{clave:string, filas:array<int,int>, datos:array<string,string>, items:array<int,array<string,mixed>>}  $grupo
     * @return array{ok:bool, documento?:array<string,mixed>, error?:array<string,mixed>}
     */
    private function emitirGrupo(array $grupo, Company $company, ?int $branchId, bool $enviarSunat): array
    {
        $datos = $grupo['datos'];
        $filas = $grupo['filas'];

        $payload = $this->construirPayload($datos, $grupo['items'], $company, $branchId);
        $payload['company_id'] = $company->id;

        $validador = Validator::make($payload, $this->reglas());

        if ($validador->fails()) {
            return [
                'ok' => false,
                'error' => [
                    'fila' => $filas[0],
                    'filas' => $filas,
                    'mensaje' => 'Datos inválidos: ' . implode(' | ', $validador->errors()->all()),
                ],
            ];
        }

        try {
            $documento = DB::transaction(function () use ($payload, $datos) {
                $tipo = $payload['tipo_documento'];

                return match ($tipo) {
                    '01' => $this->documentService->createInvoice($payload),
                    '03' => $this->documentService->createBoleta($payload),
                    default => throw new \InvalidArgumentException("Tipo de documento no soportado en carga masiva: {$tipo}."),
                };
            });
        } catch (\Throwable $e) {
            Log::error('Error en carga masiva', ['filas' => $filas, 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'error' => [
                    'fila' => $filas[0],
                    'filas' => $filas,
                    'mensaje' => $e->getMessage(),
                ],
            ];
        }

        $enviado = null;
        if ($enviarSunat) {
            $tipoEnvio = $payload['tipo_documento'] === '01' ? 'invoice' : 'boleta';
            $envio = $this->documentService->sendToSunat($documento, $tipoEnvio);
            $documento = $envio['document'] ?? $documento;
            $enviado = (bool) ($envio['success'] ?? false);
        }

        return [
            'ok' => true,
            'documento' => [
                'id' => $documento->id,
                'tipo_documento' => $documento->tipo_documento,
                'numero_completo' => $documento->numero_completo,
                'estado_sunat' => $documento->estado_sunat,
                'enviado_sunat' => $enviado,
                'fila_inicial' => $filas[0],
            ],
        ];
    }

    /**
     * @param  array<string,string>  $datos
     * @param  array<int, array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function construirPayload(array $datos, array $items, Company $company, ?int $branchId): array
    {
        $branchId ??= $this->resolverSucursal($company);

        return [
            'branch_id' => $branchId,
            'tipo_documento' => $datos['tipo_documento'] ?? '',
            'serie' => $datos['serie'] ?? '',
            'fecha_emision' => $datos['fecha_emision'] ?? '',
            'fecha_vencimiento' => $datos['fecha_vencimiento'] ?: null,
            'moneda' => strtoupper($datos['moneda'] ?: 'PEN'),
            'forma_pago_tipo' => $datos['forma_pago_tipo'] ?: 'Contado',
            'tipo_operacion' => '0101',
            'client' => [
                'tipo_documento' => $datos['cliente_tipo_doc'] ?: '6',
                'numero_documento' => $datos['cliente_num_doc'] ?? '',
                'razon_social' => $datos['cliente_razon_social'] ?? '',
                'direccion' => $datos['cliente_direccion'] ?: null,
                'email' => $datos['cliente_email'] ?: null,
            ],
            'detalles' => array_map(function (array $item) {
                unset($item['fila']);

                return $item;
            }, $items),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reglas(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            'tipo_documento' => 'required|in:01,03',
            'serie' => 'required|string|max:4',
            'fecha_emision' => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha_emision',
            'moneda' => 'required|in:PEN,USD',
            'forma_pago_tipo' => 'required|in:Contado,Credito',
            'client.tipo_documento' => 'required|in:1,4,6,0',
            'client.numero_documento' => 'required|string|max:15',
            'client.razon_social' => 'required|string|max:255',
            'detalles' => 'required|array|min:1',
            'detalles.*.codigo' => 'required|string|max:50',
            'detalles.*.descripcion' => 'required|string|max:500',
            'detalles.*.unidad' => 'required|string|max:3',
            'detalles.*.cantidad' => 'required|numeric|min:0.001',
            'detalles.*.mto_valor_unitario' => 'required|numeric|min:0',
            'detalles.*.porcentaje_igv' => 'required|numeric|min:0|max:100',
            'detalles.*.tip_afe_igv' => 'required|string|in:10,11,12,13,14,15,16,17,20,21,30,31,32,33,34,35,36,40',
        ];
    }

    private function resolverSucursal(Company $company): ?int
    {
        return Branch::where('company_id', $company->id)->value('id');
    }

    private function aNumero(string $valor): float
    {
        $valor = trim($valor);
        if ($valor === '') {
            return 0.0;
        }

        // Acepta "1.234,56" (formato local) y "1234.56".
        if (str_contains($valor, ',') && str_contains($valor, '.')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        } elseif (str_contains($valor, ',')) {
            $valor = str_replace(',', '.', $valor);
        }

        return (float) $valor;
    }
}
