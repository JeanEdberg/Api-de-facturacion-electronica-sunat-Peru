<?php

use App\Models\Company;
use App\Models\Compra;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/** XML UBL 2.1 mínimo de una factura de proveedor. */
function xmlProveedor(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:ID>F001-0008877</cbc:ID>
  <cbc:IssueDate>2026-11-05</cbc:IssueDate>
  <cbc:InvoiceTypeCode>01</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>
  <cac:PaymentExchangeRate>
    <cbc:CalculationRate>3.750</cbc:CalculationRate>
  </cac:PaymentExchangeRate>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="6">20100123456</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>Distribuidora Andina S.A.C.</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:TaxTotal>
    <cbc:TaxAmount>144.00</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount>800.00</cbc:TaxableAmount>
      <cbc:TaxAmount>144.00</cbc:TaxAmount>
      <cac:TaxCategory>
        <cac:TaxScheme>
          <cbc:ID>1000</cbc:ID>
          <cbc:Name>IGV</cbc:Name>
        </cac:TaxScheme>
      </cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal>
    <cbc:LineExtensionAmount>800.00</cbc:LineExtensionAmount>
    <cbc:TaxableAmount>800.00</cbc:TaxableAmount>
    <cbc:PayableAmount>944.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

test('parsea un XML UBL de factura de proveedor', function () {
    $datos = (new CompraService)->parsearXml(xmlProveedor());

    expect($datos)->toMatchArray([
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => '0008877',
        'numero_completo' => 'F001-0008877',
        'fecha_emision' => '2026-11-05',
        'moneda' => 'USD',
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100123456',
        'proveedor_razon_social' => 'Distribuidora Andina S.A.C.',
    ]);
    expect((float) $datos['mto_oper_gravadas'])->toBe(800.0);
    expect((float) $datos['mto_igv'])->toBe(144.0);
    expect((float) $datos['mto_imp_venta'])->toBe(944.0);
    expect((float) $datos['tipo_cambio'])->toBe(3.75);
});

test('clasifica la base por código de tributo (IGV y exonerado)', function () {
    // Añade un segundo subtotal exonerado (código 9997) al XML de proveedor.
    $exonerado = <<<'SUB'
    <cac:TaxSubtotal>
      <cbc:TaxableAmount>200.00</cbc:TaxableAmount>
      <cbc:TaxAmount>0.00</cbc:TaxAmount>
      <cac:TaxCategory><cac:TaxScheme><cbc:ID>9997</cbc:ID><cbc:Name>EXO</cbc:Name></cac:TaxScheme></cac:TaxCategory>
    </cac:TaxSubtotal>
SUB;
    $xml = str_replace('</cac:TaxTotal>', $exonerado . '</cac:TaxTotal>', xmlProveedor());

    $datos = (new CompraService)->parsearXml($xml);

    expect((float) $datos['mto_oper_gravadas'])->toBe(800.0);
    expect((float) $datos['mto_oper_exoneradas'])->toBe(200.0);
    expect((float) $datos['mto_igv'])->toBe(144.0);
});

test('rechaza un XML que no es un comprobante', function () {
    expect(fn () => (new CompraService)->parsearXml('<html>no es xml valido'))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn () => (new CompraService)->parsearXml('<root><otro/></root>'))
        ->toThrow(\InvalidArgumentException::class);
});

test('registra una compra manualmente y evita duplicados por comprobante', function () {
    $payload = [
        'company_id' => $this->company->id,
        'tipo_documento' => '01',
        'serie' => 'F010',
        'correlativo' => '0001234',
        'fecha_emision' => '2026-11-05',
        'proveedor_num_doc' => '20100123456',
        'proveedor_razon_social' => 'Distribuidora Andina S.A.C.',
        'mto_oper_gravadas' => 800,
        'mto_igv' => 144,
        'mto_imp_venta' => 944,
    ];

    $this->actingAs($this->user)->postJson('/api/v1/compras', $payload)
        ->assertCreated()
        ->assertJsonPath('data.numero_completo', 'F010-0001234');

    $this->actingAs($this->user)->postJson('/api/v1/compras', $payload)->assertOk();

    expect(Compra::where('company_id', $this->company->id)->count())->toBe(1);
});

test('importa una compra desde el XML subido', function () {
    $archivo = UploadedFile::fake()->createWithContent('factura.xml', xmlProveedor());

    $this->actingAs($this->user)
        ->post('/api/v1/compras/importar-xml', [
            'company_id' => $this->company->id,
            'xml' => $archivo,
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.serie', 'F001')
        ->assertJsonPath('data.proveedor_num_doc', '20100123456');

    expect(Compra::where('company_id', $this->company->id)->count())->toBe(1);
});

test('lista las compras de la empresa del usuario con filtro por fechas', function () {
    Compra::factory()->create([
        'company_id' => $this->company->id,
        'fecha_emision' => '2026-11-05',
    ]);
    Compra::factory()->create([
        'company_id' => $this->company->id,
        'fecha_emision' => '2026-10-01',
    ]);
    Compra::factory()->create(['fecha_emision' => '2026-11-05']); // otra empresa

    $this->actingAs($this->user)
        ->getJson('/api/v1/compras?fecha_desde=2026-11-01&fecha_hasta=2026-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'data.data');
});

test('no permite registrar compras en otra empresa', function () {
    $otra = Company::factory()->create();

    $this->actingAs($this->user)->postJson('/api/v1/compras', [
        'company_id' => $otra->id,
        'tipo_documento' => '01',
        'fecha_emision' => '2026-11-05',
        'proveedor_num_doc' => '20100123456',
        'proveedor_razon_social' => 'X',
        'mto_imp_venta' => 100,
    ])->assertForbidden();
});

test('requiere autenticación', function () {
    $this->getJson('/api/v1/compras')->assertUnauthorized();
});
