<?php

namespace App\Services;

use App\Models\TipoCambio;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tipo de cambio SUNAT por fecha.
 *
 * La API de facturación no guarda el tipo de cambio de cada comprobante y SUNAT
 * solo publica la tasa del día en un archivo de texto, sin histórico accesible
 * (la consulta histórica del portal está detrás de un WAF). Por eso:
 *
 *   1. se busca la fecha en la tabla `tipo_cambios`;
 *   2. si falta y es la fecha de hoy, se consulta SUNAT y se persiste;
 *   3. si falta y es una fecha pasada, se devuelve null (ya no es recuperable).
 *
 * El comando `sunat:sync-tipo-cambio` corre a diario y va acumulando las fechas,
 * que es lo que permite reconstruir el histórico hacia adelante.
 */
class TipoCambioService
{
    public const ENDPOINT = 'https://www.sunat.gob.pe/a/txt/tipoCambio.txt';

    public function __construct(
        private string $endpoint = self::ENDPOINT,
    ) {}

    /**
     * Tasa para una fecha 'YYYY-MM-DD'. Devuelve ['fecha','compra','venta'] o null.
     */
    public function obtener(string $fecha): ?array
    {
        $dia = $this->normalizarFecha($fecha);
        if ($dia === null) {
            return null;
        }

        $registro = TipoCambio::whereDate('fecha', $dia)->first();
        if ($registro !== null) {
            return $this->presentar($registro);
        }

        // Solo la tasa del día se puede recuperar: SUNAT no expone histórico.
        if ($dia !== Carbon::today()->toDateString()) {
            return null;
        }

        return $this->consultarYGuardar($dia);
    }

    /**
     * Consulta SUNAT para una fecha y persiste el resultado. Lo usa el comando
     * diario. Devuelve la fila guardada o null si SUNAT no publicó tasa.
     */
    public function sincronizar(?string $fecha = null): ?array
    {
        $dia = $this->normalizarFecha($fecha ?? Carbon::today()->toDateString());
        if ($dia === null) {
            return null;
        }

        return $this->consultarYGuardar($dia);
    }

    private function consultarYGuardar(string $dia): ?array
    {
        $cotizacion = $this->consultarSunat();
        if ($cotizacion === null || $cotizacion['fecha'] !== $dia) {
            // SUNAT publica la tasa del día; si la fecha no coincide, no hay dato.
            return null;
        }

        $registro = TipoCambio::updateOrCreate(
            ['fecha' => $dia],
            ['compra' => $cotizacion['compra'], 'venta' => $cotizacion['venta'], 'fuente' => 'sunat'],
        );

        return $this->presentar($registro);
    }

    /**
     * Lee y parsea el TXT oficial: "10/11/2026|3.409|3.418|".
     */
    public function consultarSunat(): ?array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($this->endpoint);
            if (! $response->successful()) {
                Log::warning('SUNAT respondió '.$response->status().' al consultar el tipo de cambio.');

                return null;
            }

            return $this->parsear($response->body());
        } catch (\Throwable $e) {
            Log::warning('No se pudo consultar el tipo de cambio a SUNAT: '.$e->getMessage());

            return null;
        }
    }

    public function parsear(string $texto): ?array
    {
        $linea = trim(explode("\n", str_replace("\r", '', $texto))[0] ?? '');
        $partes = array_map('trim', explode('|', $linea));

        if (count($partes) < 3 || ! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $partes[0], $m)) {
            return null;
        }

        $numero = static function (string $v): ?float {
            return is_numeric($v) ? (float) $v : null;
        };

        return [
            'fecha' => sprintf('%s-%s-%s', $m[3], $m[2], $m[1]),
            'compra' => $numero($partes[1]),
            'venta' => $numero($partes[2]),
        ];
    }

    private function normalizarFecha(string $fecha): ?string
    {
        try {
            return Carbon::parse($fecha)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function presentar(TipoCambio $registro): array
    {
        return [
            'fecha' => $registro->fecha->toDateString(),
            'compra' => $registro->compra !== null ? (float) $registro->compra : null,
            'venta' => $registro->venta !== null ? (float) $registro->venta : null,
        ];
    }
}
