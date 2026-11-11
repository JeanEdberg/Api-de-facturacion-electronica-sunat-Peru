<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create([
        'ruc' => '20614684772',
        'razon_social' => 'INMOA ERP & FACTURACIÓN S.A.C.',
        'departamento' => 'LORETO',
    ]);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->client = Client::factory()->create([
        'company_id' => $this->company->id,
        'tipo_documento' => '6',
        'numero_documento' => '20512345678',
        'razon_social' => 'INVERSIONES PLUMÍFERO E.I.R.L.',
    ]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

test('la empresa persiste la condición de contribuyente de la Amazonía', function () {
    $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}", [
            'ruc' => $this->company->ruc,
            'razon_social' => $this->company->razon_social,
            'direccion' => 'AV. EJEMPLO 123',
            'ubigeo' => '150101',
            'distrito' => 'IQUITOS',
            'provincia' => 'MAYNAS',
            'departamento' => 'LORETO',
            'email' => 'test@empresa.com',
            'usuario_sol' => 'TESTUSER',
            'clave_sol' => 'TESTPASS',
            'contribuyente_amazonia' => '1',
            'tipo_amazonia' => '2002',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.contribuyente_amazonia', true)
        ->assertJsonPath('data.tipo_amazonia', '2002');

    $this->assertDatabaseHas('companies', [
        'id' => $this->company->id,
        'contribuyente_amazonia' => true,
        'tipo_amazonia' => '2002',
    ]);
});

test('al desmarcar Amazonía se limpia el tipo de leyenda', function () {
    $this->company->update(['contribuyente_amazonia' => true, 'tipo_amazonia' => '2001']);

    $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}", [
            'ruc' => $this->company->ruc,
            'razon_social' => $this->company->razon_social,
            'direccion' => 'AV. EJEMPLO 123',
            'ubigeo' => '150101',
            'distrito' => 'IQUITOS',
            'provincia' => 'MAYNAS',
            'departamento' => 'LORETO',
            'email' => 'test@empresa.com',
            'usuario_sol' => 'TESTUSER',
            'clave_sol' => 'TESTPASS',
            'contribuyente_amazonia' => '0',
            'tipo_amazonia' => '2001',
        ])
        ->assertStatus(200);

    $this->assertDatabaseHas('companies', [
        'id' => $this->company->id,
        'contribuyente_amazonia' => false,
        'tipo_amazonia' => null,
    ]);
});

test('la factura de una empresa de la Amazonía incluye la leyenda 2001 con ítems exonerados', function () {
    $this->company->update(['contribuyente_amazonia' => true, 'tipo_amazonia' => '2001']);

    $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'serie' => 'F001',
        'fecha_emision' => '2026-11-10',
        'moneda' => 'PEN',
        'forma_pago_tipo' => 'Contado',
        'client' => [
            'tipo_documento' => '6',
            'numero_documento' => '20512345678',
            'razon_social' => 'INVERSIONES PLUMÍFERO E.I.R.L.',
        ],
        'detalles' => [[
            'codigo' => 'P001',
            'descripcion' => 'Producto amazónico',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 100,
            'tip_afe_igv' => '20',
        ]],
    ])->assertStatus(201);

    $leyendas = collect($response->json('data.leyendas'));
    expect($leyendas->firstWhere('code', '2001')['value'])
        ->toBe('BIENES TRANSFERIDOS EN LA AMAZONIA REGION SELVA PARA SER CONSUMIDOS EN LA MISMA');
});

test('una empresa no amazónica no recibe leyenda de Amazonía', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'serie' => 'F001',
        'fecha_emision' => '2026-11-10',
        'moneda' => 'PEN',
        'forma_pago_tipo' => 'Contado',
        'client' => [
            'tipo_documento' => '6',
            'numero_documento' => '20512345678',
            'razon_social' => 'INVERSIONES PLUMÍFERO E.I.R.L.',
        ],
        'detalles' => [[
            'codigo' => 'P001',
            'descripcion' => 'Producto',
            'unidad' => 'NIU',
            'cantidad' => 1,
            'mto_valor_unitario' => 100,
            'tip_afe_igv' => '20',
        ]],
    ])->assertStatus(201);

    $codes = collect($response->json('data.leyendas'))->pluck('code');
    expect($codes)->not->toContain('2001')
        ->and($codes)->not->toContain('2002')
        ->and($codes)->not->toContain('2003');
});
