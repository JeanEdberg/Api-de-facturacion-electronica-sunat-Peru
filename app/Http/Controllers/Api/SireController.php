<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Propuesta SIRE (RVIE/RCE) por periodo y repositorio de propuestas archivadas.
 *
 * Cada propuesta se guarda por (empresa, libro, periodo): volver a generarla o
 * subirla reemplaza la anterior, de modo que no se duplican propuestas del mismo
 * mes.
 */
class SireController extends Controller
{
    public function __construct(private SireService $service)
    {
    }

    /**
     * Descarga la propuesta del periodo. Con `preview=1` devuelve JSON.
     */
    public function descargar(Request $request, string $libro): Response|JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'periodo' => ['required', 'string', 'regex:/^\d{4}-?\d{2}$/'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $propuesta = $this->service->generarYArchivar($companyId, $data['periodo'], $libro);

        if ($request->boolean('preview')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'libro' => $propuesta->libro,
                    'periodo' => $propuesta->periodo,
                    'registros' => $propuesta->registros,
                    'filename' => $propuesta->nombre,
                    'contenido' => $propuesta->contenido,
                ],
            ]);
        }

        // El TXT del SIRE se entrega en windows-1252 (latin-1) como en SUNAT.
        $bytes = mb_convert_encoding($propuesta->contenido, 'Windows-1252', 'UTF-8');

        return response($bytes, 200, [
            'Content-Type' => 'text/plain; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="'.$propuesta->nombre.'"',
        ]);
    }

    /**
     * Sube (archiva) una propuesta descargada del portal del SIRE.
     */
    public function subir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'libro' => ['required', 'in:ventas,compras'],
            'periodo' => ['required', 'string', 'regex:/^\d{4}-?\d{2}$/'],
            'contenido' => ['required', 'string'],
            'nombre' => ['nullable', 'string'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $periodo = $this->service->normalizarPeriodo($data['periodo']);
        $registros = $this->contarRegistros($data['contenido']);

        $propuesta = $this->service->archivar(
            $companyId,
            $data['libro'],
            $periodo,
            $data['contenido'],
            $registros,
            'manual',
            $data['nombre'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => "Propuesta archivada ({$registros} registros).",
            'data' => $propuesta,
        ]);
    }

    /**
     * Lista las propuestas archivadas de la empresa.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'libro' => ['nullable', 'in:ventas,compras'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $propuestas = $this->service->listar($companyId, $data['libro'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $propuestas->map(fn ($p) => [
                'libro' => $p->libro,
                'periodo' => $p->periodo,
                'origen' => $p->origen,
                'nombre' => $p->nombre,
                'registros' => $p->registros,
                'actualizado_en' => $p->updated_at,
            ])->values(),
        ]);
    }

    /**
     * Devuelve el contenido archivado de un periodo.
     */
    public function show(Request $request, string $libro, string $periodo): JsonResponse
    {
        $companyId = $this->companyId($request, $request->integer('company_id') ?: null);
        $propuesta = $this->service->obtener($companyId, $libro, $periodo);

        if (! $propuesta) {
            return response()->json([
                'success' => false,
                'message' => 'No hay propuesta archivada para ese periodo.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $propuesta]);
    }

    private function companyId(Request $request, ?int $companyId): int
    {
        if ($companyId) {
            return $companyId;
        }

        return (int) ($request->user()?->company_id ?? 1);
    }

    private function contarRegistros(string $contenido): int
    {
        $lineas = preg_split('/\r?\n/', $contenido);

        return count(array_filter($lineas, fn ($l) => trim($l) !== ''));
    }
}
