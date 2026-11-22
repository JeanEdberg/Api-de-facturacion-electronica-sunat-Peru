<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailySummary;
use App\Models\Retention;
use App\Models\User;
use App\Models\VoidedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function empresaConSucursal(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    return [$company, $branch];
}

function boletaPendiente(Company $company, Branch $branch, string $numero = '00000001'): Boleta
{
    $client = Client::factory()->create([
        'tipo_documento' => '1',
        'numero_documento' => '45678912',
    ]);

    return Boleta::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'tipo_documento' => '03',
        'serie' => 'B001',
        'correlativo' => $numero,
        'numero_completo' => 'B001-' . $numero,
        'fecha_emision' => '2026-11-21',
        'moneda' => 'PEN',
        'metodo_envio' => 'resumen_diario',
        'valor_venta' => 100,
        'mto_oper_gravadas' => 100,
        'mto_igv' => 18,
        'total_impuestos' => 18,
        'sub_total' => 100,
        'mto_imp_venta' => 118,
        'detalles' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'mto_valor_unitario' => 100]],
        'estado_sunat' => 'PENDIENTE',
    ]);
}

// ---------------- Resúmenes diarios ----------------

test('lista los resúmenes diarios de la empresa', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    DailySummary::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'correlativo' => '001',
        'fecha_generacion' => '2026-11-21',
        'fecha_resumen' => '2026-11-21',
        'detalles' => [],
        'estado_proceso' => 'GENERADO',
        'estado_sunat' => 'PENDIENTE',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/daily-summaries')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1);
});

test('devuelve las boletas pendientes de una fecha', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);
    boletaPendiente($company, $branch);

    $this->actingAs($user)
        ->getJson("/api/v1/daily-summaries/pending?company_id={$company->id}&branch_id={$branch->id}&fecha_emision=2026-11-21")
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('crea un resumen a partir de las boletas pendientes y las vincula', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);
    $boleta = boletaPendiente($company, $branch);

    $this->actingAs($user)
        ->postJson('/api/v1/daily-summaries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'fecha_resumen' => '2026-11-21',
        ])
        ->assertCreated()
        ->assertJsonPath('data.correlativo', '001');

    expect($boleta->fresh()->daily_summary_id)->not->toBeNull();
});

test('no crea un resumen si no hay boletas pendientes', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/daily-summaries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'fecha_resumen' => '2026-11-21',
        ])
        ->assertStatus(400)
        ->assertJsonPath('success', false);
});

test('no permite crear un resumen para otra empresa', function () {
    [$propia] = empresaConSucursal();
    [$ajena, $sucursalAjena] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $propia->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/daily-summaries', [
            'company_id' => $ajena->id,
            'branch_id' => $sucursalAjena->id,
            'fecha_resumen' => '2026-11-21',
        ])
        ->assertStatus(403);
});

// ---------------- Retenciones ----------------

