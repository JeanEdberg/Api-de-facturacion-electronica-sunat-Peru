<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta datos de un RUC o DNI en fuentes externas para autocompletar
 * formularios (alta de empresa y alta de cliente).
 *
 * Usa Decolecta cuando hay token configurado. Sin token, responde que la
 * consulta no está disponible en lugar de fallar, para que el formulario
 * siga funcionando con llenado manual.
 */
class ConsultaDocumentoService
{
    private const BASE_URL = 'https://api.decolecta.com/v1';

    public function __construct(
        private readonly ?string $token = null,
    ) {
    }

    /**
     * Consulta un documento y devuelve la forma que espera la PWA:
     * { disponible, encontrado, mensaje, data }.
     */
    public function consultar(string $tipoDocumento, string $numeroDocumento): array
    {
        $token = $this->token ?? config('services.decolecta.token');

        if (empty($token)) {
            return [
                'disponible' => false,
                'encontrado' => false,
                'mensaje' => 'La consulta automática no está configurada. Ingresa los datos a mano.',
                'data' => null,
            ];
        }

        $numeroDocumento = preg_replace('/\D/', '', $numeroDocumento);

        $cacheKey = "consulta-documento:{$tipoDocumento}:{$numeroDocumento}";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($token, $tipoDocumento, $numeroDocumento) {
            return $this->consultarFuente($token, $tipoDocumento, $numeroDocumento);
        });
    }

    private function consultarFuente(string $token, string $tipoDocumento, string $numeroDocumento): array
    {
        $ruta = match ($tipoDocumento) {
            '6' => 'sunat/ruc',
            '1' => 'reniec/dni',
            default => null,
        };

        if ($ruta === null) {
            return [
                'disponible' => true,
                'encontrado' => false,
                'mensaje' => 'Solo se pueden consultar RUC (tipo 6) y DNI (tipo 1).',
                'data' => null,
            ];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get(self::BASE_URL . "/{$ruta}", ['numero' => $numeroDocumento]);
        } catch (\Throwable $e) {
            Log::warning('Consulta de documento falló', ['error' => $e->getMessage()]);

            return [
                'disponible' => true,
                'encontrado' => false,
                'mensaje' => 'No se pudo consultar el documento. Intenta de nuevo o ingresa los datos a mano.',
                'data' => null,
            ];
        }

        if ($response->status() === 404) {
            return [
                'disponible' => true,
                'encontrado' => false,
                'mensaje' => 'El documento no se encontró en los registros.',
                'data' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'disponible' => true,
                'encontrado' => false,
                'mensaje' => 'La consulta devolvió un error (' . $response->status() . ').',
                'data' => null,
            ];
        }

        $data = $tipoDocumento === '6'
            ? $this->normalizarRuc($response->json(), $numeroDocumento)
            : $this->normalizarDni($response->json(), $numeroDocumento);

        return [
            'disponible' => true,
            'encontrado' => $data !== null,
            'mensaje' => $data ? 'Datos encontrados.' : 'El documento no se encontró en los registros.',
            'data' => $data,
        ];
    }

    private function normalizarRuc(?array $json, string $numero): ?array
    {
        if (empty($json['razon_social'])) {
            return null;
        }

        return [
            'tipo_documento' => '6',
            'numero_documento' => $json['numero_documento'] ?? $numero,
            'razon_social' => trim($json['razon_social']),
            'nombre_comercial' => null,
            'direccion' => trim($json['direccion'] ?? ''),
            'ubigeo' => $json['ubigeo'] ?? null,
            'distrito' => $json['distrito'] ?? null,
            'provincia' => $json['provincia'] ?? null,
            'departamento' => $json['departamento'] ?? null,
            'estado' => $json['estado'] ?? null,
            'condicion' => $json['condicion'] ?? null,
        ];
    }

    private function normalizarDni(?array $json, string $numero): ?array
    {
        $nombreCompleto = trim(implode(' ', array_filter([
            $json['nombres'] ?? null,
            $json['apellido_paterno'] ?? null,
            $json['apellido_materno'] ?? null,
        ])));

        if ($nombreCompleto === '') {
            return null;
        }

        return [
            'tipo_documento' => '1',
            'numero_documento' => $numero,
            'razon_social' => $nombreCompleto,
            'nombre_comercial' => null,
            'direccion' => null,
            'ubigeo' => null,
            'distrito' => null,
            'provincia' => null,
            'departamento' => null,
            'estado' => null,
            'condicion' => null,
        ];
    }
}
