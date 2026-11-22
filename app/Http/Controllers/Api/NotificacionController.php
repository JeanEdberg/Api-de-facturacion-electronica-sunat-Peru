<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Notificacion;
use App\Services\BuzonService;
use App\Services\CertificadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buzón electrónico SUNAT / SUNAFIL.
 *
 * El listado conserva la forma de paginador de Laravel que consume la PWA
 * ({ data: { data: [...] } }) y añade `resumen` (pendientes y total) para el
 * encabezado del panel.
 */
class NotificacionController extends Controller
{
    public function __construct(
        private BuzonService $service,
        private CertificadoService $certificado,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'entidad' => ['nullable', 'string', 'in:SUNAT,SUNAFIL'],
            'estado' => ['nullable', 'string', 'in:pendiente,leida'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);

        $query = Notificacion::where('company_id', $companyId);
        if (! empty($data['entidad'])) {
            $query->where('entidad', $data['entidad']);
        }
        if (! empty($data['estado'])) {
            $query->where('estado', $data['estado']);
        }

        $company = Company::findOrFail($companyId);

        $paginador = $query->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'success' => true,
            'data' => $paginador,
            'resumen' => $this->service->resumen($companyId, $data['entidad'] ?? null),
            'alertas' => $this->alertas($companyId),
            'fuente' => $this->service->estadoFuente($company),
            'message' => 'Notificaciones obtenidas correctamente',
        ]);
    }

    /**
     * Alertas del buzón: hoy solo el vencimiento del certificado digital.
     *
     * @return array{certificado: array<string, mixed>}
     */
    private function alertas(int $companyId): array
    {
        $company = Company::find($companyId);

        return [
            'certificado' => $company
                ? $this->certificado->estado($company)
                : ['tiene' => false, 'vencido' => false, 'por_vencer' => false],
            'acuse' => $this->alertasDeAcuse($companyId),
        ];
    }

    /**
     * Notificaciones de SUNAFIL sin acuse a las que se les acaba el plazo.
     *
     * Es sólo un aviso: el acuse no lo registra el ERP, lo registra la persona
     * que abre la carta. Si la fecha estimada ya pasó, se dice también.
     */
    private function alertasDeAcuse(int $companyId): array
    {
        $aviso = (int) config('buzon.dias_aviso', 2);

        return Notificacion::query()
            ->where('company_id', $companyId)
            ->where('entidad', 'SUNAFIL')
            ->where('con_acuse', false)
            ->whereNotNull('ultimo_dia_acuse_manual')
            ->whereDate('ultimo_dia_acuse_manual', '<=', now()->addDays($aviso))
            ->orderBy('ultimo_dia_acuse_manual')
            ->get(['id', 'codigo', 'asunto', 'ultimo_dia_acuse_manual'])
            ->map(fn (Notificacion $n) => [
                'id' => $n->id,
                'codigo' => $n->codigo,
                'asunto' => $n->asunto,
                'ultimo_dia_acuse' => $n->ultimo_dia_acuse_manual?->format('Y-m-d'),
                'dias_restantes' => $n->diasParaAcuse(),
                'vencido' => $n->diasParaAcuse() < 0,
            ])
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entidad' => ['required', 'string', 'in:SUNAT,SUNAFIL'],
            'asunto' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'fecha' => ['nullable', 'date'],
            'detalle' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:500'],
        ]);

        $notificacion = $this->service->registrar($data);

        return response()->json([
            'success' => true,
            'data' => $notificacion,
            'message' => 'Notificación registrada correctamente',
        ], 201);
    }

    public function marcarLeida($id): JsonResponse
    {
        try {
            $notificacion = Notificacion::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada',
            ], 404);
        }

        $notificacion = $this->service->marcarLeida($notificacion);

        return response()->json([
            'success' => true,
            'data' => $notificacion,
            'message' => 'Notificación marcada como leída',
        ]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'entidad' => ['nullable', 'string', 'in:SUNAT,SUNAFIL'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $company = Company::findOrFail($companyId);
        $entidad = $data['entidad'] ?? null;

        try {
            $resultado = $this->service->sincronizar($company, $entidad);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $entidad
                    ? "No se pudo sincronizar el buzón {$entidad}"
                    : 'No se pudo sincronizar el buzón',
                'error' => $e->getMessage(),
            ], 502);
        }

        $sufijo = $entidad ? " de {$entidad}" : '';

        return response()->json([
            'success' => true,
            'data' => $resultado,
            'message' => $resultado['importadas'] > 0
                ? "Sincronizado{$sufijo}: {$resultado['importadas']} notificación(es)."
                : "Buzón{$sufijo} sincronizado: sin notificaciones nuevas.",
        ]);
    }

    /**
     * Guarda la fuente del buzón de la empresa (endpoints, token y credenciales
     * SOL). Los secretos vacíos conservan el valor actual, para que guardar solo
     * un endpoint no borre la Clave SOL ya configurada.
     */
    public function fuente(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'buzon_sunat_endpoint' => ['nullable', 'string', 'max:500'],
            'buzon_sunafil_endpoint' => ['nullable', 'string', 'max:500'],
            'buzon_token' => ['nullable', 'string', 'max:500'],
            'usuario_sol' => ['nullable', 'string', 'max:100'],
            'clave_sol' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $company = Company::findOrFail($companyId);

        $cambios = [
            'buzon_sunat_endpoint' => $data['buzon_sunat_endpoint'] ?? null,
            'buzon_sunafil_endpoint' => $data['buzon_sunafil_endpoint'] ?? null,
        ];

        if (array_key_exists('usuario_sol', $data) && filled($data['usuario_sol'])) {
            $cambios['usuario_sol'] = $data['usuario_sol'];
        }

        foreach (['buzon_token', 'clave_sol'] as $secreto) {
            if (filled($data[$secreto] ?? null)) {
                $cambios[$secreto] = $data[$secreto];
            }
        }

        $company->update($cambios);

        return response()->json([
            'success' => true,
            'data' => $this->service->estadoFuente($company->refresh()),
            'message' => 'Fuente del buzón guardada correctamente',
        ]);
    }

    /**
     * Prueba la fuente sin importar nada: el panel muestra si el endpoint, el
     * token y la Clave SOL realmente funcionan.
     */
    public function probarFuente(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'entidad' => ['nullable', 'string', 'in:SUNAT,SUNAFIL'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $company = Company::findOrFail($companyId);

        $resultado = $this->service->probar($company, $data['entidad'] ?? 'SUNAT');

        return response()->json([
            'success' => $resultado['conectado'],
            'conectado' => $resultado['conectado'],
            'total' => $resultado['total'],
            'message' => $resultado['message'],
        ], $resultado['conectado'] ? 200 : 502);
    }

    public function adjuntar(Request $request, Notificacion $notificacion): JsonResponse
    {
        abort_if(
            ! $request->user()?->canAccessCompany($notificacion->company_id),
            403,
            'No tienes acceso a esta empresa.'
        );

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $notificacion = $this->service->adjuntar($notificacion, $request->file('archivo'));

        return response()->json([
            'success' => true,
            'data' => $notificacion,
            'message' => 'Adjunto guardado correctamente',
        ]);
    }

    public function descargarAdjunto(Request $request, Notificacion $notificacion): JsonResponse|StreamedResponse
    {
        abort_if(
            ! $request->user()?->canAccessCompany($notificacion->company_id),
            403,
            'No tienes acceso a esta empresa.'
        );

        $tipo = (string) $request->query('tipo', 'carta');
        $ruta = $this->service->rutaAdjunto($notificacion, $tipo);

        // La carta NUNCA se baja aquí por iniciativa propia: pedirla a la
        // casilla registra el acuse de recibo y arranca un plazo legal. Sólo
        // se sirve si ya está guardada; para bajarla hay que usar /abrir.
        // Si la notificación no salió de la casilla, no hay nada que abrir.
        if (! $ruta && $tipo === 'carta' && $this->service->puedeAbrirse($notificacion)) {
            return response()->json([
                'success' => false,
                'message' => 'La carta aún no está abierta. Usa «Abrir y notificarse» para descargarla y registrar el acuse.',
                'requiere_apertura' => true,
            ], 409);
        }

        // Las constancias sí pueden bajarse bajo demanda: no tienen efecto legal.
        if (! $ruta) {
            $resultado = $this->service->descargarDocumento($notificacion, $tipo);

            if (! ($resultado['ok'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $resultado['message'] ?? 'La notificación no tiene adjunto',
                ], 404);
            }

            $ruta = $this->service->rutaAdjunto($notificacion->refresh(), $tipo);
        }

        if (! $ruta) {
            return response()->json([
                'success' => false,
                'message' => 'La notificación no tiene adjunto',
            ], 404);
        }

        return Storage::disk('public')->download($ruta);
    }

    /**
     * Descarga bajo demanda el documento de una notificación del buzón.
     *
     * Es el camino de las constancias de SUNAFIL, que no se bajan al
     * sincronizar porque no tienen plazo que responder.
     */
    /**
     * «Abrir y notificarse»: baja la carta y registra el acuse de recibo.
     *
     * Es la única vía que abre la carta. Exige permiso explícito y que el
     * usuario confirme, porque el acuse arranca un plazo legal contra la
     * empresa y no se puede deshacer desde el ERP.
     */
    public function abrir(Request $request, Notificacion $notificacion): JsonResponse
    {
        abort_if(
            ! $request->user()?->canAccessCompany($notificacion->company_id),
            403,
            'No tienes acceso a esta empresa.'
        );

        abort_unless(
            Gate::allows('abrir-notificacion-sunafil', $notificacion),
            403,
            'No tienes permiso para abrir notificaciones de SUNAFIL.'
        );

        $request->validate(['confirmo' => ['accepted']]);

        $resultado = $this->service->abrir($notificacion, (int) $request->user()->id);

        return response()->json([
            'success' => (bool) $resultado['ok'],
            'message' => $resultado['message'],
            'data' => $resultado,
        ], $resultado['ok'] ? 200 : 502);
    }

    public function descargarDocumento(Request $request, Notificacion $notificacion): JsonResponse
    {
        abort_if(
            ! $request->user()?->canAccessCompany($notificacion->company_id),
            403,
            'No tienes acceso a esta empresa.'
        );

        // Aquí NO se baja la carta: pedirla a la casilla registra el acuse y
        // arranca el plazo legal, así que sólo se hace desde /abrir, con
        // permiso y confirmación. Las constancias no tienen efecto legal.
        $datos = $request->validate([
            'tipo' => ['nullable', 'string', 'in:constancia_deposito,constancia_acuse'],
        ]);

        $resultado = $this->service->descargarDocumento(
            $notificacion,
            $datos['tipo'] ?? 'constancia_acuse'
        );

        if (! ($resultado['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'] ?? 'No se pudo descargar el documento.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $resultado,
            'message' => 'Documento descargado.',
        ]);
    }

    private function companyId(Request $request, ?int $companyId): int
    {
        $companyId = $companyId ?? (int) ($request->user()?->company_id ?? 1);

        $user = $request->user();
        abort_if($user && ! $user->canAccessCompany($companyId), 403, 'No tienes acceso a esta empresa.');

        return $companyId;
    }
}
