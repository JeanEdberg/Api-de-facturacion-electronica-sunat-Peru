<?php

namespace App\Services;

use App\Models\Boleta;
use App\Models\Company;
use App\Models\Compra;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Propuesta del SIRE (Sistema Integrado de Registros Electrónicos).
 *
 * Genera el archivo que se sube al portal del SIRE para ventas (RVIE, Anexo
 * N.º 3 de la RS 112-2021, archivo 140400) y compras (RCE, Anexo N.º 11 de la
 * RS 040-2022, archivo 080400). Es el mismo formato que produce la PWA en
 * src/lib/sire.js, ahora también disponible desde la API.
 *
 * Reglas del formato:
 *   - campos separados por '|' y línea terminada en '|';
 *   - fin de línea CRLF;
 *   - RVIE informa 33 campos (34-40 los completa la Administración);
 *   - RCE informa 37 campos y envía 38-41 vacíos;
 *   - la razón social admite hasta 1500 caracteres.
 */
class SireService
{
    public const SEPARATOR = '|';
    private const EOL = "\r\n";

    public function __construct(
        private TipoCambioService $tipoCambio,
    ) {}

    /**
     * Construye la propuesta RVIE (ventas) del periodo 'YYYY-MM' o 'YYYYMM'.
     *
     * @return array{filename:string,content:string,registros:int}
     */
    public function ventas(Company $company, string $periodo): array
    {
        $per = $this->periodo6($periodo);

        $facturas = Invoice::with('client')
            ->where('company_id', $company->id)
            ->whereYear('fecha_emision', substr($per, 0, 4))
            ->whereMonth('fecha_emision', substr($per, 4, 2))
            ->orderBy('fecha_emision')->orderBy('serie')->orderBy('correlativo')
            ->get();

        $boletas = Boleta::with('client')
            ->where('company_id', $company->id)
            ->whereYear('fecha_emision', substr($per, 0, 4))
            ->whereMonth('fecha_emision', substr($per, 4, 2))
            ->orderBy('fecha_emision')->orderBy('serie')->orderBy('correlativo')
            ->get();

        $lineas = [];
        foreach ($facturas->concat($boletas)->sortBy('fecha_emision') as $doc) {
            $lineas[] = $this->lineaRvie($doc, $company, $per);
        }

        $content = $lineas === [] ? '' : implode(self::EOL, $lineas) . self::EOL;

        return [
            'filename' => $this->filename($company->ruc, $per, '140400'),
            'content' => $content,
            'registros' => count($lineas),
        ];
    }

    /**
     * Construye la propuesta RCE (compras) del periodo.
     *
     * @return array{filename:string,content:string,registros:int}
     */
    public function compras(Company $company, string $periodo): array
    {
        $per = $this->periodo6($periodo);

        $compras = Compra::where('company_id', $company->id)
            ->whereYear('fecha_emision', substr($per, 0, 4))
            ->whereMonth('fecha_emision', substr($per, 4, 2))
            ->orderBy('fecha_emision')->orderBy('serie')->orderBy('correlativo')
            ->get();

        $lineas = $compras->map(fn ($c) => $this->lineaRce($c, $company, $per))->all();

        $content = $lineas === [] ? '' : implode(self::EOL, $lineas) . self::EOL;

        return [
            'filename' => $this->filename($company->ruc, $per, '080400'),
            'content' => $content,
            'registros' => count($lineas),
        ];
    }

    /**
     * @return string
     */
    private function lineaRvie($doc, Company $company, string $per): string
    {
        $cliente = $doc->client;

        return $this->campo([
            $company->ruc,                                  // 1 RUC
            $company->razon_social,                         // 2 razón social
            $per,                                           // 3 periodo AAAAMM
            '',                                             // 4 CAR (vacío en reemplazo)
            $this->fecha($doc->fecha_emision),              // 5 emisión
            '',                                             // 6 vencimiento/pago
            $doc->tipo_documento,                           // 7 tipo comprobante
            $doc->serie,                                    // 8 serie
            $this->numero($doc),                            // 9 número
            $this->numero($doc),                            // 10 número final
            $cliente?->tipo_documento,                      // 11 tipo doc cliente
            $cliente?->numero_documento,                    // 12 número doc
            $cliente?->razon_social,                        // 13 razón social
            $this->money($doc->mto_oper_exportacion),       // 14 exportación
            $this->money($doc->mto_oper_gravadas),          // 15 BI gravada
            $this->money(0),                                // 16 descuento BI
            $this->money($doc->mto_igv),                    // 17 IGV/IPM
            $this->money(0),                                // 18 descuento IGV
            $this->money($doc->mto_oper_exoneradas),        // 19 exonerado
            $this->money($doc->mto_oper_inafectas),         // 20 inafecto
            $this->money($doc->mto_isc),                    // 21 ISC
            $this->money($doc->mto_base_ivap),              // 22 base IVAP
            $this->money($doc->mto_ivap),                   // 23 IVAP
            $this->money($doc->mto_icbper),                 // 24 ICBPER
            $this->money($doc->mto_otros_tributos),         // 25 otros tributos
            $this->money($doc->mto_imp_venta),              // 26 importe total
            $doc->moneda ?: 'PEN',                          // 27 moneda
            $this->tipoCambio($doc, $company),              // 28 tipo de cambio
            '', '', '', '',                                 // 29-32 doc modificado
            '',                                             // 33 proyecto/contrato
        ]);
    }