test('crea una retención con su proveedor', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/retentions', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'serie' => 'R001',
            'correlativo' => '1',
            'fecha_emision' => '2026-11-21',
            'moneda' => 'PEN',
            'regimen' => '01',
            'tasa' => 3,
            'imp_retenido' => 3,
            'imp_pagado' => 97,
            'proveedor' => [
                'tipo_documento' => '6',
                'numero_documento' => '20100070970',
                'razon_social' => 'PROVEEDOR SAC',
            ],
            'detalles' => [[
                'tipo_doc' => '01',
                'num_doc' => 'F001-1',
                'fecha_emision' => '2026-11-20',
                'fecha_retencion' => '2026-11-21',
                'moneda' => 'PEN',
                'imp_total' => 100,
                'imp_pagar' => 97,
                'imp_retenido' => 3,
                'pagos' => [['moneda' => 'PEN', 'fecha' => '2026-11-21', 'importe' => 97]],
                'tipo_cambio' => ['fecha' => '2026-11-21', 'factor' => 1, 'moneda_obj' => 'PEN', 'moneda_ref' => 'PEN'],
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.numero_completo', 'R001-000001');

    expect(Retention::count())->toBe(1);
});

test('lista solo las retenciones de la empresa del usuario', function () {
    [$propia] = empresaConSucursal();
    [$ajena, $sucursalAjena] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $propia->id]);

    Retention::create([
        'company_id' => $ajena->id,
        'branch_id' => $sucursalAjena->id,
        'proveedor_id' => Client::factory()->create()->id,
        'serie' => 'R001',
        'correlativo' => '1',
        'fecha_emision' => '2026-11-21',
        'regimen' => '01',
        'tasa' => 3,
        'imp_retenido' => 3,
        'imp_pagado' => 97,
        'moneda' => 'PEN',
        'detalles' => [],
        'estado_sunat' => 'PENDIENTE',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/retentions')
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

// ---------------- Comunicación de baja ----------------

test('lista los documentos anulables de una fecha', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);
    boletaPendiente($company, $branch)->update(['estado_sunat' => 'ACEPTADO']);

    $this->actingAs($user)
        ->getJson("/api/v1/voided-documents/candidates?company_id={$company->id}&branch_id={$branch->id}&fecha_referencia=2026-11-21")
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('no ofrece como anulable un documento que SUNAT no aceptó', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);
    boletaPendiente($company, $branch); // estado PENDIENTE

    $this->actingAs($user)
        ->getJson("/api/v1/voided-documents/candidates?company_id={$company->id}&branch_id={$branch->id}&fecha_referencia=2026-11-21")
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('crea una comunicación de baja', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/voided-documents', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'fecha_referencia' => now()->toDateString(),
            'motivo_baja' => 'Error en el documento',
            'detalles' => [[
                'tipo_documento' => '03',
                'serie' => 'B001',
                'correlativo' => '00000001',
                'motivo_especifico' => 'Anulación por error',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_documentos', 1);

    expect(VoidedDocument::count())->toBe(1);
});

test('rechaza anular documentos de más de 7 días', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/voided-documents', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'fecha_referencia' => now()->subDays(20)->toDateString(),
            'motivo_baja' => 'Error',
            'detalles' => [[
                'tipo_documento' => '03',
                'serie' => 'B001',
                'correlativo' => '00000001',
                'motivo_especifico' => 'Error',
            ]],
        ])
        ->assertStatus(422);
});

test('no permite anular documentos de otra empresa', function () {
    [$propia] = empresaConSucursal();
    [$ajena, $sucursalAjena] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $propia->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/voided-documents', [
            'company_id' => $ajena->id,
            'branch_id' => $sucursalAjena->id,
            'fecha_referencia' => now()->toDateString(),
            'motivo_baja' => 'Error',
            'detalles' => [[
                'tipo_documento' => '03',
                'serie' => 'B001',
                'correlativo' => '00000001',
                'motivo_especifico' => 'Error',
            ]],
        ])
        ->assertStatus(403);
});

// ---------------- Carga masiva ----------------

test('descarga la plantilla de carga masiva', function () {
    [$company] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->get('/api/v1/bulk/plantilla');

    $response->assertOk();
    expect($response->getContent())->toContain('tipo_documento');
});

test('emite comprobantes desde un CSV y agrupa las líneas del mismo grupo', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $csv = implode("\n", [
        'grupo,tipo_documento,serie,fecha_emision,moneda,cliente_tipo_documento,cliente_numero_documento,cliente_razon_social,cliente_direccion,cliente_email,codigo,descripcion,unidad,cantidad,valor_unitario,precio_unitario,tipo_igv,porcentaje_igv,descuento',
        'G-001,03,B001,2026-11-21,PEN,1,45678912,CLIENTE UNO,,,P001,SERVICIO UNO,NIU,1,100.00,118.00,10,18,0',
        'G-001,03,B001,2026-11-21,PEN,1,45678912,CLIENTE UNO,,,P002,SERVICIO DOS,NIU,2,50.00,118.00,10,18,0',
        'G-002,03,B001,2026-11-21,PEN,1,45678913,CLIENTE DOS,,,P003,OTRO SERVICIO,NIU,1,200.00,236.00,10,18,0',
    ]);

    $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

    $response = $this->actingAs($user)
        ->post('/api/v1/bulk/documentos', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'archivo' => $archivo,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data.emitidos')
        ->assertJsonCount(0, 'data.errores');

    expect(Boleta::count())->toBe(2);
    expect(Boleta::first()->detalles)->toHaveCount(2);
});

