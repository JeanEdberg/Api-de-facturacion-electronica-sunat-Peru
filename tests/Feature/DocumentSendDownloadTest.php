<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * Simula el resultado de DocumentService::sendToSunat() marcando el documento
 * como ACEPTADO, sin llamar a SUNAT.
 */
function fakeSendResult($document, string $estado = 'ACEPTADO', ?string $code = null, ?string $message = null): array
{
    $document->estado_sunat = $estado;
    $document->respuesta_sunat = json_encode([
        'code' => $code,
        'description' => $message,
    ]);
    $document->save();

    return [
        'success' => $estado === 'ACEPTADO',
        'document' => $document,
        'error' => $estado === 'ACEPTADO' ? null : (object) ['code' => $code, 'message' => $message],
    ];
}

test('la nota de crédito expone la ruta de envío a SUNAT', function () {
    $nota = CreditNote::factory()->create(['estado_sunat' => 'PENDIENTE']);

    $this->mock(DocumentService::class, function ($mock) use ($nota) {
        $mock->shouldReceive('sendToSunat')
            ->once()
            ->withArgs(fn ($doc, $tipo) => $doc->is($nota) && $tipo === 'credit_note')
            ->andReturnUsing(fn () => fakeSendResult($nota));
    });

    $this->actingAs($this->user)
        ->postJson("/api/v1/credit-notes/{$nota->id}/send-sunat")
        ->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Nota de crédito enviada correctamente a SUNAT']);

    expect($nota->fresh()->estado_sunat)->toBe('ACEPTADO');
});

test('la nota de débito expone la ruta de envío a SUNAT', function () {
    $nota = DebitNote::factory()->create(['estado_sunat' => 'PENDIENTE']);

    $this->mock(DocumentService::class, function ($mock) use ($nota) {
        $mock->shouldReceive('sendToSunat')
            ->once()
            ->withArgs(fn ($doc, $tipo) => $doc->is($nota) && $tipo === 'debit_note')
            ->andReturnUsing(fn () => fakeSendResult($nota));
    });

    $this->actingAs($this->user)
        ->postJson("/api/v1/debit-notes/{$nota->id}/send-sunat")
        ->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Nota de débito enviada correctamente a SUNAT']);
});

test('la guía de remisión expone la ruta de envío a SUNAT', function () {
    $guia = DispatchGuide::factory()->create(['estado_sunat' => 'PENDIENTE']);

    $this->mock(DocumentService::class, function ($mock) use ($guia) {
        $mock->shouldReceive('sendDispatchGuideToSunat')
            ->once()
            ->withArgs(fn ($doc) => $doc->is($guia))
            ->andReturnUsing(fn () => fakeSendResult($guia, 'ACEPTADO'));
    });

    $this->actingAs($this->user)
        ->postJson("/api/v1/dispatch-guides/{$guia->id}/send-sunat")
        ->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Guía de remisión enviada correctamente a SUNAT']);
});

test('no reenvía un comprobante ya aceptado', function () {
    $nota = CreditNote::factory()->create(['estado_sunat' => 'ACEPTADO']);

    $this->mock(DocumentService::class, function ($mock) {
        $mock->shouldNotReceive('sendToSunat');
    });

    $this->actingAs($this->user)
        ->postJson("/api/v1/credit-notes/{$nota->id}/send-sunat")
        ->assertStatus(400)
        ->assertJson(['success' => false]);
});

test('devuelve error_code cuando SUNAT rechaza', function () {
    $nota = CreditNote::factory()->create(['estado_sunat' => 'PENDIENTE']);

    $this->mock(DocumentService::class, function ($mock) use ($nota) {
        $mock->shouldReceive('sendToSunat')
            ->once()
            ->andReturnUsing(fn () => fakeSendResult($nota, 'RECHAZADO', '2335', 'El comprobante fue rechazado'));
    });

    $this->actingAs($this->user)
        ->postJson("/api/v1/credit-notes/{$nota->id}/send-sunat")
        ->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_code' => '2335',
        ]);
});

test('descarga el XML de una nota de crédito cuando existe', function () {
    $nota = CreditNote::factory()->create(['xml_path' => 'notas-credito/xml/01012026/FC01-000001.xml']);

    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::disk('public')->put($nota->xml_path, '<xml/>');

    $this->actingAs($this->user)
        ->get("/api/v1/credit-notes/{$nota->id}/download-xml")
        ->assertStatus(200);
});

test('devuelve 404 al descargar XML de una nota sin archivo', function () {
    $nota = CreditNote::factory()->create(['xml_path' => null]);

    \Illuminate\Support\Facades\Storage::fake('public');

    $this->actingAs($this->user)
        ->getJson("/api/v1/credit-notes/{$nota->id}/download-xml")
        ->assertStatus(404)
        ->assertJson(['success' => false, 'message' => 'XML no encontrado']);
});
