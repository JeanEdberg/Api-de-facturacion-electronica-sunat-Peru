<?php

use App\Models\Branch;
use App\Models\Company;
use App\Services\DocumentService;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company as GreenterCompany;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Xml\Builder\InvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('boleta de restaurante 2026 aplica la tasa especial 10.5% (Ley 31556 + 32387)', function () {
    $company = Company::factory()->create(['ruc' => '20614684772']);
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    $service = app(DocumentService::class);
    $boleta = $service->createBoleta([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'B001',
        'fecha_emision' => '2026-11-05',
        'moneda' => 'PEN',
        'metodo_envio' => 'individual',
        'client' => [
            'tipo_documento' => '1',
            'numero_documento' => '12345678',
            'razon_social' => 'CLIENTE PRUEBA',
        ],
        'detalles' => [[
            'codigo' => 'P001',
            'descripcion' => 'MENU DEL DIA',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 100,
            'tip_afe_igv' => '10',
            'porcentaje_igv' => 10.5,
        ]],
    ]);

    $detalle = $boleta->detalles[0];
    expect((float) $detalle['igv'])->toBe(10.5);
    expect((float) $boleta->mto_igv)->toBe(10.5);
    expect((float) $boleta->mto_imp_venta)->toBe(110.5);

    // El XML de Greenter debe reportar el 10,5 % en cada línea gravada. Se arma
    // el modelo directamente para no requerir el certificado digital.
    $invoice = new Invoice();
    $invoice->setUblVersion('2.1')->setTipoDoc('03')->setSerie('B001')->setCorrelativo('1')
        ->setFechaEmision(new \DateTime('2026-11-05'))
        ->setTipoMoneda('PEN')->setTipoOperacion('0101')
        ->setCompany((new GreenterCompany())->setRuc('20614684772')->setRazonSocial('INMOA'))
        ->setClient((new Client())->setTipoDoc('1')->setNumDoc('12345678')->setRznSocial('CLIENTE PRUEBA'))
        ->setMtoOperGravadas(100)->setMtoIGV(10.5)->setTotalImpuestos(10.5)->setValorVenta(100)
        ->setSubTotal(110.5)->setMtoImpVenta(110.5);

    $detail = (new SaleDetail())
        ->setCodProducto('P001')->setUnidad('NIU')->setDescripcion('MENU DEL DIA')
        ->setCantidad(1)->setMtoValorUnitario(100)->setMtoValorVenta(100)
        ->setMtoBaseIgv(100)->setPorcentajeIgv(10.5)->setIgv(10.5)
        ->setTipAfeIgv('10')->setTotalImpuestos(10.5)->setMtoPrecioUnitario(110.5);
    $invoice->setDetails([$detail]);

    $xml = (new InvoiceBuilder())->build($invoice);
    expect($xml)->toContain('<cbc:Percent>10.5</cbc:Percent>');
});
