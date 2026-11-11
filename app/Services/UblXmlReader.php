<?php

namespace App\Services;

/**
 * Lee el XML UBL 2.1 de un comprobante recibido (el que emite el proveedor) y
 * extrae los importes y la contraparte para registrarlo en compras.
 *
 * Se apoya en SimpleXML y los namespaces de UBL, así que sirve tanto para
 * facturas (01) como para boletas (03) y notas (07/08) del proveedor. Los
 * tributos se acumulan por código de `TaxSubtotal` (catálogo 05): IGV 1000,
 * ISC 2000, ICBPER 7152; los demás van a otros tributos. Cuando un dato no está
 * en el XML se devuelve 0 o cadena vacía: el alta manual completa lo que falte.
 */
class UblXmlReader
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException si el archivo no es un XML legible
     */
    public function leer(string $contenido): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenido);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            throw new \RuntimeException('El archivo no es un XML válido.');
        }

        $xml->registerXPathNamespace('cac', self::NS_CAC);
        $xml->registerXPathNamespace('cbc', self::NS_CBC);

        $tipoDocumento = $this->texto($xml, 'cbc:InvoiceTypeCode')
            ?? $this->texto($xml, 'cbc:CreditNoteTypeCode')
            ?? $this->texto($xml, 'cbc:DebitNoteTypeCode')
            ?? '01';

        [$serie, $correlativo] = $this->partirNumero($this->texto($xml, 'cbc:ID') ?? '');

        $tributos = $this->tributos($xml);

        return [
            'tipo_documento' => str_pad($tipoDocumento, 2, '0', STR_PAD_LEFT),
            'serie' => $serie,
            'correlativo' => $correlativo,
            'fecha_emision' => $this->texto($xml, 'cbc:IssueDate'),
            'fecha_vencimiento' => $this->texto($xml, 'cbc:DueDate'),
            'moneda' => $this->texto($xml, 'cbc:DocumentCurrencyCode') ?? 'PEN',
            'proveedor_tipo_doc' => $this->tipoDocProveedor($xml),
            'proveedor_num_doc' => $this->texto($xml, 'cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID') ?? '',
            'proveedor_razon_social' => $this->texto($xml, 'cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')
                ?? $this->texto($xml, 'cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name') ?? '',
            'mto_oper_gravadas' => $tributos['gravadas'],
            'mto_oper_exoneradas' => $tributos['exoneradas'],
            'mto_oper_inafectas' => $tributos['inafectas'],
            'mto_oper_exportacion' => $tributos['exportacion'],
            'mto_igv' => $tributos['igv'],
            'mto_isc' => $tributos['isc'],
            'mto_icbper' => $tributos['icbper'],
            'mto_otros_tributos' => $tributos['otros'],
            'mto_imp_venta' => $this->monto($xml, 'cac:LegalMonetaryTotal/cbc:PayableAmount'),
            'origen' => 'xml',
        ];
    }

    /**
     * Acumula base e impuesto por código de tributo (catálogo 05) y clasifica la
     * base según el tipo de afectación (catálogo 07) de la categoría de tributo.
     *
     * @return array{gravadas: float, exoneradas: float, inafectas: float, exportacion: float, igv: float, isc: float, icbper: float, otros: float}
     */
    private function tributos(\SimpleXMLElement $xml): array
    {
        $totales = [
            'gravadas' => 0.0,
            'exoneradas' => 0.0,
            'inafectas' => 0.0,
            'exportacion' => 0.0,
            'igv' => 0.0,
            'isc' => 0.0,
            'icbper' => 0.0,
            'otros' => 0.0,
        ];

        foreach ($xml->xpath('cac:TaxTotal/cac:TaxSubtotal') ?: [] as $subtotal) {
            $subtotal->registerXPathNamespace('cac', self::NS_CAC);
            $subtotal->registerXPathNamespace('cbc', self::NS_CBC);

            $codigo = $this->nodo($subtotal, 'cac:TaxCategory/cac:TaxScheme/cbc:ID');
            $afectacion = $this->nodo($subtotal, 'cac:TaxCategory/cbc:ID');
            $base = round((float) ($this->nodo($subtotal, 'cbc:TaxableAmount') ?? 0), 2);
            $monto = round((float) ($this->nodo($subtotal, 'cbc:TaxAmount') ?? 0), 2);

            match ($codigo) {
                '1000' => $totales['igv'] += $monto,
                '2000' => $totales['isc'] += $monto,
                '7152' => $totales['icbper'] += $monto,
                default => $totales['otros'] += $monto,
            };

            if ($base > 0) {
                match (true) {
                    in_array($afectacion, ['20', '21'], true) => $totales['exoneradas'] += $base,
                    in_array($afectacion, ['30', '31', '32', '33', '34', '35', '36', '37'], true) => $totales['inafectas'] += $base,
                    $afectacion === '40' => $totales['exportacion'] += $base,
                    default => $totales['gravadas'] += $base,
                };
            }
        }

        return $totales;
    }

    /**
     * El esquema del proveedor usa el atributo schemeID del PartyIdentification
     * (o el catálogo 06: 1 DNI, 6 RUC, 4 carné de extranjería).
     */
    private function tipoDocProveedor(\SimpleXMLElement $xml): string
    {
        $nodos = $xml->xpath('cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID');
        if (! $nodos) {
            return '6';
        }

        $scheme = (string) ($nodos[0]['schemeID'] ?? '');

        return in_array($scheme, ['1', '4', '6', '0'], true) ? $scheme : '6';
    }

    /**
     * Separa 'F001-142' o 'F001142' en serie y correlativo de 8 dígitos.
     *
     * @return array{0: string, 1: string}
     */
    private function partirNumero(string $numero): array
    {
        $numero = trim($numero);
        if (str_contains($numero, '-')) {
            [$serie, $correlativo] = array_pad(explode('-', $numero, 2), 2, '');
        } else {
            $serie = substr($numero, 0, 4);
            $correlativo = substr($numero, 4);
        }

        return [$serie, str_pad(ltrim($correlativo, '0') ?: '0', 8, '0', STR_PAD_LEFT)];
    }

    private function texto(\SimpleXMLElement $xml, string $ruta): ?string
    {
        $valor = $this->nodo($xml, $ruta);

        return $valor === null || $valor === '' ? null : $valor;
    }

    private function nodo(\SimpleXMLElement $xml, string $ruta): ?string
    {
        $nodos = $xml->xpath($ruta);

        return $nodos ? trim((string) $nodos[0]) : null;
    }

    private function monto(\SimpleXMLElement $xml, string $ruta): float
    {
        $nodos = $xml->xpath($ruta);

        return $nodos ? round((float) $nodos[0], 2) : 0.0;
    }
}
