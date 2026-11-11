<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Compra;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

/** Construye una línea PLE terminada en '|'. */
function lineaPle(array $campos, int $total): string
{
    $campos = array_pad($campos, $total, '');
    return implode('|', $campos) . '|';
}

/** Línea del Registro de Ventas 14.1 (34 campos). */
function lineaVentas141(array $overrides = []): string
{
    $base = array_fill(0, 34, '');
    $base[0] = '20240300';            // 1 periodo
    $base[1] = 'M0000001';            // 2 CUO
    $base[2] = 'M0000001';            // 3 correlativo asiento
    $base[3] = '15/03/2024';          // 4 fecha emisión
    $base[4] = '';                    // 5 vencimiento
    $base[5] = '01';                  // 6 tipo comprobante
    $base[6] = 'F001';                // 7 serie
    $base[7] = '00000123';            // 8 número
    $base[9] = '6';                   // 10 tipo doc cliente
    $base[10] = '20556677889';        // 11 número doc
    $base[11] = 'CLIENTE HISTORICO SAC'; // 12 razón social
    $base[13] = '1000.00';            // 14 BI gravada
    $base[15] = '180.00';             // 16 IGV
    $base[23] = '1180.00';            // 24 importe total
    $base[24] = 'PEN';                // 25 moneda
    $base[33] = '1';                  // 34 estado

    return lineaPle(array_replace($base, $overrides), 34);
}

/** Línea del Registro de Compras 8.1 (41 campos). */
function lineaCompras81(array $overrides = []): string
{
    $base = array_fill(0, 41, '');
    $base[0] = '20240300';
    $base[1] = 'M0000001';
    $base[2] = 'M0000001';
    $base[3] = '20/03/2024';          // fecha emisión
    $base[5] = '01';                  // tipo comprobante
    $base[6] = 'F001';                // serie
    $base[8] = '00000456';            // número
    $base[10] = '6';                  // tipo doc proveedor
    $base[11] = '20123456789';        // número doc proveedor
    $base[12] = 'PROVEEDOR HISTORICO SAC'; // razón social
    $base[13] = '2000.00';            // BI gravada
    $base[14] = '360.00';             // IGV
    $base[22] = '2360.00';            // importe total
    $base[23] = 'PEN';                // moneda
    $base[24] = '1.000';              // tipo de cambio
    $base[40] = '1';                  // estado

    return lineaPle(array_replace($base, $overrides), 41);
}

test('importa ventas de períodos anteriores desde un PLE 14.1', function () {
    $contenido = lineaVentas141() . "\r\n" . lineaVentas141([
        7 => '00000124', 10 => '20998877665', 11 => 'OTRO CLIENTE SAC',
    ]) . "\r\n";

    $archivo = UploadedFile::fake()->createWithContent('LE20240300140100.txt', $contenido);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.tipo', 'ventas')
        ->assertJsonPath('data.importados', 2);

    expect(Invoice::count())->toBe(2);
    $factura = Invoice::where('serie', 'F001')->where('correlativo', '00000123')->first();
    expect($factura)->not->toBeNull();
    expect((float) $factura->mto_oper_gravadas)->toBe(1000.00);
    expect((float) $factura->mto_igv)->toBe(180.00);
    expect((float) $factura->mto_imp_venta)->toBe(1180.00);
    expect($factura->fecha_emision->toDateString())->toBe('2024-03-15');
    expect($factura->estado_sunat)->toBe('ACEPTADO');
});

test('importa compras de períodos anteriores desde un PLE 8.1', function () {
    $contenido = lineaCompras81() . "\r\n";

    $archivo = UploadedFile::fake()->createWithContent('LE20240300080100.txt', $contenido);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.tipo', 'compras')
        ->assertJsonPath('data.importados', 1);

    $compra = Compra::first();
    expect($compra->proveedor_num_doc)->toBe('20123456789');
    expect((float) $compra->mto_oper_gravadas)->toBe(2000.00);
    expect((float) $compra->mto_igv)->toBe(360.00);
    expect((float) $compra->mto_imp_venta)->toBe(2360.00);
    expect($compra->fecha_emision->toDateString())->toBe('2024-03-20');
});

test('reimportar el mismo PLE actualiza en vez de duplicar', function () {
    $contenido = lineaVentas141() . "\r\n";
    $archivo = fn () => UploadedFile::fake()->createWithContent('ventas.txt', $contenido);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo(),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.importados', 1);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo(),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.importados', 0)
        ->assertJsonPath('data.actualizados', 1);

    expect(Invoice::count())->toBe(1);
});

test('omite las notas de crédito y débito del PLE de ventas', function () {
    $contenido = lineaVentas141() . "\r\n" . lineaVentas141([5 => '07', 7 => '00000099']) . "\r\n";

    $archivo = UploadedFile::fake()->createWithContent('ventas.txt', $contenido);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201);

    expect($response->json('data.importados'))->toBe(1);
    expect($response->json('data.omitidos'))->toHaveCount(1);
    expect(Invoice::count())->toBe(1);
});

test('avanza el correlativo de la serie tras importar el histórico', function () {
    $contenido = lineaVentas141([7 => '00000500']) . "\r\n";
    $archivo = UploadedFile::fake()->createWithContent('ventas.txt', $contenido);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201);

    $correlativo = \App\Models\Correlative::where('branch_id', $this->branch->id)
        ->where('tipo_documento', '01')
        ->where('serie', 'F001')
        ->first();

    expect($correlativo)->not->toBeNull();
    expect($correlativo->correlativo_actual)->toBe(500);
});

test('rechaza un archivo con un número de campos desconocido', function () {
    $archivo = UploadedFile::fake()->createWithContent('raro.txt', "a|b|c|\r\n");

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(422);
});

test('importa desde la plantilla CSV de ventas', function () {
    $cabecera = [
        'tipo_registro', 'tipo_documento', 'serie', 'correlativo', 'fecha_emision',
        'moneda', 'cliente_tipo_doc', 'cliente_num_doc', 'cliente_razon_social',
        'mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas',
        'mto_oper_exportacion', 'mto_igv', 'mto_isc', 'mto_otros_tributos',
        'mto_imp_venta', 'estado_sunat',
    ];
    $fila = [
        'ventas', '01', 'F001', '0000001', '2023-12-31', 'PEN', '6',
        '20556677889', 'CLIENTE 2023 SAC', '500.00', '0', '0', '0', '90.00', '0', '0',
        '590.00', 'ACEPTADO',
    ];

    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $cabecera, ';');
    fputcsv($stream, $fila, ';');
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    $archivo = UploadedFile::fake()->createWithContent('historico.csv', $csv);

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.importados', 1);

    expect(Invoice::count())->toBe(1);
    expect(Invoice::first()->fecha_emision->toDateString())->toBe('2023-12-31');
});

test('la plantilla descargable de compras incluye la cabecera esperada', function () {
    $response = $this->actingAs($this->user)->get('/api/v1/historical/plantilla?tipo=compras');

    $response->assertStatus(200);
    expect($response->headers->get('content-disposition'))->toContain('plantilla-historico-compras.csv');
    expect($response->streamedContent())->toContain('proveedor_num_doc');
});

test('no permite importar a una empresa ajena', function () {
    $otra = Company::factory()->create();
    $archivo = UploadedFile::fake()->createWithContent('ventas.txt', lineaVentas141() . "\r\n");

    $this->actingAs($this->user)
        ->postJson('/api/v1/historical/importar', [
            'company_id' => $otra->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(403);
});
