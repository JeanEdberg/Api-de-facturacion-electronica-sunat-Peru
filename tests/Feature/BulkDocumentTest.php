<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Company;
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

function plantillaCsv(array $filas): string
{
    $cabecera = [
        'grupo', 'tipo_documento', 'serie', 'fecha_emision', 'fecha_vencimiento',
        'moneda', 'forma_pago_tipo', 'cliente_tipo_doc', 'cliente_num_doc',
        'cliente_razon_social', 'cliente_direccion', 'cliente_email',
        'item_codigo', 'item_descripcion', 'item_unidad', 'item_cantidad',
        'item_valor_unitario', 'item_porcentaje_igv', 'item_tip_afe_igv',
    ];

    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $cabecera, ';');
    foreach ($filas as $fila) {
        fputcsv($stream, $fila, ';');
    }
    rewind($stream);
    $contenido = stream_get_contents($stream);
    fclose($stream);

    return $contenido;
}

/** Construye un XLSX mínimo válido con una sola hoja. */
function plantillaXlsx(array $filas): string
{
    $cabecera = [
        'grupo', 'tipo_documento', 'serie', 'fecha_emision', 'fecha_vencimiento',
        'moneda', 'forma_pago_tipo', 'cliente_tipo_doc', 'cliente_num_doc',
        'cliente_razon_social', 'cliente_direccion', 'cliente_email',
        'item_codigo', 'item_descripcion', 'item_unidad', 'item_cantidad',
        'item_valor_unitario', 'item_porcentaje_igv', 'item_tip_afe_igv',
    ];
    $todas = array_merge([$cabecera], $filas);

    $celda = fn (int $col, int $fila, string $valor) =>
        '<c r="' . chr(65 + $col) . $fila . '" t="inlineStr"><is><t>' . htmlspecialchars($valor) . '</t></is></c>';

    $filasXml = '';
    foreach ($todas as $i => $fila) {
        $num = $i + 1;
        $celdas = '';
        foreach ($fila as $c => $valor) {
            if ($valor !== '' && $valor !== null) {
                $celdas .= $celda($c, $num, (string) $valor);
            }
        }
        $filasXml .= '<row r="' . $num . '">' . $celdas . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $filasXml . '</sheetData></worksheet>';

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    return $ruta;
}

test('emite varias boletas desde un CSV', function () {
    $csv = plantillaCsv([
        ['1', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '44556677', 'JUAN PEREZ', '', '', 'P001', 'Gaseosa', 'NIU', '3', '2.50', '18', '10'],
        ['2', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '11223344', 'MARIA LOPEZ', '', '', 'P002', 'Agua', 'NIU', '1', '1.00', '18', '10'],
    ]);

    $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

    $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Se emitieron 2 comprobante(s) correctamente.',
        ]);

    expect(Boleta::count())->toBe(2);
    expect(Boleta::pluck('numero_completo')->all())->toBe(['B001-000001', 'B001-000002']);
});

test('consolida varias filas del mismo grupo en un solo comprobante con líneas', function () {
    $csv = plantillaCsv([
        ['A', '01', 'F001', '2026-11-10', '', 'PEN', 'Contado', '6', '20123456789', 'CLIENTE SAC', '', '', 'P001', 'Item uno', 'NIU', '2', '10.00', '18', '10'],
        ['A', '01', 'F001', '2026-11-10', '', 'PEN', 'Contado', '6', '20123456789', 'CLIENTE SAC', '', '', 'P002', 'Item dos', 'NIU', '1', '5.00', '18', '10'],
    ]);

    $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

    $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201);

    expect(Invoice::count())->toBe(1);
    $factura = Invoice::first();
    expect($factura->detalles)->toHaveCount(2);
    expect((float) $factura->mto_imp_venta)->toBe(29.50);
});

test('reporta los grupos inválidos sin emitir el resto a medias', function () {
    $csv = plantillaCsv([
        ['1', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '44556677', 'JUAN PEREZ', '', '', 'P001', 'Gaseosa', 'NIU', '3', '2.50', '18', '10'],
        ['2', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '', 'SIN DOCUMENTO', '', '', 'P002', 'Agua', 'NIU', '1', '1.00', '18', '10'],
    ]);

    $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(207);

    expect($response->json('data.emitidos'))->toHaveCount(1);
    expect($response->json('data.errores'))->toHaveCount(1);
    expect($response->json('data.errores.0.fila'))->toBe(3);
    expect(Boleta::count())->toBe(1);
});

test('emite comprobantes desde un archivo XLSX', function () {
    $ruta = plantillaXlsx([
        ['1', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '44556677', 'JUAN PEREZ', '', '', 'P001', 'Gaseosa', 'NIU', '2', '3.00', '18', '10'],
    ]);

    $archivo = new UploadedFile($ruta, 'carga.xlsx', null, null, true);

    $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $this->company->id,
            'archivo' => $archivo,
        ])
        ->assertStatus(201);

    expect(Boleta::count())->toBe(1);
    expect(Boleta::first()->numero_completo)->toBe('B001-000001');

    @unlink($ruta);
});

test('la plantilla descargable incluye la cabecera esperada', function () {
    $response = $this->actingAs($this->user)->get('/api/v1/bulk/plantilla');

    $response->assertStatus(200);
    expect($response->headers->get('content-disposition'))->toContain('plantilla-carga-masiva.csv');

    $contenido = $response->streamedContent();
    expect($contenido)->toContain('tipo_documento');
    expect($contenido)->toContain('item_valor_unitario');
});

test('no permite cargar a una empresa ajena', function () {
    $otra = Company::factory()->create();
    $csv = plantillaCsv([
        ['1', '03', 'B001', '2026-11-10', '', 'PEN', 'Contado', '1', '44556677', 'X', '', '', 'P001', 'Y', 'NIU', '1', '1', '18', '10'],
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $otra->id,
            'archivo' => UploadedFile::fake()->createWithContent('carga.csv', $csv),
        ])
        ->assertStatus(403);
});

test('rechaza un formato de archivo no soportado', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/bulk/documentos', [
            'company_id' => $this->company->id,
            'archivo' => UploadedFile::fake()->create('datos.pdf', 5, 'application/pdf'),
        ])
        ->assertStatus(422);
});
