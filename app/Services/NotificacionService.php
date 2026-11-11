<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Notificacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Buzón electrónico SUNAT / SUNAFIL.
 *
 * Ni SUNAT ni SUNAFIL publican una API REST pública del buzón: el acceso se
 * hace con la credencial SOL en sus portales. Por eso este servicio:
 *   1) usa un adaptador HTTP configurable (por si la entidad habilita un
 *      endpoint o si se dispone de un gateway propio), y
 *   2) permite el alta manual de notificaciones cuando no hay endpoint.
 *
 * Las notificaciones se guardan por empresa y se deduplican por
 * (company_id, entidad, codigo), de modo que sincronizar dos veces no duplica.
 */
class NotificacionService
{
    /**
     * Sincroniza el buzón de una empresa con las entidades configuradas.
     *
     * @return array{importadas:int, actualizadas:int, entidades:array<string,int>}
     */
    public function sincronizar(Company $company): array
    {
        $importadas = 0;
        $actualizadas = 0;
        $porEntidad = [];

        foreach ([Notificacion::ENTIDAD_SUNAT, Notificacion::ENTIDAD_SUNAFIL] as $entidad) {
            $crudas = $this->obtenerDelBuzon($company, $entidad);
            $porEntidad[$entidad] = 0;

            foreach ($crudas as $cruda) {
                $datos = $this->normalizar($cruda, $entidad);
                if ($datos === null) {
                    continue;
                }

                $notificacion = Notificacion::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'entidad' => $entidad,
                        'codigo' => $datos['codigo'],
                    ],
                    $datos + ['company_id' => $company->id, 'entidad' => $entidad],
                );

                $notificacion->wasRecentlyCreated ? $importadas++ : $actualizadas++;
                $porEntidad[$entidad]++;
            }
        }

        return [
            'importadas' => $importadas,
            'actualizadas' => $actualizadas,
            'entidades' => $porEntidad,
        ];
    }

    /**
     * Obtiene las notificaciones crudas del buzón de una entidad.
     *
     * Sin endpoint configurado devuelve una lista vacía: el sistema sigue siendo
     * útil mediante el alta manual (registrar()).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function obtenerDelBuzon(Company $company, string $entidad): array
    {
        $endpoint = config("sunat.buzon.endpoints.{$entidad}");

        if (empty($endpoint)) {
            return [];
        }

        try {
            $response = Http::withOptions(['verify' => true])
                ->timeout(30)
                ->withBasicAuth($company->ruc . $company->usuario_sol, $company->clave_sol)
                ->acceptJson()
                ->get($endpoint, ['ruc' => $company->ruc]);

            if (! $response->successful()) {
                Log::warning('Buzón: respuesta no exitosa', [
                    'company_id' => $company->id,
                    'entidad' => $entidad,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            return is_array($data) ? ($data['notificaciones'] ?? $data) : [];
        } catch (\Throwable $e) {
            Log::error('Buzón: error al consultar la entidad', [
                'company_id' => $company->id,
                'entidad' => $entidad,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Normaliza una notificación cruda. Devuelve null si no tiene datos mínimos.
     *
     * @param  array<string,mixed>  $cruda
     * @return array<string,mixed>|null
     */
    public function normalizar(array $cruda, string $entidad): ?array
    {
        $asunto = trim((string) ($cruda['asunto'] ?? $cruda['titulo'] ?? ''));
        if ($asunto === '') {
            return null;
        }

        return [
            'codigo' => $this->normalizarCodigo($cruda['codigo'] ?? $cruda['id'] ?? null, $asunto),
            'asunto' => $asunto,
            'tipo' => $this->limpiar($cruda['tipo'] ?? null),
            'fecha' => $this->normalizarFecha($cruda['fecha'] ?? null),
            'detalle' => $this->limpiar($cruda['detalle'] ?? $cruda['descripcion'] ?? null),
            'url' => $this->limpiar($cruda['url'] ?? $cruda['enlace'] ?? null),
            'tiene_adjunto' => (bool) ($cruda['tiene_adjunto'] ?? $cruda['adjunto'] ?? false),
        ];
    }

    protected function normalizarCodigo(mixed $codigo, string $asunto): string
    {
        $codigo = trim((string) ($codigo ?? ''));
        // Sin código de la entidad, se usa un hash estable del asunto para no duplicar.
        return $codigo !== '' ? $codigo : 'auto-' . substr(sha1($asunto), 0, 16);
    }

    protected function normalizarFecha(mixed $fecha): ?string
    {
        if (empty($fecha)) {
            return null;
        }

        try {
            return Carbon::parse($fecha)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function limpiar(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        return $valor === '' ? null : $valor;
    }
}
