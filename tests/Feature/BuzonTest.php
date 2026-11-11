<?php

use App\Models\Company;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create([
        'ruc' => '20614684772',
        'razon_social' => 'INMOA ERP & FACTURACIÓN S.A.C.',
    ]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

/** Genera un PEM autofirmado (clave + certificado) válido por N días. */
function pemValidoPorDias(int $dias): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'INMOA ERP'], $key);
    $cert = openssl_csr_sign($csr, null, $key, $dias);
    openssl_x509_export($cert, $certOut);
    openssl_pkey_export($key, $keyOut);

    return $keyOut."\n".$certOut;
}

test('registra una notificación manual y la lista con resumen', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones', [
            'company_id' => $this->company->id,
            'entidad' => 'SUNAT',
            'codigo' => '023-2026',
            'asunto' => 'Resolución de Intendencia',
            'tipo' => 'Alerta',
            'fecha' => '2026-11-10',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.entidad', 'SUNAT')
        ->assertJsonPath('data.estado', 'pendiente');

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.asunto', 'Resolución de Intendencia')
        ->assertJsonPath('resumen.pendientes', 1)
        ->assertJsonPath('resumen.total', 1);
});

test('no duplica la misma notificación (empresa, entidad, código)', function () {
    $payload = [
        'company_id' => $this->company->id,
        'entidad' => 'SUNAT',
        'codigo' => '023-2026',
        'asunto' => 'Resolución de Intendencia',
    ];

    $this->actingAs($this->user)->postJson('/api/v1/notificaciones', $payload)->assertStatus(201);
    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones', array_merge($payload, ['asunto' => 'Resolución actualizada']))
        ->assertStatus(201);

    expect(Notificacion::count())->toBe(1);
    $this->assertDatabaseHas('notificaciones', ['asunto' => 'Resolución actualizada']);
});

test('marca una notificación como leída', function () {
    $notificacion = Notificacion::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/marcar-leida")
        ->assertStatus(200)
        ->assertJsonPath('data.estado', 'leida');

    $this->assertDatabaseHas('notificaciones', [
        'id' => $notificacion->id,
        'estado' => 'leida',
    ]);
});

test('filtra el buzón por entidad', function () {
    Notificacion::factory()->create(['company_id' => $this->company->id, 'entidad' => 'SUNAT']);
    Notificacion::factory()->create(['company_id' => $this->company->id, 'entidad' => 'SUNAFIL']);

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}&entidad=SUNAFIL")
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.entidad', 'SUNAFIL')
        ->assertJsonPath('resumen.total', 1);
});

test('sin endpoint configurado la sincronización no importa nada', function () {
    config()->set('services.buzon.sunat_endpoint', null);
    config()->set('services.buzon.sunafil_endpoint', null);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', ['company_id' => $this->company->id])
        ->assertStatus(200)
        ->assertJsonPath('data.importadas', 0)
        ->assertJsonPath('data.fuentes', []);

    Http::assertNothingSent();
});

test('sincroniza las notificaciones del endpoint configurado', function () {
    config()->set('services.buzon.sunat_endpoint', 'https://buzon.test/api/sunat');

    Http::fake([
        'buzon.test/*' => Http::response([
            'data' => [
                ['entidad' => 'SUNAT', 'codigo' => 'N-1', 'asunto' => 'Esquela', 'fecha' => '2026-11-09'],
                ['entidad' => 'SUNAT', 'codigo' => 'N-2', 'asunto' => 'Orden de fiscalización', 'fecha' => '2026-11-08'],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', ['company_id' => $this->company->id])
        ->assertStatus(200)
        ->assertJsonPath('data.importadas', 2);

    expect(Notificacion::where('company_id', $this->company->id)->count())->toBe(2);
    $this->assertDatabaseHas('notificaciones', ['codigo' => 'N-1', 'origen' => 'sincronizada']);

    // Re-sincronizar no duplica ni cuenta como nueva.
    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', ['company_id' => $this->company->id])
        ->assertStatus(200)
        ->assertJsonPath('data.importadas', 0);

    expect(Notificacion::where('company_id', $this->company->id)->count())->toBe(2);
});

test('no sincroniza el buzón de una empresa ajena', function () {
    $otra = Company::factory()->create();
    config()->set('services.buzon.sunat_endpoint', 'https://buzon.test/api/sunat');
    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$otra->id}")
        ->assertStatus(403);
});

test('adjunta y descarga el expediente de una notificación', function () {
    Storage::fake('public');

    $notificacion = Notificacion::factory()->create(['company_id' => $this->company->id]);

    $archivo = UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf');

    $this->actingAs($this->user)
        ->post("/api/v1/notificaciones/{$notificacion->id}/adjuntos", ['archivo' => $archivo], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.tiene_adjunto', true);

    $notificacion->refresh();
    expect($notificacion->adjunto_path)->not->toBeNull();
    Storage::disk('public')->assertExists($notificacion->adjunto_path);

    $this->actingAs($this->user)
        ->get("/api/v1/notificaciones/{$notificacion->id}/adjunto")
        ->assertStatus(200)
        ->assertHeader('content-disposition');
});

test('rechaza adjuntos que no son PDF ni imagen', function () {
    Storage::fake('public');

    $notificacion = Notificacion::factory()->create(['company_id' => $this->company->id]);
    $archivo = UploadedFile::fake()->create('script.exe', 5, 'application/octet-stream');

    $this->actingAs($this->user)
        ->post("/api/v1/notificaciones/{$notificacion->id}/adjuntos", ['archivo' => $archivo], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect($notificacion->refresh()->tiene_adjunto)->toBeFalse();
});

test('no adjunta a la notificación de una empresa ajena', function () {
    Storage::fake('public');

    $otra = Company::factory()->create();
    $notificacion = Notificacion::factory()->create(['company_id' => $otra->id]);
    $archivo = UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf');

    $this->actingAs($this->user)
        ->post("/api/v1/notificaciones/{$notificacion->id}/adjuntos", ['archivo' => $archivo], ['Accept' => 'application/json'])
        ->assertStatus(403);
});

test('avisa en el listado cuando el certificado está por vencer', function () {
    config()->set('services.buzon.cert_alerta_dias', 30);

    $this->company->update(['certificado_pem' => pemValidoPorDias(10)]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('alertas.certificado.tiene', true)
        ->assertJsonPath('alertas.certificado.por_vencer', true)
        ->assertJsonPath('alertas.certificado.vencido', false);
});

test('marca el certificado como vencido cuando ya caducó', function () {
    $this->company->update(['certificado_pem' => pemValidoPorDias(5)]);

    Carbon::setTestNow(now()->addDays(10));

    try {
        $this->actingAs($this->user)
            ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
            ->assertStatus(200)
            ->assertJsonPath('alertas.certificado.vencido', true)
            ->assertJsonPath('alertas.certificado.por_vencer', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('sin certificado no hay alerta de vencimiento', function () {
    $this->company->update(['certificado_pem' => null]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('alertas.certificado.tiene', false);
});
