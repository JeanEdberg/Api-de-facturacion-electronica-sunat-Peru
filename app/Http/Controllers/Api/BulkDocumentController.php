<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\BulkDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkDocumentController extends Controller
{
    public function __construct(private BulkDocumentService $service) {}

    /**
     * POST /api/v1/bulk/documentos
     *
     * multipart: archivo (csv|xlsx), company_id, branch_id (opcional),
     *            enviar_sunat (opcional, "1" para enviar tras emitir)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'archivo' => 'required|file|max:10240',
            'enviar_sunat' => 'nullable|boolean',
        ]);

        $company = Company::find($request->integer('company_id'));

        if (! $company || ! $request->user()->canAccessCompany($company->id)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para acceder a esta empresa.',
            ], 403);
        }

        $archivo = $request->file('archivo');
        $extension = strtolower($archivo->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'xlsx', 'txt'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato no soportado. Sube un archivo CSV o XLSX.',
            ], 422);
        }

        try {
            $resultado = $this->service->procesar(
                $archivo->getRealPath(),
                $company,
                $request->filled('branch_id') ? $request->integer('branch_id') : null,
                $request->boolean('enviar_sunat'),
                $extension,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 500);
        }

        $emitidos = count($resultado['emitidos']);
        $errores = count($resultado['errores']);

        return response()->json([
            'success' => $errores === 0,
            'message' => $this->mensaje($emitidos, $errores),
            'data' => $resultado,
        ], $errores === 0 ? 201 : 207);
    }

    /**
     * GET /api/v1/bulk/plantilla
     *
     * Devuelve la plantilla CSV de carga masiva (con cabecera y una fila de
     * ejemplo) para que el usuario solo la rellene.
     */
    public function plantilla(): StreamedResponse
    {
        $cabecera = [
            'grupo', 'tipo_documento', 'serie', 'fecha_emision', 'fecha_vencimiento',
            'moneda', 'forma_pago_tipo', 'cliente_tipo_doc', 'cliente_num_doc',
            'cliente_razon_social', 'cliente_direccion', 'cliente_email',
            'item_codigo', 'item_descripcion', 'item_unidad', 'item_cantidad',
            'item_valor_unitario', 'item_porcentaje_igv', 'item_tip_afe_igv',
        ];

        $ejemplo = [
            '1', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '44556677',
            'JUAN PEREZ', 'AV SIEMPRE VIVA 123', 'juan@correo.com',
            'P001', 'Gaseosa 500ml', 'NIU', '3', '2.50', '18', '10',
        ];

        $nombre = 'plantilla-carga-masiva.csv';

        return response()->streamDownload(function () use ($cabecera, $ejemplo) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $cabecera, ';');
            fputcsv($salida, $ejemplo, ';');
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function mensaje(int $emitidos, int $errores): string
    {
        if ($emitidos === 0 && $errores === 0) {
            return 'El archivo no contenía comprobantes para emitir.';
        }

        if ($errores === 0) {
            return "Se emitieron {$emitidos} comprobante(s) correctamente.";
        }

        return "Se emitieron {$emitidos} comprobante(s) y {$errores} grupo(s) presentaron errores.";
    }
}
