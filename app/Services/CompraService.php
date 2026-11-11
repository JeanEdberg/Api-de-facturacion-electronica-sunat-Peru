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

        // En el UBL peruano la base de cada tributo va en TaxSubtotal (no en
        // LegalMonetaryTotal), así que se acumula base e impuesto por código de
        // tributo: 1000 IGV, 9997 exonerado, 9998 inafecto, 9995/9996 exportación,
        // 2000 ISC, 7152 ICBPER.
        [$bases, $tributos] = $this->acumularTributos($doc, $cac, $cbc);

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
            'mto_oper_gravadas' => $bases['1000'] ?? 0.0,
            'mto_oper_exoneradas' => $bases['9997'] ?? 0.0,
            'mto_oper_inafectas' => $bases['9998'] ?? 0.0,
            'mto_oper_exportacion' => round(($bases['9995'] ?? 0.0) + ($bases['9996'] ?? 0.0), 2),
            'mto_igv' => $tributos['1000'] ?? 0.0,
            'mto_isc' => $tributos['2000'] ?? 0.0,
            'mto_icbper' => $tributos['7152'] ?? 0.0,
            'mto_otros_tributos' => $this->otrosTributos($tributos),
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
     * Acumula base imponible e impuesto por código de tributo del comprobante.
     *
     * @param  \SimpleXMLElement  $doc
     * @return array{0: array<string,float>, 1: array<string,float>}
     */
    protected function acumularTributos($doc, string $cac, string $cbc): array
    {
        $bases = [];
        $tributos = [];

        foreach ($doc->children($cac)->TaxTotal ?? [] as $taxTotal) {
            foreach ($taxTotal->children($cac)->TaxSubtotal ?? [] as $subtotal) {
                $categoria = $subtotal->children($cac)->TaxCategory;
                $scheme = $categoria?->children($cac)->TaxScheme;
                $id = (string) ($scheme?->children($cbc)->ID ?? '');
                if ($id === '') {
                    continue;
                }

                $bases[$id] = round(($bases[$id] ?? 0.0) + (float) $subtotal->children($cbc)->TaxableAmount, 2);
                $tributos[$id] = round(($tributos[$id] ?? 0.0) + (float) $subtotal->children($cbc)->TaxAmount, 2);
            }
        }

        return [$bases, $tributos];
    }

    /**
     * Suma los tributos distintos del IGV/ISC/ICBPER ya reportados aparte.
     *
     * @param  array<string,float>  $tributos
     */
    protected function otrosTributos(array $tributos): float
    {
        $conocidos = ['1000', '2000', '7152'];
        $suma = 0.0;
        foreach ($tributos as $codigo => $monto) {
            if (! in_array($codigo, $conocidos, true)) {
                $suma += $monto;
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
