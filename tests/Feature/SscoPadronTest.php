<?php

use App\Models\SscoPadron;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    SscoPadron::create([
        'ruc' => '10001276510',
        'razon_social' => 'TAPIA GONZALES MONICA FABIOLA',
        'resolucion' => 'Resolución de Intendencia N.º 024-024-0090639/SUNAT',
        'fecha_publicacion' => '2026-10-31',
    ]);
});

test('consulta individual: un RUC del padrón se reporta como SSCO', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/padrones/ssco/10001276510')
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'ruc' => '10001276510',
                'ssco' => true,
            ],
        ])
        ->assertJsonPath('data.detalle.razon_social', 'TAPIA GONZALES MONICA FABIOLA');
});

test('consulta individual: un RUC ausente no es SSCO', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/padrones/ssco/20123456789')
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => ['ssco' => false, 'detalle' => null],
        ]);
});

test('consulta en lote devuelve solo los RUC que son SSCO', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/padrones/ssco/lote', [
            'rucs' => ['10001276510', '20123456789', '10012979512'],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.consultados', 3)
        ->assertJsonPath('data.ssco.10001276510.razon_social', 'TAPIA GONZALES MONICA FABIOLA');

    expect($response->json('data.ssco'))->toHaveKey('10001276510');
    expect($response->json('data.ssco'))->not->toHaveKey('20123456789');
});

test('estado del padrón informa el número de registros', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/padrones/ssco/estado')
        ->assertStatus(200)
        ->assertJsonPath('data.registros', 1);
});

test('la consulta requiere autenticación', function () {
    $this->getJson('/api/v1/padrones/ssco/10001276510')->assertStatus(401);
});
