<?php

use App\Services\ConsultaDocumentoService;
use Illuminate\Support\Facades\Http;

// El endpoint es público (el alta de empresa ocurre antes del login) y se
// apoya en Decolecta; aquí se simula la fuente externa con Http::fake para
// probar el camino real del servicio y el contrato de la respuesta.

test('devuelve los datos del RUC normalizados', function () {
    Http::fake([
        'api.decolecta.com/v1/sunat/ruc*' => Http::response([
            'numero_documento' => '20614684772',
            'razon_social' => 'INMOA LOGISTICA Y SOLUCIONES E.I.R.L.',
            'direccion' => 'JR. LA CULTURA MZA. J7 LOTE 1A',
            'ubigeo' => '170101',
            'distrito' => 'TAMBOPATA',
            'provincia' => 'TAMBOPATA',
            'departamento' => 'MADRE DE DIOS',
            'estado' => 'ACTIVO',
            'condicion' => 'HABIDO',
        ]),
    ]);

    $this->app->instance(ConsultaDocumentoService::class, new ConsultaDocumentoService('token-de-prueba'));

    $this->getJson('/api/v1/consulta-documento?tipo_documento=6&numero_documento=20614684772')
        ->assertStatus(200)
        ->assertJson([
            'disponible' => true,
            'encontrado' => true,
            'data' => [
                'razon_social' => 'INMOA LOGISTICA Y SOLUCIONES E.I.R.L.',
                'ubigeo' => '170101',
                'distrito' => 'TAMBOPATA',
            ],
        ]);
});

test('un documento ausente se informa como no encontrado', function () {
    Http::fake([
        'api.decolecta.com/v1/*' => Http::response(['message' => 'not found'], 404),
    ]);

    $this->app->instance(ConsultaDocumentoService::class, new ConsultaDocumentoService('token-de-prueba'));

    $this->getJson('/api/v1/consulta-documento?tipo_documento=6&numero_documento=20123456789')
        ->assertStatus(200)
        ->assertJson([
            'disponible' => true,
            'encontrado' => false,
            'data' => null,
        ]);
});

test('sin token configurado la consulta no está disponible', function () {
    config(['services.decolecta.token' => null]);
    $this->app->instance(ConsultaDocumentoService::class, new ConsultaDocumentoService(null));

    $this->getJson('/api/v1/consulta-documento?tipo_documento=6&numero_documento=20614684772')
        ->assertStatus(200)
        ->assertJson([
            'disponible' => false,
            'encontrado' => false,
        ]);
});

test('rechaza un tipo de documento no soportado', function () {
    $this->getJson('/api/v1/consulta-documento?tipo_documento=9&numero_documento=12345678')
        ->assertStatus(422);
});
