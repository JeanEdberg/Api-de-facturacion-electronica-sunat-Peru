<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VoidedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
});

function payloadBaja(Company $company, Branch $branch): array
{
    return [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'fecha_referencia' => now()->subDay()->format('Y-m-d'),
        'motivo_baja' => 'ERROR EN LA EMISION',
        'detalles' => [
            [
                'tipo_documento' => '01',
                'serie' => 'F001',
                'correlativo' => '000001',
                'motivo_especifico' => 'ANULACION DE LA OPERACION',
            ],
        ],
    ];
}

test('crea una comunicación de baja', function () {
    $response = $this->actingAs($this->user)->postJson(
        '/api/v1/voided-documents',
        payloadBaja($this->company, $this->branch)
    );

    $response->assertStatus(201)->assertJson(['success' => true]);
    expect(VoidedDocument::count())->toBe(1);

    $voided = VoidedDocument::first();
    expect($voided->correlativo)->toBe('001');
    expect($voided->numero_completo)->toBe('RA-' . now()->format('Ymd') . '-001');
    expect($voided->total_documentos)->toBe(1);
});

test('rechaza documentos duplicados', function () {
    $payload = payloadBaja($this->company, $this->branch);
    $payload['detalles'][] = $payload['detalles'][0];

    $this->actingAs($this->user)->postJson('/api/v1/voided-documents', $payload)
        ->assertStatus(422);
});

test('rechaza anular documentos con más de 7 días', function () {
    $payload = payloadBaja($this->company, $this->branch);
    $payload['fecha_referencia'] = now()->subDays(10)->format('Y-m-d');

    $this->actingAs($this->user)->postJson('/api/v1/voided-documents', $payload)
        ->assertStatus(422);
});

test('lista los documentos candidatos a anular', function () {
    $client = \App\Models\Client::factory()->create();
    Invoice::create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'client_id' => $client->id,
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => '000001',
        'numero_completo' => 'F001-000001',
        'fecha_emision' => '2025-10-01',
        'moneda' => 'PEN',
        'mto_oper_gravadas' => 100,
        'mto_igv' => 18,
        'mto_imp_venta' => 118,
        'detalles' => [],
        'estado_sunat' => 'ACEPTADO',
    ]);

    $response = $this->actingAs($this->user)->getJson(
        '/api/v1/voided-documents/candidates?company_id=' . $this->company->id
        . '&branch_id=' . $this->branch->id
        . '&fecha_referencia=2025-10-01'
    );

    $response->assertOk()->assertJson(['success' => true, 'total' => 1]);
    expect($response->json('data.0.tipo_documento'))->toBe('01');
});

test('lista las comunicaciones de baja', function () {
    VoidedDocument::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson(
        '/api/v1/voided-documents?company_id=' . $this->company->id
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect($response->json('data.total'))->toBe(2);
});
