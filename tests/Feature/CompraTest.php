<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Compra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create([
        'ruc' => '20614684772',
        'razon_social' => 'INMOA ERP & FACTURACIÓN S.A.C.',
    ]);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    Storage::fake('public');
});

function xmlFacturaProveedor(string $serie = 'F001', string $numero = 'F001-142'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:ID>{$numero}</cbc:ID>
  <cbc:IssueDate>2026-11-05</cbc:IssueDate>
  <cbc:InvoiceTypeCode>01</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>PEN</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="6">20512345678</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>PROVEEDOR DE PRUEBA S.A.C.</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:TaxTotal>
    <cbc:TaxAmount>18.00</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount>100.00</cbc:TaxableAmount>
      <cbc:TaxAmount>18.00</cbc:TaxAmount>
      <cac:TaxCategory><cbc:ID>10</cbc:ID><cac:TaxScheme><cbc:ID>1000</cbc:ID></cac:TaxScheme></cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount>118.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;
}

test('registra una compra manual y la lista paginada', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/compras', [
            'company_id' => $this->company->id,
            'tipo_documento' => '01',
            'serie' => 'F001',
            'correlativo' => '00000142',
            'fecha_emision' => '2026-11-05',
            'moneda' => 'PEN',
            'proveedor_tipo_doc' => '6',
            'proveedor_num_doc' => '20512345678',
            'proveedor_razon_social' => 'PROVEEDOR DE PRUEBA S.A.C.',
            'mto_oper_gravadas' => 100,
            'mto_igv' => 18,
            'mto_imp_venta' => 118,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.numero_completo', 'F001-00000142')
        ->assertJsonPath('data.origen', 'manual');

    $this->assertDatabaseHas('compras', [
        'company_id' => $this->company->id,
        'numero_completo' => 'F001-00000142',
        'mto_imp_venta' => 118,
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/compras?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.serie', 'F001');
});

test('importa el XML del proveedor y extrae los importes y la contraparte', function () {
    $archivo = UploadedFile::fake()->createWithContent('proveedor.xml', xmlFacturaProveedor());

    $this->actingAs($this->user)
        ->postJson('/api/v1/compras/importar-xml', [
            'company_id' => $this->company->id,
            'xml' => $archivo,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.origen', 'xml')
        ->assertJsonPath('data.serie', 'F001')
        ->assertJsonPath('data.correlativo', '00000142')
        ->assertJsonPath('data.proveedor_num_doc', '20512345678')
        ->assertJsonPath('data.proveedor_razon_social', 'PROVEEDOR DE PRUEBA S.A.C.')
        ->assertJsonPath('data.proveedor.razon_social', 'PROVEEDOR DE PRUEBA S.A.C.')
        ->assertJsonPath('data.mto_imp_venta', '118.00')
        ->assertJsonPath('data.mto_igv', '18.00');

    $compra = Compra::first();
    expect($compra->xml_path)->not->toBeNull();
    Storage::disk('public')->assertExists($compra->xml_path);
});

test('rechaza un XML ilegible con un 422', function () {
    $archivo = UploadedFile::fake()->createWithContent('basura.xml', 'esto no es xml');

    $this->actingAs($this->user)
        ->postJson('/api/v1/compras/importar-xml', [
            'company_id' => $this->company->id,
            'xml' => $archivo,
        ])
        ->assertStatus(422);
});

test('adjunta un PDF a una compra y lo descarga', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);
    $pdf = UploadedFile::fake()->create('proveedor.pdf', 10, 'application/pdf');

    $this->actingAs($this->user)
        ->postJson("/api/v1/compras/{$compra->id}/adjuntos", [
            'tipo' => 'pdf',
            'archivo' => $pdf,
        ])
        ->assertStatus(200);

    $compra->refresh();
    expect($compra->pdf_path)->not->toBeNull();

    $this->actingAs($this->user)
        ->get("/api/v1/compras/{$compra->id}/download-pdf")
        ->assertStatus(200);
});

test('elimina una compra y sus artefactos', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);
    Storage::disk('public')->put('compras/pdf/test.pdf', 'pdf');
    $compra->update(['pdf_path' => 'compras/pdf/test.pdf']);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/compras/{$compra->id}")
        ->assertStatus(200);

    $this->assertDatabaseMissing('compras', ['id' => $compra->id]);
    Storage::disk('public')->assertMissing('compras/pdf/test.pdf');
});

test('reimportar el mismo XML actualiza la compra en vez de duplicarla', function () {
    $archivo = UploadedFile::fake()->createWithContent('proveedor.xml', xmlFacturaProveedor());

    $payload = ['company_id' => $this->company->id, 'xml' => $archivo];

    $this->actingAs($this->user)->postJson('/api/v1/compras/importar-xml', $payload)->assertStatus(201);
    $this->actingAs($this->user)
        ->postJson('/api/v1/compras/importar-xml', ['company_id' => $this->company->id, 'xml' => UploadedFile::fake()->createWithContent('proveedor.xml', xmlFacturaProveedor())])
        ->assertStatus(201);

    expect(Compra::count())->toBe(1);
});

test('clasifica la base según el tipo de afectación del XML', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>F001-200</cbc:ID>
  <cbc:IssueDate>2026-11-05</cbc:IssueDate>
  <cbc:InvoiceTypeCode>01</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>PEN</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="6">20512345678</cbc:ID></cac:PartyIdentification>
      <cac:PartyLegalEntity><cbc:RegistrationName>PROVEEDOR S.A.C.</cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:TaxTotal>
    <cbc:TaxAmount>18.00</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount>100.00</cbc:TaxableAmount>
      <cbc:TaxAmount>18.00</cbc:TaxAmount>
      <cac:TaxCategory><cbc:ID>10</cbc:ID><cac:TaxScheme><cbc:ID>1000</cbc:ID></cac:TaxScheme></cac:TaxCategory>
    </cac:TaxSubtotal>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount>50.00</cbc:TaxableAmount>
      <cbc:TaxAmount>0.00</cbc:TaxAmount>
      <cac:TaxCategory><cbc:ID>20</cbc:ID><cac:TaxScheme><cbc:ID>9996</cbc:ID></cac:TaxScheme></cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal><cbc:PayableAmount>168.00</cbc:PayableAmount></cac:LegalMonetaryTotal>
</Invoice>
XML;

    $this->actingAs($this->user)
        ->postJson('/api/v1/compras/importar-xml', [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('proveedor.xml', $xml),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.mto_oper_gravadas', '100.00')
        ->assertJsonPath('data.mto_oper_exoneradas', '50.00')
        ->assertJsonPath('data.mto_igv', '18.00');
});

test('la propuesta SIRE de compras (RCE) incluye las compras del periodo', function () {
    Compra::factory()->create([
        'company_id' => $this->company->id,
        'fecha_emision' => '2026-11-05',
        'serie' => 'F001',
        'correlativo' => '00000142',
        'proveedor_num_doc' => '20512345678',
        'proveedor_razon_social' => 'PROVEEDOR DE PRUEBA S.A.C.',
        'mto_oper_gravadas' => 100,
        'mto_igv' => 18,
        'mto_imp_venta' => 118,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/api/v1/sire/compras?periodo=2026-11&preview=1")
        ->assertStatus(200);

    expect($response->json('data.registros'))->toBe(1);
    expect($response->json('data.contenido'))->toContain('20512345678');
});
