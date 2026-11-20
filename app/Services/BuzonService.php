<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Notificacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Buzón electrónico SUNAT (SIE) / SUNAFIL.
 *
 * SUNAT no expone un API público del buzón, así que la sincronización se apoya
 * en un endpoint configurado por empresa (`buzon_sunat_endpoint` /
 * `buzon_sunafil_endpoint`). Si la empresa no define uno propio se usan los
 * endpoints por entorno (`services.buzon.*`). Sin fuente alguna la
 * sincronización no falla: informa que no hay fuente y el usuario sigue
 * registrando notificaciones a mano.
 *
 * Ese endpoint normalmente es el puente SOL (`tools/buzon-bridge/sol_buzon.py`),
 * que entra al Menú SOL con el RUC y la Clave SOL de la empresa y devuelve el
 * buzón como JSON.
 */
class BuzonService
{
    public const ENTIDADES = ['SUNAT', 'SUNAFIL'];

    /**
     * Registra una notificación manual. Si llega código repetido para la misma
     * empresa y entidad se actualiza en vez de duplicar.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos): Notificacion
    {
        $datos['origen'] = $datos['origen'] ?? 'manual';
        $datos['estado'] = $datos['estado'] ?? 'pendiente';

        if (empty($datos['codigo'])) {
            return Notificacion::create($datos);
        }

        return Notificacion::updateOrCreate(
            [
                'company_id' => $datos['company_id'],
                'entidad' => $datos['entidad'],
                'codigo' => $datos['codigo'],
            ],
            $datos,
        );
    }

    public function marcarLeida(Notificacion $notificacion): Notificacion
    {
        $notificacion->update(['estado' => 'leida', 'leida_en' => now()]);

        return $notificacion->refresh();
    }

    /**
     * Adjunta (o reemplaza) el expediente PDF/imagen de una notificación.
     */
    public function adjuntar(Notificacion $notificacion, UploadedFile $archivo): Notificacion
    {
        $anterior = $notificacion->adjunto_path;

        $ruta = $this->guardarAdjunto($notificacion, $archivo);
        $notificacion->update(['adjunto_path' => $ruta, 'tiene_adjunto' => true]);

        if ($anterior && $anterior !== $ruta) {
            Storage::disk('public')->delete($anterior);
        }

        return $notificacion->refresh();
    }

    public function rutaAdjunto(Notificacion $notificacion): ?string
    {
        $ruta = $notificacion->adjunto_path;

        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return $ruta;
    }

    private function guardarAdjunto(Notificacion $notificacion, UploadedFile $archivo): string
    {
        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: 'pdf');
        $ruta = "buzon/{$notificacion->company_id}/{$notificacion->id}.{$extension}";

        Storage::disk('public')->put($ruta, file_get_contents($archivo->getRealPath()));

