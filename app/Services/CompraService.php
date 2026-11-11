<?php

namespace App\Services;

use App\Models\Compra;
use SimpleXMLElement;

/**
 * Registro de compras: comprobantes RECIBIDOS de proveedores.
 *
 * Permite registrar manualmente y también importar los datos desde el XML UBL
 * del comprobante (que el proveedor entrega), de modo que no haya que teclear
 * los importes.
 */
class CompraService
{
    /**
     * Extrae los datos de un comprobante desde su XML UBL 2.1.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException si el XML no es un comprobante válido.
     */
    public function parsearXml(string $contenido): array
    {
        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenido);
        libxml_use_internal_errors($anterior);

        if ($xml === false) {
            throw new \InvalidArgumentException('El archivo no es un XML válido.');
        }

        $ns = $xml->getNamespaces(true);
        $cbc = $ns['cbc'] ?? 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
        $cac = $ns['cac'] ?? 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

        $doc = $this->buscarComprobante($xml, $cac);
        if ($doc === null) {
            throw new \InvalidArgumentException('El XML no contiene un comprobante (Invoice/CreditNote/DebitNote).');
        }

        $docNs = $doc->getNamespaces(true);
        $cbc = $docNs['cbc'] ?? $cbc;
        $cac = $docNs['cac'] ?? $cac;

        $numero = (string) $doc->children($cbc)->ID;
        [$serie, $correlativo] = $this->separarNumero($numero);

        $proveedor = $doc->children($cac)->AccountingSupplierParty?->children($cac)->Party;
        $razon = (string) ($proveedor?->children($cac)->PartyLegalEntity?->children($cbc)->RegistrationName ?? '');
        $numDoc = (string) ($proveedor?->children($cac)->PartyIdentification?->children($cbc)->ID ?? '');
        $tipoDoc = (string) ($proveedor?->children($cac)->PartyIdentification?->children($cbc)->ID['schemeID'] ?? '6');

        $moneda = (string) ($doc->children($cbc)->DocumentCurrencyCode ?? 'PEN');

        $tipoCambio = 1.0;
        foreach ($doc->children($cac)->PaymentExchangeRate ?? [] as $rate) {
            $valor = (string) $rate->children($cbc)->CalculationRate;
            if ($valor !== '') {
                $tipoCambio = (float) $valor;
            }
        }

        $total = (float) ($doc->children($cac)->LegalMonetaryTotal?->children($cbc)->PayableAmount ?? 0);

