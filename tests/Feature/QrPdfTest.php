<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use App\Services\PdfService;
use Illuminate\Support\Facades\View;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El QR es obligatorio en la representación impresa de todo comprobante
 * electrónico (RS 097-2012/SUNAT y normas de guías): comprobantes, notas y
 * guías. Estas pruebas garantizan que el QR se genere y quede incrustado en el
 * PDF en cada formato soportado.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->client = Client::factory()->create(['company_id' => $this->company->id]);
});

function assertPdfHasQr(string $pdf): void
{
    // dompdf incrusta el QR (SVG rasterizado) como un XObject de imagen.
    expect($pdf)->toStartWith('%PDF')
        ->and($pdf)->toContain('/Image');
}

function datosComprobante($company, $branch, $client, array $extra = []): array
{
    return array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'fecha_emision' => now(),
        'moneda' => 'PEN',
        'valor_venta' => 100,
        'mto_oper_gravadas' => 100,
        'mto_igv' => 18,
        'total_impuestos' => 18,
        'mto_imp_venta' => 118,
        'forma_pago_tipo' => 'Contado',
        'detalles' => [[
            'codigo' => 'C1',
            'descripcion' => 'Servicio',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 100,
            'tip_afe_igv' => '10',
            'porcentaje_igv' => 18,
        ]],
        'leyendas' => [['code' => '1000', 'value' => 'CIENTO DIECIOCHO']],
        'estado_sunat' => 'PENDIENTE',
    ], $extra);
}

test('la factura incluye QR en todos los formatos', function () {
    $invoice = Invoice::create(datosComprobante($this->company, $this->branch, $this->client, [
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => '000001',
        'numero_completo' => 'F001-000001',
    ]));

    $service = app(PdfService::class);

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        assertPdfHasQr($service->generateInvoicePdf($invoice->load(['company', 'branch', 'client']), $format));
    }
});

test('la boleta incluye QR en todos los formatos', function () {
    $boleta = Boleta::create(datosComprobante($this->company, $this->branch, $this->client, [
        'tipo_documento' => '03',
        'serie' => 'B001',
        'correlativo' => '000001',
        'numero_completo' => 'B001-000001',
    ]));

    $service = app(PdfService::class);

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        assertPdfHasQr($service->generateBoletaPdf($boleta->load(['company', 'branch', 'client']), $format));
    }
});

test('la nota de crédito incluye QR en todos los formatos', function () {
    $creditNote = CreditNote::create(datosComprobante($this->company, $this->branch, $this->client, [
        'tipo_documento' => '07',
        'serie' => 'FC01',
        'correlativo' => '000001',
        'numero_completo' => 'FC01-000001',
        'tipo_doc_afectado' => '01',
        'num_doc_afectado' => 'F001-000001',
        'cod_motivo' => '07',
        'des_motivo' => 'Devolución',
    ]));

    $service = app(PdfService::class);

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        assertPdfHasQr($service->generateCreditNotePdf($creditNote->load(['company', 'branch', 'client']), $format));
    }
});

test('la nota de débito incluye QR en todos los formatos', function () {
    $debitNote = DebitNote::create(datosComprobante($this->company, $this->branch, $this->client, [
        'tipo_documento' => '08',
        'serie' => 'FD01',
        'correlativo' => '000001',
        'numero_completo' => 'FD01-000001',
        'tipo_doc_afectado' => '01',
        'num_doc_afectado' => 'F001-000001',
        'cod_motivo' => '02',
        'des_motivo' => 'Aumento en el valor',
    ]));

    $service = app(PdfService::class);

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        assertPdfHasQr($service->generateDebitNotePdf($debitNote->load(['company', 'branch', 'client']), $format));
    }
});

test('la guía de remisión incluye QR en todos los formatos', function () {
    $guide = DispatchGuide::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'client_id' => $this->client->id,
    ]);

    $service = app(PdfService::class);

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        assertPdfHasQr($service->generateDispatchGuidePdf($guide->load(['company', 'branch', 'destinatario']), $format));
    }
});

test('la guía por compra muestra la factura vinculada en el PDF', function () {
    $guide = DispatchGuide::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'client_id' => $this->client->id,
        'documentos_relacionados' => [
            ['tipo' => '01', 'tipo_desc' => 'FACTURA', 'numero' => 'F001-00000123'],
        ],
    ]);

    $service = app(PdfService::class);
    $prepare = new ReflectionMethod($service, 'prepareDispatchGuideData');
    $prepare->setAccessible(true);
    $template = new ReflectionMethod($service, 'getTemplate');
    $template->setAccessible(true);

    $data = $prepare->invoke($service, $guide->load(['company', 'branch', 'destinatario']));

    expect($data['documentos_relacionados'])
        ->toHaveCount(1)
        ->and($data['documentos_relacionados'][0]['numero'])->toBe('F001-00000123');

    foreach (['A4', 'A5', '80mm', '50mm'] as $format) {
        $html = View::make($template->invoke($service, 'dispatch-guide', $format), array_merge($data, ['format' => $format]))->render();
        expect($html)->toContain('F001-00000123')
            ->and($html)->toContain('DOCUMENTOS RELACIONADOS');
    }
});
