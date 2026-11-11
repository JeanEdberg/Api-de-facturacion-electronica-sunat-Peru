<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
});

test('lista las boletas pendientes de una fecha', function () {
    Boleta::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_emision' => '2025-10-01',
        'estado_sunat' => 'PENDIENTE',
    ]);
    // Ya aceptada: no debe aparecer.
    Boleta::factory()->accepted()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_emision' => '2025-10-01',
    ]);

    $response = $this->actingAs($this->user)->getJson(
        '/api/v1/daily-summaries/pending?company_id=' . $this->company->id
        . '&branch_id=' . $this->branch->id
        . '&fecha_emision=2025-10-01'
    );

    $response->assertOk()
        ->assertJson(['success' => true, 'total' => 2]);
});

test('crea un resumen diario agrupando las boletas pendientes', function () {
    $boletas = Boleta::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_emision' => '2025-10-02',
        'estado_sunat' => 'PENDIENTE',
    ]);

    $response = $this->actingAs($this->user)->postJson('/api/v1/daily-summaries', [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_resumen' => '2025-10-02',
    ]);

    $response->assertStatus(201)->assertJson(['success' => true]);

    expect(DailySummary::count())->toBe(1);
    $summary = DailySummary::first();
    expect($summary->correlativo)->toBe('001');
    expect($summary->detalles)->toHaveCount(3);
    expect($summary->numero_completo)->toBe('RC-20251002-001');

    // Las boletas quedan vinculadas al resumen.
    foreach ($boletas as $boleta) {
        expect($boleta->fresh()->daily_summary_id)->toBe($summary->id);
    }
});

test('rechaza crear un resumen sin boletas pendientes', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/daily-summaries', [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_resumen' => '2025-10-03',
    ]);

    $response->assertStatus(422)->assertJson(['success' => false]);
    expect(DailySummary::count())->toBe(0);
});

test('incrementa el correlativo del resumen por fecha', function () {
    DailySummary::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_resumen' => '2025-10-04',
        'correlativo' => '001',
    ]);
    Boleta::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_emision' => '2025-10-04',
        'estado_sunat' => 'PENDIENTE',
    ]);

    $this->actingAs($this->user)->postJson('/api/v1/daily-summaries', [
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'fecha_resumen' => '2025-10-04',
    ])->assertStatus(201);

    expect(DailySummary::where('correlativo', '002')->count())->toBe(1);
});

test('lista los resúmenes con filtros', function () {
    DailySummary::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->actingAs($this->user)->getJson(
        '/api/v1/daily-summaries?company_id=' . $this->company->id
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect($response->json('data.total'))->toBe(2);
});
