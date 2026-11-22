<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function facturaDe(Company $company): Invoice
{
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $client = Client::factory()->create([
        'company_id' => $company->id,
        'tipo_documento' => '6',
        'numero_documento' => '20512345678',
    ]);

    return Invoice::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => '00000001',
        'numero_completo' => 'F001-00000001',
        'fecha_emision' => '2026-11-10',
        'moneda' => 'PEN',
        'mto_oper_gravadas' => 1000,
        'mto_igv' => 180,
        'mto_imp_venta' => 1180,
        'detalles' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'valor_unitario' => 1000]],
        'estado_sunat' => 'ACEPTADO',
    ]);
}

// Un administrador de empresa no debe poder ver ni operar datos de otra
// empresa, ni aunque pida otro company_id en la petición.

test('el listado de empresas solo muestra la propia a un administrador de empresa', function () {
    $propia = Company::factory()->create(['ruc' => '20100000011']);
    $ajena = Company::factory()->create(['ruc' => '20100000012']);
    $user = User::factory()->create(['company_id' => $propia->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/companies');

    $rucs = collect($response->json('data'))->pluck('ruc')->all();

    expect($rucs)->toContain('20100000011')->not->toContain('20100000012');
});

test('un administrador de empresa no accede a una empresa ajena', function () {
    $propia = Company::factory()->create(['ruc' => '20100000013']);
    $ajena = Company::factory()->create(['ruc' => '20100000014']);
    $user = User::factory()->create(['company_id' => $propia->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/companies/{$ajena->id}")
        ->assertStatus(403);

    $this->actingAs($user)
        ->putJson("/api/v1/companies/{$ajena->id}", ['razon_social' => 'HACKEADA'])
        ->assertStatus(403);

    expect($ajena->refresh()->razon_social)->not->toBe('HACKEADA');
});

test('el filtro company_id no permite a un administrador ver comprobantes de otra empresa', function () {
    $propia = Company::factory()->create(['ruc' => '20100000015']);
    $ajena = Company::factory()->create(['ruc' => '20100000016']);
    $user = User::factory()->create(['company_id' => $propia->id]);

    facturaDe($ajena);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/invoices?company_id={$ajena->id}");

    $response->assertOk();

    $companies = collect($response->json('data.data'))->pluck('company_id')->unique();

    expect($companies)->not->toContain($ajena->id);
});
