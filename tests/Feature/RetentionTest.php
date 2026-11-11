<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Retention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
});

function payloadRetencion(Company $company, Branch $branch): array
{
    return [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'serie' => 'R001',
        'correlativo' => '000001',
        'fecha_emision' => '2025-10-01',
        'moneda' => 'PEN',
        'regimen' => '01',
        'tasa' => 3.00,
        'imp_retenido' => 3.00,
        'imp_pagado' => 97.00,
        'proveedor' => [
            'tipo_documento' => '6',
            'numero_documento' => '20123456789',
            'razon_social' => 'PROVEEDOR TEST SAC',
        ],
        'detalles' => [
            [
                'tipo_doc' => '01',
                'num_doc' => 'F001-000001',
                'fecha_emision' => '2025-10-01',
                'fecha_retencion' => '2025-10-01',
                'moneda' => 'PEN',
                'imp_total' => 100.00,
                'imp_pagar' => 97.00,
                'imp_retenido' => 3.00,
                'pagos' => [
                    ['moneda' => 'PEN', 'fecha' => '2025-10-01', 'importe' => 97.00],
                ],
                'tipo_cambio' => [
                    'fecha' => '2025-10-01',
                    'factor' => 1,
                    'moneda_obj' => 'PEN',
                    'moneda_ref' => 'PEN',
                ],
            ],
        ],
    ];
}

test('crea una retención con su proveedor', function () {
    $response = $this->actingAs($this->user)->postJson(
        '/api/v1/retentions',
        payloadRetencion($this->company, $this->branch)
    );

    $response->assertStatus(201)->assertJson(['success' => true]);
    expect(Retention::count())->toBe(1);

    $retention = Retention::first();
    expect($retention->numero_completo)->toBe('R001-000001');
    expect($retention->proveedor->numero_documento)->toBe('20123456789');
    expect($retention->detalles)->toHaveCount(1);
});

test('valida los datos de la retención', function () {
    $payload = payloadRetencion($this->company, $this->branch);
    $payload['detalles'] = [];

    $this->actingAs($this->user)->postJson('/api/v1/retentions', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('detalles');
});

test('lista las retenciones con filtros', function () {
    Retention::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson(
        '/api/v1/retentions?company_id=' . $this->company->id
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect($response->json('data.total'))->toBe(2);
});