test('informa la fila cuando una línea no tiene descripción', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $csv = implode("\n", [
        'grupo,tipo_documento,serie,fecha_emision,moneda,cliente_tipo_documento,cliente_numero_documento,cliente_razon_social,cliente_direccion,cliente_email,codigo,descripcion,unidad,cantidad,valor_unitario,precio_unitario,tipo_igv,porcentaje_igv,descuento',
        'G-001,03,B001,2026-11-21,PEN,1,45678912,CLIENTE UNO,,,P001,,NIU,1,100.00,118.00,10,18,0',
    ]);

    $this->actingAs($user)
        ->post('/api/v1/bulk/documentos', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'archivo' => UploadedFile::fake()->createWithContent('carga.csv', $csv),
        ])
        ->assertStatus(207)
        ->assertJsonCount(1, 'data.errores');

    expect(Boleta::count())->toBe(0);
});

test('no permite cargar comprobantes para otra empresa', function () {
    [$propia] = empresaConSucursal();
    [$ajena, $sucursalAjena] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $propia->id]);

    $csv = 'grupo,tipo_documento,serie,fecha_emision,moneda,cliente_tipo_documento,cliente_numero_documento,cliente_razon_social,cliente_direccion,cliente_email,codigo,descripcion,unidad,cantidad,valor_unitario,precio_unitario,tipo_igv,porcentaje_igv,descuento';

    $this->actingAs($user)
        ->post('/api/v1/bulk/documentos', [
            'company_id' => $ajena->id,
            'branch_id' => $sucursalAjena->id,
            'archivo' => UploadedFile::fake()->createWithContent('carga.csv', $csv),
        ])
        ->assertStatus(403);
});

// ---------------- Importación histórica ----------------

test('importa comprobantes históricos como aceptados y sin enviarlos a SUNAT', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $csv = implode("\n", [
        'tipo_documento,serie,correlativo,fecha_emision,moneda,cliente_tipo_documento,cliente_numero_documento,cliente_razon_social,base_gravada,igv,total',
        '03,B900,1,2025-01-15,PEN,1,45678912,CLIENTE HISTORICO,100.00,18.00,118.00',
    ]);

    $this->actingAs($user)
        ->post('/api/v1/historical/importar', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'tipo' => 'ventas',
            'archivo' => UploadedFile::fake()->createWithContent('hist.csv', $csv),
        ])
        ->assertOk()
        ->assertJsonPath('data.importados', 1);

    expect(Boleta::first()->estado_sunat)->toBe('ACEPTADO');
});

test('reimportar un comprobante histórico lo actualiza en vez de duplicarlo', function () {
    [$company, $branch] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    $csv = implode("\n", [
        'tipo_documento,serie,correlativo,fecha_emision,moneda,cliente_tipo_documento,cliente_numero_documento,cliente_razon_social,base_gravada,igv,total',
        '03,B900,1,2025-01-15,PEN,1,45678912,CLIENTE HISTORICO,100.00,18.00,118.00',
    ]);

    $this->actingAs($user)->post('/api/v1/historical/importar', [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'tipo' => 'ventas',
        'archivo' => UploadedFile::fake()->createWithContent('hist1.csv', $csv),
    ])->assertJsonPath('data.importados', 1);

    $corregido = str_replace('118.00', '236.00', $csv);
    $corregido = str_replace('100.00', '200.00', $corregido);

    $this->actingAs($user)->post('/api/v1/historical/importar', [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'tipo' => 'ventas',
        'archivo' => UploadedFile::fake()->createWithContent('hist2.csv', $corregido),
    ])->assertJsonPath('data.actualizados', 1);

    expect(Boleta::count())->toBe(1);
    expect((float) Boleta::first()->mto_imp_venta)->toBe(236.0);
});

test('descarga la plantilla histórica de ventas y de compras', function () {
    [$company] = empresaConSucursal();
    $user = User::factory()->create(['company_id' => $company->id]);

    foreach (['ventas', 'compras'] as $tipo) {
        $response = $this->actingAs($user)->get("/api/v1/historical/plantilla?tipo={$tipo}");
        $response->assertOk();
        expect($response->getContent())->toContain('base_gravada');
    }
});
