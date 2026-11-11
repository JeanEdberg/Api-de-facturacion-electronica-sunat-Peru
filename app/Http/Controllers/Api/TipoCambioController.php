<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TipoCambioService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoCambioController extends Controller
{
    public function __construct(private TipoCambioService $service) {}

    /**
     * GET /api/v1/tipo-cambio?fecha=2026-11-10
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => 'required|date_format:Y-m-d',
        ]);

        $fecha = $request->query('fecha');
        $cotizacion = $this->service->obtener($fecha);

        if ($cotizacion === null) {
            $esHoy = Carbon::parse($fecha)->isToday();

            return response()->json([
                'success' => false,
                'message' => $esHoy
                    ? 'SUNAT todavía no ha publicado el tipo de cambio de hoy.'
                    : 'No hay tipo de cambio registrado para esa fecha. SUNAT no expone histórico y solo se pudo capturar la tasa del día.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tipo de cambio obtenido correctamente.',
            'data' => $cotizacion,
        ]);
    }
}
