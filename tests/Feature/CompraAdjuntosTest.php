<?php

use App\Models\Compra;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    Storage::fake('public');
});

test('adjunta el PDF de una compra cuando el proveedor solo lo envió así', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);

    $pdf = UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf');

    $this->actingAs($this->user)
        ->postJson("/api/v1/compras/{$compra->id}/adjuntos", [
            'tipo' => 'pdf',
            'archivo' => $pdf,
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true, 'message' => 'PDF adjuntado correctamente.']);

    expect($compra->fresh()->pdf_path)->not->toBeNull();
    Storage::disk('public')->assertExists($compra->fresh()->pdf_path);
});

test('adjunta el XML de una compra', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);

    $xml = UploadedFile::fake()->createWithContent('comprobante.xml', '<Invoice/>');

    $this->actingAs($this->user)
        ->postJson("/api/v1/compras/{$compra->id}/adjuntos", [
            'tipo' => 'xml',
            'archivo' => $xml,
        ])
        ->assertStatus(201);

    expect($compra->fresh()->xml_path)->toContain('.xml');
});

test('rechaza un tipo de adjunto no permitido', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/compras/{$compra->id}/adjuntos", [
            'tipo' => 'exe',
            'archivo' => UploadedFile::fake()->create('x.exe', 1),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tipo');
});

test('descarga el PDF adjunto de una compra', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id]);
    $path = "facturas/pdf/01012026/{$compra->numero_completo}.pdf";
    Storage::disk('public')->put($path, '%PDF-1.4 contenido');
    $compra->update(['pdf_path' => $path]);

    $this->actingAs($this->user)
        ->get("/api/v1/compras/{$compra->id}/download-pdf")
        ->assertStatus(200)
        ->assertHeader('content-disposition');
});

test('devuelve 404 al descargar un CDR que no existe en la compra', function () {
    $compra = Compra::factory()->create(['company_id' => $this->company->id, 'cdr_path' => null]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/compras/{$compra->id}/download-cdr")
        ->assertStatus(404)
        ->assertJson(['success' => false, 'message' => 'CDR no encontrado.']);
});

test('no permite adjuntar a una compra de otra empresa', function () {
    $otraEmpresa = Company::factory()->create();
    $compra = Compra::factory()->create(['company_id' => $otraEmpresa->id]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/compras/{$compra->id}/adjuntos", [
            'tipo' => 'pdf',
            'archivo' => UploadedFile::fake()->create('factura.pdf', 10, 'application/pdf'),
        ])
        ->assertStatus(403);
});