        return array_filter([
            'tipo_documento' => $this->tipoDocumento((string) $doc->children($cbc)->InvoiceTypeCode),
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $numero,
            'fecha_emision' => substr((string) $doc->children($cbc)->IssueDate, 0, 10),
            'moneda' => $moneda !== '' ? $moneda : 'PEN',
            'tipo_cambio' => $tipoCambio,
            'proveedor_tipo_doc' => $tipoDoc !== '' ? $tipoDoc : '6',
            'proveedor_num_doc' => $numDoc,
            'proveedor_razon_social' => $razon,
            'mto_oper_gravadas' => $this->monto($doc, $cac, $cbc, 'TotalTaxableAmount', 'LineExtensionAmount'),
            'mto_oper_exoneradas' => $this->monto($doc, $cac, $cbc, 'TotalExoneratedAmount'),
            'mto_oper_inafectas' => $this->monto($doc, $cac, $cbc, 'TotalUnaffectedAmount'),
            'mto_oper_exportacion' => $this->monto($doc, $cac, $cbc, 'TotalExportationAmount'),
            'mto_igv' => $this->totalImpuesto($doc, $cac, $cbc, '1000', 'VAT'),
            'mto_isc' => $this->totalImpuesto($doc, $cac, $cbc, null, 'ISC'),
            'mto_icbper' => $this->totalImpuesto($doc, $cac, $cbc, '7152', 'OTH'),
            'mto_imp_venta' => $total,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Normaliza y guarda una compra (alta manual o importada). No duplica: si el
     * mismo comprobante del proveedor ya existe para la empresa, lo actualiza.
     *
     * @param  array<string,mixed>  $datos
     */
    public function guardar(int $companyId, array $datos): Compra
    {
        [$serie, $correlativo] = $this->separarNumero((string) ($datos['numero_completo'] ?? ''));

        $datos['company_id'] = $companyId;
        $datos['serie'] = $datos['serie'] ?? $serie;
        $datos['correlativo'] = $datos['correlativo'] ?? $correlativo;
        $datos['numero_completo'] = $datos['numero_completo']
            ?? trim(($datos['serie'] ?? '') . '-' . ($datos['correlativo'] ?? ''), '-');

        return Compra::updateOrCreate(
            [
                'company_id' => $companyId,
                'proveedor_num_doc' => $datos['proveedor_num_doc'],
                'tipo_documento' => $datos['tipo_documento'] ?? '01',
                'serie' => $datos['serie'],
                'correlativo' => $datos['correlativo'],
            ],
            $datos,
        );
    }

    /**
     * @param  \SimpleXMLElement  $doc
     */
    protected function monto($doc, string $cac, string $cbc, string $tag, ?string $fallback = null): float
    {
        $total = $doc->children($cac)->LegalMonetaryTotal;
        if ($total === null) {
            return 0.0;
        }

        $valor = (string) $total->children($cbc)->{$tag};
        if ($valor === '' && $fallback !== null) {
            $valor = (string) $total->children($cbc)->{$fallback};
        }

        return round((float) $valor, 2);
    }

    /**
     * Suma los impuestos del comprobante por código de tributo o tipo.
     *
     * @param  \SimpleXMLElement  $doc
     */
    protected function totalImpuesto($doc, string $cac, string $cbc, ?string $codigo, ?string $tipo): float
    {
        $suma = 0.0;

        foreach ($doc->children($cac)->TaxTotal ?? [] as $taxTotal) {
            foreach ($taxTotal->children($cac)->TaxSubtotal ?? [] as $subtotal) {
                $categoria = $subtotal->children($cac)->TaxCategory;
                $scheme = $categoria?->children($cac)->TaxScheme;
                $id = (string) ($scheme?->children($cbc)->ID ?? '');
                $name = (string) ($scheme?->children($cbc)->Name ?? '');

                if ($codigo !== null && $id !== $codigo) {
                    continue;
                }
                if ($codigo === null && $tipo !== null && ! str_contains(strtoupper($name), strtoupper($tipo))) {
                    continue;
                }

                $suma += (float) $subtotal->children($cbc)->TaxAmount;
            }
        }

        return round($suma, 2);
    }

    /**
     * Busca el comprobante raíz sea Invoice, CreditNote o DebitNote.
     */
    protected function buscarComprobante(SimpleXMLElement $xml, string $cac): ?SimpleXMLElement
    {
        foreach (['Invoice', 'CreditNote', 'DebitNote'] as $raiz) {
            if ($xml->getName() === $raiz) {
                return $xml;
            }
        }

        // Algunos XML envuelven el comprobante; se busca el primer hijo conocido.
        foreach ($xml->children() as $hijo) {
            if (in_array($hijo->getName(), ['Invoice', 'CreditNote', 'DebitNote'], true)) {
                return $hijo;
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function separarNumero(string $numero): array
    {
        $numero = trim($numero);
        if (str_contains($numero, '-')) {
            [$serie, $correlativo] = explode('-', $numero, 2);
            return [trim($serie), trim($correlativo)];
        }

        return ['', $numero];
    }

    protected function tipoDocumento(?string $codigo): string
    {
        $codigo = trim((string) $codigo);
        return $codigo !== '' ? str_pad($codigo, 2, '0', STR_PAD_LEFT) : '01';
    }
}
