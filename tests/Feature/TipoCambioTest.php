<?php

use App\Models\TipoCambio;
use App\Models\User;
use App\Services\TipoCambioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->hoy = now()->toDateString();
});

test('parsea el TXT oficial de SUNAT', function () {
    $servicio = new TipoCambioService;

    expect($servicio->parsear('10/11/2026|3.409|3.418|'))->toBe([
        'fecha' => '2026-11-10',
        'compra' => 3.409,
        'venta' => 3.418,
    ]);

    // El archivo termina en salto de línea.
    expect($servicio->parsear("10/11/2026|3.409|3.418|\r\n"))->toBe([
        'fecha' => '2026-11-10',
        'compra' => 3.409,
        'venta' => 3.418,
    ]);
});

test('devuelve null si el TXT no tiene el formato esperado', function () {
    $servicio = new TipoCambioService;

    expect($servicio->parsear(''))->toBeNull();
    expect($servicio->parsear('<html>error</html>'))->toBeNull();
    expect($servicio->parsear('2026-11-10|3.409|3.418'))->toBeNull();
});

test('obtiene y guarda el tipo de cambio del día desde SUNAT', function () {
    $fechaSunat = now()->format('d/m/Y');
    Http::fake([
        TipoCambioService::ENDPOINT => Http::response("{$fechaSunat}|3.409|3.418|", 200),
    ]);

    $servicio = new TipoCambioService;
    $resultado = $servicio->obtener($this->hoy);

    expect($resultado)->toBe(['fecha' => $this->hoy, 'compra' => 3.409, 'venta' => 3.418]);
    expect(TipoCambio::whereDate('fecha', $this->hoy)->value('venta'))->toEqual(3.418);
});

test('la segunda consulta sale de la base de datos, no de SUNAT', function () {
    Http::fake([
        TipoCambioService::ENDPOINT => Http::response(now()->format('d/m/Y').'|3.409|3.418|', 200),
    ]);

    $servicio = new TipoCambioService;
    $servicio->obtener($this->hoy);
    $servicio->obtener($this->hoy);

    Http::assertSentCount(1);
});

test('no consulta SUNAT para una fecha pasada sin registro', function () {
    Http::fake();

    $servicio = new TipoCambioService;
    $resultado = $servicio->obtener('2020-01-15');

    expect($resultado)->toBeNull();
    Http::assertNothingSent();
});

test('devuelve null si SUNAT no publicó la tasa de hoy', function () {
    Http::fake([
        TipoCambioService::ENDPOINT => Http::response('', 200),
    ]);

    $servicio = new TipoCambioService;

    expect($servicio->obtener($this->hoy))->toBeNull();
    $this->assertDatabaseCount('tipo_cambios', 0);
});

test('el endpoint responde el tipo de cambio del día', function () {
    TipoCambio::create(['fecha' => '2026-11-10', 'compra' => 3.409, 'venta' => 3.418]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tipo-cambio?fecha=2026-11-10');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['fecha', 'compra', 'venta']])
        ->assertJsonPath('data.venta', 3.418);
});

test('el endpoint responde 404 si no hay tasa para la fecha', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tipo-cambio?fecha=2020-01-15');

    $response->assertStatus(404)->assertJsonPath('success', false);
});

test('el endpoint valida el formato de la fecha', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/tipo-cambio?fecha=10/11/2026');

    $response->assertStatus(422);
});

test('el endpoint requiere autenticación', function () {
    $this->getJson('/api/v1/tipo-cambio?fecha=2026-11-10')->assertStatus(401);
});
