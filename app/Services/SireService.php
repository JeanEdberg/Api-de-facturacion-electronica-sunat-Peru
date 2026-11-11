<?php

namespace App\Services;

use App\Models\Boleta;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SirePropuesta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Propuesta SIRE (Sistema Integrado de Registros Electrónicos).
 *
 * Genera el TXT RVIE (ventas, archivo 140400) o RCE (compras, archivo 080400) a
 * partir de los comprobantes del periodo y lo archiva por (empresa, libro,
 * periodo) para no duplicar propuestas del mismo mes.
 *
 * Reglas del formato (RS 112-2021 Anexo 3, RS 040-2022 Anexo 11):
 *   * separador '|', cada línea termina en '|', finales CRLF;
 *   * RVIE informa 33 campos; RCE informa 37 y envía 38-41 vacíos.
 */
class SireService
{
    private const SEP = '|';

    private const EOL = "\r\n";

    /**
     * Genera y archiva la propuesta del periodo. Devuelve contenido y metadatos.
     *
     * @return array{libro: string, periodo: string, filename: string, contenido: string, registros: int}
     */
    public function generar(int $companyId, string $periodo, string $libro): array
    {
        $libro = $libro === 'compras' ? 'compras' : 'ventas';
        $periodo = $this->normalizarPeriodo($periodo);

        $company = Company::findOrFail($companyId);
        $filas = $libro === 'compras'
            ? $this->filasCompras($companyId, $periodo)
            : $this->filasVentas($companyId, $periodo);

        $contenido = $this->construir($company, $periodo, $libro, $filas);
        $filename = $this->filename($company->ruc, $periodo, $libro === 'compras' ? '080400' : '140400');

        return [
            'libro' => $libro,
            'periodo' => $periodo,
            'filename' => $filename,
            'contenido' => $contenido,
            'registros' => count($filas),
        ];
    }

    /**
     * Genera la propuesta y la guarda en el repositorio (una por periodo).
     */
    public function generarYArchivar(int $companyId, string $periodo, string $libro, string $origen = 'servidor', ?string $nombre = null): SirePropuesta
    {
        $data = $this->generar($companyId, $periodo, $libro);

        return $this->archivar($companyId, $data['libro'], $data['periodo'], $data['contenido'], $data['registros'], $origen, $nombre ?? $data['filename']);
    }

    /**
     * Archiva (o reemplaza) la propuesta de un periodo. La clave única
     * (company_id, libro, periodo) evita duplicados al re-descargar.
     */
    public function archivar(int $companyId, string $libro, string $periodo, string $contenido, int $registros, string $origen = 'servidor', ?string $nombre = null): SirePropuesta
    {
        return SirePropuesta::updateOrCreate(
            ['company_id' => $companyId, 'libro' => $libro, 'periodo' => $this->normalizarPeriodo($periodo)],
            [
                'origen' => $origen,
                'nombre' => $nombre,
                'contenido' => $contenido,
                'registros' => $registros,
                'hash' => hash('sha256', $contenido),
            ],
        );
    }

    /**
     * Propuesta archivada de un periodo (o null).
     */
    public function obtener(int $companyId, string $libro, string $periodo): ?SirePropuesta
    {
        return SirePropuesta::where('company_id', $companyId)
            ->where('libro', $libro)
            ->where('periodo', $this->normalizarPeriodo($periodo))
            ->first();
    }

    /**
     * Lista las propuestas archivadas de una empresa.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SirePropuesta>
     */
    public function listar(int $companyId, ?string $libro = null)
    {
        return SirePropuesta::where('company_id', $companyId)
            ->when($libro, fn ($q) => $q->where('libro', $libro))
            ->orderBy('periodo')
            ->get();
    }

    public function filename(string $ruc, string $periodo, string $libro): string
    {
        $yyyymm = substr(preg_replace('/\D/', '', $periodo), 0, 6);

        return "LE{$ruc}{$yyyymm}00{$libro}021112.TXT";
    }

    /**
     * Periodo canónico 'AAAA-MM' (acepta también 'AAAAMM').
     */
    public function normalizarPeriodo(string $periodo): string
    {
        $digitos = substr(preg_replace('/\D/', '', $periodo), 0, 6);

        return strlen($digitos) === 6 ? substr($digitos, 0, 4).'-'.substr($digitos, 4, 2) : $digitos;
    }

    /**
     * Filas de ventas del periodo (facturas y boletas emitidas).
     *
     * @return array<int, array<string, mixed>>
     */
    private function filasVentas(int $companyId, string $periodo): array
    {
        [$desde, $hasta] = $this->rango($periodo);

        $facturas = Invoice::with('client')
            ->where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();
        $boletas = Boleta::with('client')
            ->where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        return $facturas->map(fn ($d) => $this->filaVenta($d, '01'))
            ->merge($boletas->map(fn ($d) => $this->filaVenta($d, '03')))
            ->values()
            ->all();
    }

