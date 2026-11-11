<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\HistoricalImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación de comprobantes de períodos anteriores (histórico).
 *
 * Acepta los archivos planos del PLE (Ventas 14.1 / Compras 8.1) y una plantilla
 * CSV/XLSX propia. Los comprobantes se registran sin enviarse a SUNAT, porque ya
 * fueron declarados en su momento.
 */
class HistoricalImportController extends Controller
{
    public function __construct(private HistoricalImportService $service) {}

    /**
     * POST /api/v1/historical/importar
     *
     * multipart: archivo (TXT PLE | CSV | XLSX), company_id, branch_id (opcional)
     */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'archivo' => 'required|file|max:20480',
        ]);

        $company = Company::find($request->integer('company_id'));

        if (! $company || ! $request->user()->canAccessCompany($company->id)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para acceder a esta empresa.',
            ], 403);
        }

        $archivo = $request->file('archivo');
        $extension = strtolower($archivo->getClientOriginalExtension() ?: 'txt');

        if (! in_array($extension, ['txt', 'csv', 'xlsx'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato no soportado. Sube un TXT del PLE, CSV o XLSX.',
            ], 422);
        }

        try {
            $resultado = $this->service->procesar(
                $archivo->getRealPath(),
                $company,
                $request->filled('branch_id') ? $request->integer('branch_id') : null,
                $extension,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 500);
        }

        $errores = count($resultado['errores']);

        return response()->json([
            'success' => $errores === 0,
            'message' => $this->mensaje($resultado),
            'data' => $resultado,
        ], $errores === 0 ? 201 : 207);
    }

    /**
     * GET /api/v1/historical/plantilla?tipo=ventas|compras
     *
     * Plantilla CSV para la importación histórica desde hoja de cálculo.
     */
    public function plantilla(Request $request): StreamedResponse
    {
        $tipo = $request->query('tipo') === 'compras' ? 'compras' : 'ventas';

        if ($tipo === 'compras') {
            $cabecera = [
                'tipo_registro', 'tipo_documento', 'serie', 'correlativo', 'fecha_emision',
                'moneda', 'tipo_cambio', 'proveedor_tipo_doc', 'proveedor_num_doc',
                'proveedor_razon_social', 'mto_oper_gravadas', 'mto_oper_exoneradas',
                'mto_oper_inafectas', 'mto_igv', 'mto_isc', 'mto_otros_tributos',
                'mto_imp_venta', 'estado_sunat',
            ];
            $ejemplo = [
                'compras', '01', 'F001', '0000123', '2024-03-15', 'PEN', '1', '6',
                '20123456789', 'PROVEEDOR SAC', '1000.00', '0', '0', '180.00', '0', '0',
                '1180.00', 'ACEPTADO',
            ];
        } else {
            $cabecera = [
                'tipo_registro', 'tipo_documento', 'serie', 'correlativo', 'fecha_emision',
                'moneda', 'cliente_tipo_doc', 'cliente_num_doc', 'cliente_razon_social',
                'mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas',
                'mto_oper_exportacion', 'mto_igv', 'mto_isc', 'mto_otros_tributos',
                'mto_imp_venta', 'estado_sunat',
            ];
            $ejemplo = [
                'ventas', '01', 'F001', '0000001', '2024-03-15', 'PEN', '6',
                '20556677889', 'CLIENTE SAC', '1000.00', '0', '0', '0', '180.00', '0', '0',
                '1180.00', 'ACEPTADO',
            ];
        }

        $nombre = "plantilla-historico-{$tipo}.csv";

        return response()->streamDownload(function () use ($cabecera, $ejemplo) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $cabecera, ';');
            fputcsv($salida, $ejemplo, ';');
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string,mixed>  $resultado
     */
    private function mensaje(array $resultado): string
    {
        $partes = [];
        if ($resultado['importados'] > 0) {
            $partes[] = "{$resultado['importados']} importado(s)";
        }
        if ($resultado['actualizados'] > 0) {
            $partes[] = "{$resultado['actualizados']} actualizado(s)";
        }
        if ($resultado['omitidos'] !== []) {
            $partes[] = count($resultado['omitidos']) . ' omitido(s)';
        }
        if ($resultado['errores'] !== []) {
            $partes[] = count($resultado['errores']) . ' con error';
        }

        return $partes === []
            ? 'El archivo no contenía comprobantes para importar.'
            : 'Importación histórica (' . $resultado['tipo'] . '): ' . implode(', ', $partes) . '.';
    }
}
