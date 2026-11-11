<?php

namespace App\Services;

use App\Models\SscoPadron;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Padrón de Sujetos Sin Capacidad Operativa (SSCO) de la SUNAT.
 *
 * Descarga la relación oficial, la parsea y la cachea en la tabla `ssco_padron`.
 * Los comprobantes emitidos por un RUC del padrón no dan crédito fiscal ni
 * sustentan gasto del adquirente (aplica desde el 20.03.2022).
 */
class SscoService
{
    public const PAGINA = 'https://www.sunat.gob.pe/padronesnotificaciones/sujeSinCapacidadOperativa.html';

    public const ARCHIVO = 'https://www.sunat.gob.pe/padronesnotificaciones/ssco/sujesincapacidadOperativa.xlsx';

    public function __construct(
        private XlsxReader $reader = new XlsxReader(),
    ) {
    }

    /**
     * Consulta si un RUC está en el padrón (desde la caché local en BD).
     *
     * @return array{ruc: string, ssco: bool, detalle: array<string, mixed>|null}
     */
    public function consultar(string $ruc): array
    {
        $ruc = preg_replace('/\D/', '', $ruc);
        $registro = SscoPadron::where('ruc', $ruc)->first();

        return [
            'ruc' => $ruc,
            'ssco' => $registro !== null,
            'detalle' => $registro?->toArray(),
        ];
    }

    /**
     * Consulta en lote: devuelve un mapa RUC -> detalle para los que sean SSCO.
     *
     * @param  array<int, string>  $rucs
     * @return array<string, array<string, mixed>>
     */
    public function consultarLote(array $rucs): array
    {
        $limpios = array_values(array_unique(array_filter(array_map(
            fn ($r) => preg_replace('/\D/', '', (string) $r),
            $rucs,
        ))));

        if (empty($limpios)) {
            return [];
        }

        $resultado = [];
        foreach (array_chunk($limpios, 500) as $chunk) {
            $registros = SscoPadron::whereIn('ruc', $chunk)->get();
            foreach ($registros as $registro) {
                $resultado[$registro->ruc] = $registro->toArray();
            }
        }

        return $resultado;
    }

    /**
     * Descarga el padrón oficial y reemplaza la caché local.
     *
     * @return array{registros: int, archivo: string}
     */
    public function sincronizar(?string $origen = null): array
    {
        $temporal = $origen ?: $this->descargar();
        $registros = $this->parsear($temporal);

        if (empty($registros)) {
            throw new RuntimeException('El padrón descargado no contiene registros');
        }

        DB::transaction(function () use ($registros) {
            SscoPadron::query()->delete();
            foreach (array_chunk($registros, 500) as $chunk) {
                SscoPadron::insert($chunk);
            }
        });

        return ['registros' => count($registros), 'archivo' => $temporal];
    }

    /**
     * Descarga el .xlsx del padrón a un archivo temporal.
     */
    public function descargar(): string
    {
        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BuzSOLFact/1.0)'])
            ->get(self::ARCHIVO);

        if (! $response->successful()) {
            throw new RuntimeException("No se pudo descargar el padrón SSCO (HTTP {$response->status()})");
        }

        $path = tempnam(sys_get_temp_dir(), 'ssco_').'.xlsx';
        file_put_contents($path, $response->body());

        return $path;
    }

    /**
     * Parsea el .xlsx del padrón a filas listas para insertar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parsear(string $path): array
    {
        $sheets = $this->reader->read($path);
        if (empty($sheets)) {
            return [];
        }

        $rows = $sheets[0]['rows'];
        $registros = [];
        $ahora = now();

        for ($i = 1; $i < count($rows); $i++) {
            $fila = $rows[$i];
            $ruc = preg_replace('/\D/', '', (string) ($fila[0] ?? ''));
            if ($ruc === '') {
                continue;
            }

            $registros[] = [
                'ruc' => $ruc,
                'razon_social' => $this->texto($fila[1] ?? null, 255),
                'domicilio' => $this->texto($fila[2] ?? null, 500),
                'resolucion' => $this->texto($fila[3] ?? null, 255),
                'fecha_emision' => $this->fecha($fila[4] ?? null),
                'fecha_firme' => $this->fecha($fila[5] ?? null),
                'rep_legal_doc' => preg_replace('/\D/', '', (string) ($fila[6] ?? '')),
                'rep_legal_nombre' => $this->texto($fila[7] ?? null, 255),
                'fecha_publicacion' => $this->fecha($fila[8] ?? null),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        return $registros;
    }

    private function texto(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    /**
     * Normaliza una fecha: serial de Excel (días desde 1899-12-30) o texto ISO.
     */
    private function fecha(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial <= 0) {
                return null;
            }
            $unix = (int) round(($serial - 25569) * 86400);

            return gmdate('Y-m-d', $unix);
        }

        $ts = strtotime((string) $value);

        return $ts ? date('Y-m-d', $ts) : null;
    }
}