    /**
     * @return string
     */
    private function lineaRce(Compra $compra, Company $company, string $per): string
    {
        return $this->campo([
            $company->ruc,                                  // 1 RUC
            $company->razon_social,                         // 2 razón social
            $per,                                           // 3 periodo AAAAMM
            '',                                             // 4 CAR
            $this->fecha($compra->fecha_emision),           // 5 emisión
            $this->fecha($compra->fecha_vencimiento),       // 6 vencimiento/pago
            $compra->tipo_documento,                        // 7 tipo comprobante
            $compra->serie,                                 // 8 serie
            '',                                             // 9 año DUA
            $this->numero($compra),                         // 10 número
            $this->numero($compra),                         // 11 número final
            $compra->proveedor_tipo_doc,                    // 12 tipo doc proveedor
            $compra->proveedor_num_doc,                     // 13 número doc
            $compra->proveedor_razon_social,                // 14 razón social
            $this->money($compra->mto_oper_gravadas),       // 15 BI gravada DG
            $this->money($compra->mto_igv),                 // 16 IGV del campo 15
            $this->money(0),                                // 17 BI mixta
            $this->money(0),                                // 18 IGV mixta
            $this->money(0),                                // 19 BI no gravada
            $this->money(0),                                // 20 IGV no gravada
            $this->money($compra->mto_oper_exoneradas),     // 21 no gravada
            $this->money($compra->mto_isc),                 // 22 ISC
            $this->money($compra->mto_icbper),              // 23 ICBPER
            $this->money($compra->mto_otros_tributos),      // 24 otros tributos
            $this->money($compra->mto_imp_venta),           // 25 importe total
            $compra->moneda ?: 'PEN',                       // 26 moneda
            $this->money($compra->tipo_cambio, 3),          // 27 tipo de cambio
            '', '', '', '',                                 // 28-31 doc modificado/aduana
            '',                                             // 32 número modificado
            '',                                             // 33 clasificación bienes
            '',                                             // 34 proyecto
            '', '', '', '', '', '', '',                     // 35-41
        ]);
    }

    /** Nombre oficial: LE + RUC(11) + AAAAMM + 00 + libro(6) + 021112. */
    private function filename(string $ruc, string $per, string $libro): string
    {
        return "LE{$ruc}{$per}00{$libro}021112.TXT";
    }

    /**
     * Une los campos con '|' y termina la línea con '|'.
     *
     * @param  array<int,mixed>  $valores
     */
    private function campo(array $valores): string
    {
        $limpios = array_map(fn ($v) => $v === null ? '' : (string) $v, $valores);

        return implode(self::SEPARATOR, $limpios) . self::SEPARATOR;
    }

    private function periodo6(string $periodo): string
    {
        return substr(preg_replace('/\D/', '', $periodo), 0, 6);
    }

    private function fecha($valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return Carbon::parse($valor)->format('d/m/Y');
    }

    private function numero($doc): string
    {
        $correlativo = (string) $doc->correlativo;

        return ctype_digit($correlativo) ? str_pad($correlativo, 8, '0', STR_PAD_LEFT) : $correlativo;
    }

    private function money($valor, int $decimales = 2): string
    {
        return number_format((float) ($valor ?? 0), $decimales, '.', '');
    }

    /**
     * Tipo de cambio de la operación. La API no lo persiste en el comprobante,
     * así que se resuelve por fecha (solo para moneda extranjera).
     */
    private function tipoCambio($doc, Company $company): string
    {
        $moneda = $doc->moneda ?: 'PEN';
        if ($moneda === 'PEN') {
            return '1.000';
        }

        $cotizacion = $this->tipoCambio->obtener(Carbon::parse($doc->fecha_emision)->toDateString());

        return $cotizacion ? number_format((float) $cotizacion['venta'], 3, '.', '') : '';
    }
}
