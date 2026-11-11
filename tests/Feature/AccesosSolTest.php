<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'ruc' => '20614684772',
        'usuario_sol' => 'ANTIGUO',
        'clave_sol' => 'antigua-clave',
    ]);
});

test('carga credencial SOL parcial desde el formulario de accesos', function () {
    // El frontend (AccesosSol.jsx) envía solo los campos SOL, no el RUC ni la
    // razón social; deben conservarse los valores existentes.
    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}", [
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'MODDATOS',
            'modo_produccion' => '0',
            'contribuyente_amazonia' => '0',
            'tipo_amazonia' => '',
        ]);

    $response->assertStatus(200);

    $this->company->refresh();
    expect($this->company->usuario_sol)->toBe('MODDATOS');
    expect($this->company->clave_sol)->toBe('MODDATOS');
    expect($this->company->ruc)->toBe('20614684772');
});

test('conserva la clave SOL si el campo llega vacío', function () {
    $response = $this->actingAs($this->user)
        ->putJson("/api/v1/companies/{$this->company->id}", [
            'usuario_sol' => 'NUEVOUSUARIO',
            'clave_sol' => '',
            'modo_produccion' => '0',
        ]);

    $response->assertStatus(200);

    $this->company->refresh();
    expect($this->company->usuario_sol)->toBe('NUEVOUSUARIO');
    expect($this->company->clave_sol)->toBe('antigua-clave');
});
