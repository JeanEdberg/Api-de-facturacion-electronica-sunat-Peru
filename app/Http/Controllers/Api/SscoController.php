<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SscoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SscoController extends Controller
{
    public function __construct(private SscoService $service)
    {
    }

    /**
     * Consulta si un RUC figura en el padrón SSCO de la SUNAT.
     */
    public function consultar(Request $request, string $ruc): JsonResponse
    {
        $request->validate(['ruc' => ['sometimes', 'string']]);

        $resultado = $this->service->consultar($ruc);

        return response()->json([
            'success' => true,
            'data' => $resultado,
        ]);
    }

    /**
     * Consulta en lote un conjunto de RUC/DNI contra el padrón.
     */
    public function lote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rucs' => ['required', 'array', 'min:1', 'max:500'],
            'rucs.*' => ['string'],
        ]);

        $mapa = $this->service->consultarLote($data['rucs']);

        return response()->json([
            'success' => true,
            'data' => [
                'consultados' => count($data['rucs']),
                'ssco' => $mapa,
            ],
        ]);
    }

    /**
     * Estado de la caché local del padrón.
     */
    public function estado(): JsonResponse
    {
        $total = \App\Models\SscoPadron::count();
        $ultima = \App\Models\SscoPadron::max('updated_at');

        return response()->json([
            'success' => true,
            'data' => [
                'registros' => $total,
                'actualizado_en' => $ultima,
                'fuente' => SscoService::PAGINA,
                'archivo' => SscoService::ARCHIVO,
            ],
        ]);
    }

    /**
     * Descarga el padrón oficial de SUNAT y refresca la caché local.
     */
    public function refrescar(): JsonResponse
    {
        try {
            $resultado = $this->service->sincronizar();

            return response()->json([
                'success' => true,
                'message' => "Padrón SSCO actualizado ({$resultado['registros']} registros).",
                'data' => $resultado,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al refrescar el padrón SSCO: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar el padrón SSCO.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Carga un .xlsx del padrón subido manualmente (respaldo cuando SUNAT no responde).
     */
    public function subir(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        try {
            $path = $request->file('archivo')->getRealPath();
            $resultado = $this->service->sincronizar($path);

            return response()->json([
                'success' => true,
                'message' => "Padrón SSCO cargado ({$resultado['registros']} registros).",
                'data' => ['registros' => $resultado['registros']],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al subir el padrón SSCO: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo procesar el archivo del padrón SSCO.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
