<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConsultaDocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultaDocumentoController extends Controller
{
    public function __construct(private ConsultaDocumentoService $service)
    {
    }

    /**
     * Consulta un RUC o DNI para autocompletar formularios.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_documento' => ['required', 'in:1,6'],
            'numero_documento' => ['required', 'string', 'min:8', 'max:11'],
        ]);

        $resultado = $this->service->consultar($data['tipo_documento'], $data['numero_documento']);

        return response()->json($resultado);
    }
}
