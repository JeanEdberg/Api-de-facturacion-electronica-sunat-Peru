<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Compra;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create(['ruc' => '20614684772', 'razon_social' => 'INMOA ERP & FACTURACIÓN S.A.C.']);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->client = Client::factory()->create([
        'company_id' => $this->company->id,
        'tipo_documento' => '6',
        'numero_documento' => '20512345678',
        'razon_social' => 'Inversiones Plumífero E.I.R.L.',
    ]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

function crearFactura(Company $company, Branch $branch, Client $client, array $extra = []): Invoice
{
    return Invoice::create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => '142',
        'numero_completo' => 'F001-142',
        'fecha_emision' => '2026-11-10',
        'moneda' => 'PEN',
        'mto_oper_gravadas' => 1000,
        'mto_igv' => 180,
        'mto_imp_venta' => 1180,
        'estado_sunat' => 'ACEPTADO',
        'detalles' => [],
    ], $extra));
}

test('genera la propuesta RVIE con 33 campos informados', function () {
    crearFactura($this->company, $this->branch, $this->client);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.filename', 'LE2061468477220261100140400021112.TXT')
        ->assertJsonPath('data.registros', 1);

    $linea = rtrim($response->json('data.contenido'), "\r\n");
    $cols = explode('|', $linea);

    expect($cols)->toHaveCount(34);       // 33 campos + vacío final por el '|'
    expect($cols[0])->toBe('20614684772'); // RUC del generador
    expect($cols[2])->toBe('202611');      // periodo AAAAMM
    expect($cols[3])->toBe('');            // CAR vacío
    expect($cols[6])->toBe('01');          // tipo comprobante
    expect($cols[8])->toBe('00000142');    // número a 8 dígitos
    expect($cols[10])->toBe('6');          // tipo doc cliente
    expect($cols[14])->toBe('1000.00');    // base gravada
    expect($cols[16])->toBe('180.00');     // IGV
    expect($cols[25])->toBe('1180.00');    // importe total
    expect($cols[26])->toBe('PEN');        // moneda
    expect($cols[27])->toBe('1.000');      // tipo de cambio
});

test('incluye facturas y boletas en la propuesta de ventas', function () {
    crearFactura($this->company, $this->branch, $this->client);
    Boleta::create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'client_id' => $this->client->id,
        'tipo_documento' => '03',
        'serie' => 'B001',
        'correlativo' => '388',
        'numero_completo' => 'B001-388',
        'fecha_emision' => '2026-11-10',
        'moneda' => 'PEN',
        'mto_oper_gravadas' => 250,
        'mto_igv' => 45,
        'mto_imp_venta' => 295,
        'estado_sunat' => 'ACEPTADO',
        'detalles' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200);

    expect($response->json('data.registros'))->toBe(2);
    expect($response->json('data.contenido'))->toContain('B001');
    expect($response->json('data.contenido'))->toContain('F001');
});

test('genera la propuesta RCE con 37 campos y 38-41 vacíos', function () {
    Compra::factory()->create([
        'company_id' => $this->company->id,
        'tipo_documento' => '01',
        'serie' => 'F010',
        'correlativo' => '8877',
        'fecha_emision' => '2026-11-05',
        'moneda' => 'PEN',
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100123456',
        'proveedor_razon_social' => 'Distribuidora Andina S.A.C.',
        'mto_oper_gravadas' => 800,
        'mto_igv' => 144,
        'mto_imp_venta' => 944,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/sire/compras?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.filename', 'LE2061468477220261100080400021112.TXT');

    $linea = rtrim($response->json('data.contenido'), "\r\n");
    $cols = explode('|', $linea);

    expect($cols)->toHaveCount(42);        // 41 campos + vacío final
    expect($cols[7])->toBe('F010');        // serie
    expect($cols[11])->toBe('6');          // tipo doc proveedor
    expect($cols[12])->toBe('20100123456');
    expect($cols[14])->toBe('800.00');     // BI gravada DG
    expect($cols[15])->toBe('144.00');     // IGV DG
    expect($cols[24])->toBe('944.00');     // importe total
    expect(array_slice($cols, 37, 4))->toBe(['', '', '', '']); // 38-41 vacíos
});

test('descarga el archivo con el nombre oficial del SIRE', function () {
    crearFactura($this->company, $this->branch, $this->client);

    $response = $this->actingAs($this->user)
        ->get("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11")
        ->assertStatus(200);

    expect($response->headers->get('content-disposition'))
        ->toContain('LE2061468477220261100140400021112.TXT');
});

test('exige el periodo', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}")
        ->assertStatus(422);
});

test('no permite generar la propuesta de una empresa ajena', function () {
    $otra = Company::factory()->create();

    $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$otra->id}&periodo=2026-11")
        ->assertStatus(403);
});

test('un periodo sin comprobantes devuelve un archivo vacío', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-01&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.registros', 0);

    expect($response->json('data.contenido'))->toBe('');
});