    private function filaVenta($doc, string $tipo): array
    {
        return [
            'fecha_emision' => $doc->fecha_emision,
            'fecha_vencimiento' => $doc->fecha_vencimiento ?? null,
            'tipo_documento' => $tipo,
            'serie' => $doc->serie,
            'numero' => $doc->correlativo,
            'cliente' => [
                'tipo_doc' => $doc->client->tipo_documento ?? '',
                'num_doc' => $doc->client->numero_documento ?? '',
                'razon_social' => $doc->client->razon_social ?? '',
            ],
            'valor_exportacion' => 0,
            'bi_gravada' => (float) $doc->mto_oper_gravadas,
            'igv' => (float) $doc->mto_igv,
            'exonerada' => (float) $doc->mto_oper_exoneradas,
            'inafecta' => (float) $doc->mto_oper_inafectas,
            'isc' => (float) $doc->mto_isc,
            'bi_ivap' => (float) ($doc->mto_base_ivap ?? 0),
            'ivap' => (float) ($doc->mto_ivap ?? 0),
            'icbper' => (float) $doc->mto_icbper,
            'otros_tributos' => 0,
            'total' => (float) $doc->mto_imp_venta,
            'moneda' => $doc->moneda ?? 'PEN',
            'tipo_cambio' => 1,
        ];
    }

    /**
     * Filas de compras del periodo. El backend aún no persiste un registro de
     * compras propio: si existe la tabla `compras` se usa; si no, se devuelve
     * una lista vacía (la propuesta sale sin filas, lista para completarse).
     *
     * @return array<int, array<string, mixed>>
     */
    private function filasCompras(int $companyId, string $periodo): array
    {
        if (! DB::getSchemaBuilder()->hasTable('compras')) {
            return [];
        }

        [$desde, $hasta] = $this->rango($periodo);

        $compras = DB::table('compras')
            ->where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        return $compras->map(function ($c) {
            return [
                'fecha_emision' => $this->fecha($c->fecha_emision),
                'fecha_vencimiento' => $this->fecha($c->fecha_vencimiento ?? null),
                'tipo_documento' => $c->tipo_documento,
                'serie' => $c->serie,
                'numero' => $c->correlativo,
                'proveedor' => [
                    'tipo_doc' => $c->proveedor_tipo_doc ?? '6',
                    'num_doc' => $c->proveedor_num_doc ?? '',
                    'razon_social' => $c->proveedor_razon_social ?? '',
                ],
                'bi_gravada' => (float) ($c->mto_oper_gravadas ?? 0),
                'igv' => (float) ($c->mto_igv ?? 0),
                'no_gravada' => (float) ($c->mto_oper_exoneradas ?? 0),
                'isc' => (float) ($c->mto_isc ?? 0),
                'icbper' => (float) ($c->mto_icbper ?? 0),
                'otros_tributos' => 0,
                'total' => (float) ($c->mto_imp_venta ?? 0),
                'moneda' => $c->moneda ?? 'PEN',
                'tipo_cambio' => 1,
            ];
        })->values()->all();
    }

    private function construir(Company $company, string $periodo, string $libro, array $filas): string
    {
        $lineas = [];
        foreach ($filas as $fila) {
            $lineas[] = $libro === 'compras'
                ? $this->lineaRce($company, $periodo, $fila)
                : $this->lineaRvie($company, $periodo, $fila);
        }

        return implode(self::EOL, $lineas).self::EOL;
    }

    private function lineaRvie(Company $company, string $periodo, array $r): string
    {
        $c = $r['cliente'];

        return $this->campo([
            $company->ruc, $company->razon_social, $periodo, '',
            $this->fecha($r['fecha_emision']), '',
            $r['tipo_documento'], $r['serie'], $r['numero'], '',
            $c['tipo_doc'], $c['num_doc'], $c['razon_social'],
            $this->money($r['valor_exportacion']), $this->money($r['bi_gravada']), 0,
            $this->money($r['igv']), 0,
            $this->money($r['exonerada']), $this->money($r['inafecta']), $this->money($r['isc']),
            $this->money($r['bi_ivap']), $this->money($r['ivap']), $this->money($r['icbper']),
            $this->money($r['otros_tributos']), $this->money($r['total']),
            $r['moneda'], $this->tipoCambio($r['tipo_cambio']),
            '', '', '', '', '',
        ]);
    }

    private function lineaRce(Company $company, string $periodo, array $r): string
    {
        $p = $r['proveedor'];

        return $this->campo([
            $company->ruc, $company->razon_social, $periodo, '',
            $this->fecha($r['fecha_emision']), $this->fecha($r['fecha_vencimiento']),
            $r['tipo_documento'], $r['serie'], '',
            $r['numero'], '',
            $p['tipo_doc'], $p['num_doc'], $p['razon_social'],
            $this->money($r['bi_gravada']), $this->money($r['igv']),
            0, 0, 0, 0,
            $this->money($r['no_gravada']), $this->money($r['isc']), $this->money($r['icbper']),
            $this->money($r['otros_tributos']), $this->money($r['total']),
            $r['moneda'], $this->tipoCambio($r['tipo_cambio']),
            '', '', '', '', '', '', '', '', '', '', '', '',
        ]);
    }

    private function campo(array $valores): string
    {
        $limpios = array_map(fn ($v) => $v === null ? '' : (string) $v, $valores);

        return implode(self::SEP, $limpios).self::SEP;
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function tipoCambio($value): string
    {
        $tasa = (float) $value;
        if ($tasa <= 0) {
            $tasa = 1;
        }

        return number_format($tasa, 3, '.', '');
    }

    private function fecha($value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Primer y último día del periodo AAAAMM.
     *
     * @return array{0: string, 1: string}
     */
    private function rango(string $periodo): array
    {
        $yyyymm = substr(preg_replace('/\D/', '', $periodo), 0, 6);
        $inicio = Carbon::createFromFormat('Ym', $yyyymm)->startOfMonth();

        return [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()];
    }
}