        return $ruta;
    }

    /**
     * Resumen para el encabezado del panel: pendientes y total de la empresa.
     *
     * @return array{pendientes: int, total: int}
     */
    public function resumen(int $companyId, ?string $entidad = null): array
    {
        $query = Notificacion::where('company_id', $companyId);
        if ($entidad) {
            $query->where('entidad', $entidad);
        }

        return [
            'pendientes' => (clone $query)->where('estado', 'pendiente')->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * Endpoints efectivos de la empresa, entidad por entidad. La fuente de la
     * empresa gana sobre la del entorno.
     *
     * @return array<string, string>
     */
    public function fuentes(Company $company): array
    {
        return array_filter([
            'SUNAT' => $company->buzon_sunat_endpoint ?: config('services.buzon.sunat_endpoint'),
            'SUNAFIL' => $company->buzon_sunafil_endpoint ?: config('services.buzon.sunafil_endpoint'),
        ]);
    }

    /**
     * Estado de la fuente para el panel. No expone los secretos, solo si están
     * puestos, y de dónde sale cada endpoint.
     *
     * @return array{sunat: bool, sunafil: bool, configurado: bool, token: bool, sol: bool, origenes: array<int, string>}
     */
    public function estadoFuente(Company $company): array
    {
        $fuentes = $this->fuentes($company);
        $origenes = [];

        foreach (self::ENTIDADES as $entidad) {
            if (! isset($fuentes[$entidad])) {
                continue;
            }

            $propia = (bool) ($entidad === 'SUNAT' ? $company->buzon_sunat_endpoint : $company->buzon_sunafil_endpoint);
            $origenes[] = $propia ? 'empresa' : 'entorno';
        }

        return [
            'sunat' => isset($fuentes['SUNAT']),
            'sunafil' => isset($fuentes['SUNAFIL']),
            'configurado' => ! empty($fuentes),
            'token' => filled($company->buzon_token) || filled(config('services.buzon.token')),
            'sol' => filled($company->clave_sol),
            'origenes' => array_values(array_unique($origenes)),
        ];
    }

    /**
     * Sincroniza el buzón de la empresa contra sus endpoints.
     *
     * @return array{importadas: int, fuentes: array<int, string>}
     */
    public function sincronizar(Company $company): array
    {
        $fuentes = $this->fuentes($company);

        if (empty($fuentes)) {
            return ['importadas' => 0, 'fuentes' => []];
        }

        $importadas = 0;
        foreach ($fuentes as $entidad => $endpoint) {
            $importadas += $this->importarDesde($company, $entidad, $endpoint);
        }

        Log::info('Buzón sincronizado', [
            'company_id' => $company->id,
            'importadas' => $importadas,
        ]);

        return ['importadas' => $importadas, 'fuentes' => array_values($fuentes)];
    }

    /**
     * Prueba la fuente sin importar nada, para que el panel diga si la
     * configuración (endpoint, token y Clave SOL) realmente funciona.
     *
     * @return array{conectado: bool, total: int, message: string}
     */
    public function probar(Company $company, string $entidad = 'SUNAT'): array
    {
        $endpoint = $this->fuentes($company)[$entidad] ?? null;

        if (! $endpoint) {
            return [
                'conectado' => false,
                'total' => 0,
                'message' => "No hay endpoint configurado para {$entidad}. Define uno en la fuente del buzón.",
            ];
        }

        try {
            $items = $this->consultar($company, $entidad, $endpoint);
        } catch (\RuntimeException $e) {
            return ['conectado' => false, 'total' => 0, 'message' => $e->getMessage()];
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return [
                'conectado' => false,
                'total' => 0,
                'message' => "No se pudo llegar al endpoint {$entidad}. Revisa que el puente esté levantado y que la URL sea correcta.",
            ];
        }

        return [
            'conectado' => true,
            'total' => count($items),
            'message' => sprintf(
                'Conexión correcta con %s: %d notificación(es) disponibles.',
                $entidad,
                count($items),
            ),
        ];
    }

    /**
     * Consulta un endpoint del buzón y registra las notificaciones recibidas.
     */
    private function importarDesde(Company $company, string $entidad, string $endpoint): int
    {
        $items = $this->consultar($company, $entidad, $endpoint);
        $importadas = 0;

        foreach ($items as $item) {
            $notificacion = $this->registrar([
                'company_id' => $company->id,
                'entidad' => strtoupper($item['entidad'] ?? $entidad),
                'codigo' => $item['codigo'] ?? $item['numero'] ?? null,
                'asunto' => $item['asunto'] ?? $item['titulo'] ?? 'Notificación',
                'tipo' => $item['tipo'] ?? null,
                'fecha' => $item['fecha'] ?? null,
                'detalle' => $item['detalle'] ?? null,
                'url' => $item['url'] ?? null,
                'origen' => 'sincronizada',
            ]);

            if ($notificacion->wasRecentlyCreated) {
                $importadas++;
            }
        }

        return $importadas;
    }

    /**
     * Petición al endpoint del buzón, compartida por `sincronizar()` y
     * `probar()` para que ambos se comporten igual ante errores.
     *
     * La Clave SOL va en el cuerpo y no en la query: así no queda escrita en
     * los logs de acceso del puente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultar(Company $company, string $entidad, string $endpoint): array
    {
        $token = $company->buzon_token ?: config('services.buzon.token');

        $respuesta = Http::timeout(60)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'BuzonSUNAT/1.0'])
            ->when($token, fn ($http, $valor) => $http->withToken($valor))
            ->post($endpoint, [
                'ruc' => $company->ruc,
                'usuario' => $company->usuario_sol,
                'clave' => $company->clave_sol,
                'entidad' => $entidad,
            ]);

        // El puente devuelve `{ "error": "..." }` con un mensaje pensado para el
        // usuario cuando SUNAT rechaza las credenciales. Ese texto sirve más que
        // un "HTTP 502", que no explica nada.
        $error = $respuesta->json('error');
        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($error);
        }

        if (! $respuesta->successful()) {
            throw new \RuntimeException(
                "El buzón {$entidad} de {$company->ruc} respondió HTTP {$respuesta->status()}"
            );
        }

        $items = $respuesta->json('data') ?? $respuesta->json() ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
