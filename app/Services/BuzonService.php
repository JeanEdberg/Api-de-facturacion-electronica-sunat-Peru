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
 * en un endpoint configurado por empresa (`buzon.endpoint` en sus
 * configuraciones). Si no hay endpoint, la sincronización no falla: informa que
 * no hay fuente y el usuario sigue registrando notificaciones a mano.
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
     * Sincroniza el buzón de la empresa contra los endpoints configurados por
     * entorno (SUNAT_BUZON_ENDPOINT / SUNAFIL_BUZON_ENDPOINT).
     *
     * @return array{importadas: int, fuentes: array<int, string>}
     */
    public function sincronizar(Company $company): array
    {
        $fuentes = array_filter([
            'SUNAT' => config('services.buzon.sunat_endpoint'),
            'SUNAFIL' => config('services.buzon.sunafil_endpoint'),
        ]);

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
     * Consulta un endpoint del buzón y registra las notificaciones recibidas.
     */
    private function importarDesde(Company $company, string $entidad, string $endpoint): int
    {
        $respuesta = Http::timeout(60)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'BuzonSUNAT/1.0'])
            ->when(config('services.buzon.token'), fn ($http, $token) => $http->withToken($token))
            ->get($endpoint, [
                'ruc' => $company->ruc,
                'usuario' => $company->usuario_sol,
                'entidad' => $entidad,
            ]);

        if (! $respuesta->successful()) {
            throw new \RuntimeException("El buzón {$entidad} de {$company->ruc} respondió HTTP {$respuesta->status()}");
        }

        $items = $respuesta->json('data') ?? $respuesta->json() ?? [];
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
}
