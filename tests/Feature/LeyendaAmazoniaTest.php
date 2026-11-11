<?php

use App\Models\Company;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Invoca el método protegido del servicio real (sin mocks).
function leyendaAmazoniaDe(array $data, ?Company $company): ?array
{
    $service = app(DocumentService::class);
    $method = new ReflectionMethod($service, 'leyendaAmazonia');
    $method->setAccessible(true);

    return $method->invoke($service, $data, $company);
}

function empresaAmazonia(array $attrs = []): Company
{
    return new Company(array_merge([
        'contribuyente_amazonia' => true,
        'tipo_amazonia' => '2001',
    ], $attrs));
}

it('agrega la leyenda de Amazonía (bienes) con operaciones exoneradas', function () {
    $leyenda = leyendaAmazoniaDe(
        ['detalles' => [['tip_afe_igv' => '20']]],
        empresaAmazonia()
    );

    expect($leyenda)->not->toBeNull()
        ->and($leyenda['code'])->toBe('2001')
        ->and($leyenda['value'])->toBe('BIENES TRANSFERIDOS EN LA AMAZONIA REGION SELVA PARA SER CONSUMIDOS EN LA MISMA');
});

it('usa el tipo de operación configurado (servicios y contratos)', function () {
    $servicios = leyendaAmazoniaDe(
        ['detalles' => [['tip_afe_igv' => '20']]],
        empresaAmazonia(['tipo_amazonia' => '2002'])
    );
    expect($servicios['code'])->toBe('2002')
        ->and($servicios['value'])->toContain('SERVICIOS PRESTADOS EN LA AMAZONIA');

    $contratos = leyendaAmazoniaDe(
        ['detalles' => [['tip_afe_igv' => '20']]],
        empresaAmazonia(['tipo_amazonia' => '2003'])
    );
    expect($contratos['code'])->toBe('2003')
        ->and($contratos['value'])->toContain('CONTRATOS DE CONSTRUCCION');
});

it('no agrega la leyenda si la empresa no es de la Amazonía', function () {
    $leyenda = leyendaAmazoniaDe(
        ['detalles' => [['tip_afe_igv' => '20']]],
        new Company(['contribuyente_amazonia' => false])
    );

    expect($leyenda)->toBeNull();
});

it('no agrega la leyenda si no hay operaciones exoneradas', function () {
    $leyenda = leyendaAmazoniaDe(
        ['detalles' => [['tip_afe_igv' => '10']]],
        empresaAmazonia()
    );

    expect($leyenda)->toBeNull();
});

it('guarda el flag de Amazonía por empresa (multiempresa)', function () {
    $amazonia = Company::factory()->create([
        'contribuyente_amazonia' => true,
        'tipo_amazonia' => '2002',
    ]);
    $lima = Company::factory()->create(['contribuyente_amazonia' => false]);

    expect($amazonia->fresh()->contribuyente_amazonia)->toBeTrue()
        ->and($amazonia->fresh()->tipo_amazonia)->toBe('2002')
        ->and($lima->fresh()->contribuyente_amazonia)->toBeFalse();
});
