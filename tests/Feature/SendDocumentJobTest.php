<?php

use App\Jobs\SendDocumentToSunatJob;
use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function crearFactura(int $companyId, array $attrs = []): Invoice
{
    $branch = Branch::factory()->create(['company_id' => $companyId]);
    $client = Client::factory()->create();

    return Invoice::create(array_merge([
        'company_id' => $companyId,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'serie' => 'F001',
        'correlativo' => '00000001',
        'numero_completo' => 'F001-00000001',
        'fecha_emision' => '2026-11-10',
        'detalles' => [['codigo' => 'P1', 'descripcion' => 'Item', 'cantidad' => 1, 'precio_unitario' => 100]],
        'estado_sunat' => 'PENDIENTE',
    ], $attrs));
}

function crearBoleta(int $companyId, array $attrs = []): Boleta
{
    $branch = Branch::factory()->create(['company_id' => $companyId]);
    $client = Client::factory()->create();

    return Boleta::create(array_merge([
        'company_id' => $companyId,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'serie' => 'B001',
        'correlativo' => '00000001',
        'numero_completo' => 'B001-00000001',
        'fecha_emision' => '2026-11-10',
        'detalles' => [['codigo' => 'P1', 'descripcion' => 'Item', 'cantidad' => 1, 'precio_unitario' => 100]],
        'estado_sunat' => 'PENDIENTE',
    ], $attrs));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
});

test('send-sunat con async=true encola el envío y responde 202 sin contactar a SUNAT', function () {
    Bus::fake();

    $invoice = crearFactura($this->company->id);

    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice->id}/send-sunat", ['async' => true])
        ->assertStatus(202)
        ->assertJsonPath('success', true);

    Bus::assertDispatched(SendDocumentToSunatJob::class, function ($job) use ($invoice) {
        return $job->documentType === 'invoice' && $job->documentId === $invoice->id;
    });
});

test('send-sunat con async=true encola boletas correctamente', function () {
    Bus::fake();

    $boleta = crearBoleta($this->company->id);

    $this->actingAs($this->user)
        ->postJson("/api/v1/boletas/{$boleta->id}/send-sunat", ['async' => true])
        ->assertStatus(202);

    Bus::assertDispatched(SendDocumentToSunatJob::class, function ($job) use ($boleta) {
        return $job->documentType === 'boleta' && $job->documentId === $boleta->id;
    });
});

test('el job no reintenta un comprobante ya aceptado', function () {
    $invoice = crearFactura($this->company->id, ['estado_sunat' => 'ACEPTADO']);

    // Si intentara enviar fallaría por falta de certificado; debe salir antes.
    $job = new SendDocumentToSunatJob('invoice', $invoice->id);
    $job->handle(app(DocumentService::class));

    expect($invoice->fresh()->estado_sunat)->toBe('ACEPTADO');
});

test('el job ignora un documento inexistente sin lanzar excepción', function () {
    $job = new SendDocumentToSunatJob('invoice', 999999);
    $job->handle(app(DocumentService::class));

    expect(true)->toBeTrue();
});

test('el job define reintentos con espera creciente', function () {
    $job = new SendDocumentToSunatJob('invoice', 1);

    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff)->toBe([30, 120, 300, 600]);
});
