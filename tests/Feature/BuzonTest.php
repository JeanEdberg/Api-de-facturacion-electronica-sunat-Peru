<?php

use App\Models\Company;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

test('guarda la fuente del buzón por empresa y no expone los secretos', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/fuente', [
            'company_id' => $this->company->id,
            'buzon_sunat_endpoint' => 'https://puente.test/buzon',
            'buzon_sunafil_endpoint' => 'https://puente.test/buzon-sunafil',
            'buzon_token' => 'token-del-puente',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'clave-real-sol',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.configurado', true)
        ->assertJsonPath('data.sunat', true)
        ->assertJsonPath('data.token', true)
        ->assertJsonPath('data.sol', true)
        ->assertJsonMissingPath('data.clave_sol')
        ->assertJsonMissingPath('data.buzon_token');

    $company = $this->company->fresh();
    expect($company->buzon_sunat_endpoint)->toBe('https://puente.test/buzon');
    expect($company->usuario_sol)->toBe('MODDATOS');
    expect($company->clave_sol)->toBe('clave-real-sol');
    expect($company->buzon_token)->toBe('token-del-puente');

    // El token queda cifrado en la base, no en claro.
    $crudo = DB::table('companies')->where('id', $company->id)->value('buzon_token');
    expect($crudo)->not->toBe('token-del-puente');
});

test('guardar la fuente con secretos vacíos conserva la Clave SOL actual', function () {
    $this->company->update(['clave_sol' => 'clave-original', 'buzon_token' => 'token-original']);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/fuente', [
            'company_id' => $this->company->id,
            'buzon_sunat_endpoint' => 'https://puente.test/buzon',
            'buzon_token' => '',
            'clave_sol' => '',
        ])
        ->assertStatus(200);

    $company = $this->company->fresh();
    expect($company->buzon_sunat_endpoint)->toBe('https://puente.test/buzon');
    expect($company->clave_sol)->toBe('clave-original');
    expect($company->buzon_token)->toBe('token-original');
});

test('el listado de empresas expone los endpoints y el usuario SOL sin secretos', function () {
    $this->company->update([
        'buzon_sunat_endpoint' => 'https://puente.test/buzon',
        'buzon_token' => 'token-secreto',
        'clave_sol' => 'clave-secreta',
        'usuario_sol' => 'MODDATOS',
    ]);

    $respuesta = $this->actingAs($this->user)
        ->getJson('/api/v1/companies')
        ->assertStatus(200);

    $empresa = collect($respuesta->json('data'))->firstWhere('id', $this->company->id);

    expect($empresa['buzon_sunat_endpoint'])->toBe('https://puente.test/buzon');
    expect($empresa['usuario_sol'])->toBe('MODDATOS');
    expect($empresa)->not->toHaveKey('clave_sol');
    expect($empresa)->not->toHaveKey('buzon_token');
});

test('el listado del buzón informa el estado de la fuente', function () {
    $this->company->update([
        'buzon_sunat_endpoint' => 'https://puente.test/buzon',
        'clave_sol' => 'clave-real',
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('fuente.configurado', true)
        ->assertJsonPath('fuente.sunat', true)
        ->assertJsonPath('fuente.sunafil', false)
        ->assertJsonPath('fuente.sol', true)
        ->assertJsonPath('fuente.origenes', ['empresa']);
});

test('probar la fuente informa la conexión correcta sin importar notificaciones', function () {
    $this->company->update(['buzon_sunat_endpoint' => 'https://puente.test/buzon']);

    Http::fake([
        'puente.test/*' => Http::response([
            'data' => [
                ['codigo' => 'N-1', 'asunto' => 'Esquela'],
                ['codigo' => 'N-2', 'asunto' => 'Orden de fiscalización'],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/probar-fuente', [
            'company_id' => $this->company->id,
            'entidad' => 'SUNAT',
        ])
        ->assertStatus(200)
        ->assertJsonPath('conectado', true)
        ->assertJsonPath('total', 2);

    // Probar no debe registrar nada.
    expect(Notificacion::count())->toBe(0);

    Http::assertSent(fn ($peticion) => $peticion['ruc'] === $this->company->ruc
        && $peticion['clave'] === $this->company->clave_sol);
});

test('probar la fuente muestra el motivo del puente y no el HTML de SUNAT', function () {
    $this->company->update(['buzon_sunat_endpoint' => 'https://puente.test/buzon']);

    Http::fake([
        'puente.test/*' => Http::response([
            'error' => 'SUNAT rechazó las credenciales SOL del RUC 20614684772.',
            'detalle' => '<html><body>Error de autenticación</body></html>',
        ], 502),
    ]);

    $respuesta = $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/probar-fuente', ['company_id' => $this->company->id])
        ->assertStatus(502)
        ->assertJsonPath('conectado', false);

    $mensaje = $respuesta->json('message');
    expect($mensaje)->toContain('rechazó las credenciales SOL');
    expect($mensaje)->not->toContain('<html>');
});

test('probar la fuente sin endpoint avisa que falta configurarla', function () {
    config()->set('services.buzon.sunat_endpoint', null);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/probar-fuente', ['company_id' => $this->company->id])
        ->assertStatus(502)
        ->assertJsonPath('conectado', false)
        ->assertJsonPath('total', 0);

    Http::assertNothingSent();
});

test('la fuente de la empresa gana sobre la del entorno', function () {
    config()->set('services.buzon.sunat_endpoint', 'https://entorno.test/buzon');
    $this->company->update(['buzon_sunat_endpoint' => 'https://empresa.test/buzon']);

    Http::fake(['*' => Http::response(['data' => []])]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', ['company_id' => $this->company->id])
        ->assertStatus(200);

    Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'empresa.test'));
    Http::assertNotSent(fn ($peticion) => str_contains($peticion->url(), 'entorno.test'));
});

test('sin fuente propia ni de entorno la empresa usa la del entorno y lo refleja', function () {
    config()->set('services.buzon.sunat_endpoint', 'https://entorno.test/buzon');

    $this->actingAs($this->user)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('fuente.configurado', true)
        ->assertJsonPath('fuente.origenes', ['entorno']);
});

test('no se puede configurar la fuente del buzón de una empresa ajena', function () {
    $otra = Company::factory()->create();

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/fuente', [
            'company_id' => $otra->id,
            'buzon_sunat_endpoint' => 'https://ajena.test/buzon',
            'clave_sol' => 'clave',
        ])
        ->assertStatus(403);

    expect($otra->fresh()->buzon_sunat_endpoint)->toBeNull();
});

test('no se puede probar la fuente de una empresa ajena', function () {
    $otra = Company::factory()->create();
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/probar-fuente', ['company_id' => $otra->id])
        ->assertStatus(403);

    Http::assertNothingSent();
});

test('probar la fuente con el puente caído no filtra detalles de cURL', function () {
    $this->company->update(['buzon_sunat_endpoint' => 'http://127.0.0.1:9/buzon']);

    $respuesta = $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/probar-fuente', ['company_id' => $this->company->id])
        ->assertStatus(502)
        ->assertJsonPath('conectado', false);

    $mensaje = $respuesta->json('message');
    expect($mensaje)->not->toContain('cURL error');
    expect($mensaje)->not->toContain('libcurl');
    expect($mensaje)->toContain('puente');
});

test('la clave SOL se guarda cifrada y el modelo la devuelve en claro', function () {
    $this->company->clave_sol = 'clave-real-123';
    $this->company->save();

    $crudo = DB::table('companies')->where('id', $this->company->id)->value('clave_sol');

    expect($crudo)->not->toBe('clave-real-123');
    expect($crudo)->toStartWith('eyJ'); // payload del cifrador de Laravel
    expect($this->company->fresh()->clave_sol)->toBe('clave-real-123');
});

test('la clave SOL no aparece al serializar la empresa', function () {
    $this->company->clave_sol = 'clave-real-123';
    $this->company->save();

    expect($this->company->fresh()->toArray())->not->toHaveKey('clave_sol');
});

test('el comando sunat:clave-sol guarda la clave de la empresa', function () {
    $this->artisan('sunat:clave-sol', ['ruc' => $this->company->ruc, '--usuario' => 'MODDATOS'])
        ->expectsQuestion('Clave SOL (no se muestra al escribir)', 'clave-desde-comando')
        ->assertSuccessful();

    $empresa = $this->company->fresh();
    expect($empresa->clave_sol)->toBe('clave-desde-comando');
    expect($empresa->usuario_sol)->toBe('MODDATOS');
});

test('el comando sunat:clave-sol falla con un RUC desconocido', function () {
    $this->artisan('sunat:clave-sol', ['ruc' => '20999999999'])
        ->assertFailed();
});

test('el comando sunat:clave-sol no guarda una clave vacía', function () {
    $original = $this->company->clave_sol;

    $this->artisan('sunat:clave-sol', ['ruc' => $this->company->ruc, '--usuario' => 'MODDATOS'])
        ->expectsQuestion('Clave SOL (no se muestra al escribir)', '')
        ->assertFailed();

    expect($this->company->fresh()->clave_sol)->toBe($original);
});

test('aplana las bandejas de SUNAFIL y guarda bandeja e índice', function () {
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');

    Http::fake([
        'puente.test/buzon' => Http::response([
            'notificaciones' => [
                ['codigo' => 'N-1', 'asunto' => 'Resolución'],
            ],
            'acciones_previas' => [
                ['registro' => '0000122241 - 2026', 'fecha_deposito' => '23/10/2026', 'indice' => 0],
                ['registro' => '0000122242 - 2026', 'fecha_deposito' => '24/10/2026', 'indice' => 1],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', [
            'company_id' => $this->company->id,
            'entidad' => 'SUNAFIL',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.importadas', 3);

    // Antes el aplanado dejaba las dos listas y no sus filas: importaba 0.
    expect(Notificacion::where('entidad', 'SUNAFIL')->count())->toBe(3);

    $carta = Notificacion::where('codigo', '0000122242 - 2026')->first();
    $detalle = json_decode($carta->detalle, true);

    expect($detalle['bandeja'])->toBe('acciones_previas');
    expect($detalle['indice'])->toBe(1);
});

test('sincronizar NO baja la carta: abrirla registra el acuse', function () {
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');
    Storage::fake('public');

    Http::fake([
        'puente.test/buzon' => Http::response([
            'acciones_previas' => [
                ['registro' => '0000122241 - 2026', 'fecha_deposito' => '23/10/2026', 'indice' => 0],
            ],
        ]),
        'puente.test/documento' => Http::response('%PDF-1.4 carta', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', [
            'company_id' => $this->company->id,
            'entidad' => 'SUNAFIL',
        ])
        ->assertStatus(200);

    $notificacion = Notificacion::firstOrFail();

    // Lo importante: sincronizar sólo lee. No se pulsó ningún botón, así que
    // no se registró ningún acuse.
    expect($notificacion->tiene_adjunto)->toBeFalse();
    expect($notificacion->ruta_carta)->toBeNull();
    Http::assertNotSent(fn ($peticion) => str_ends_with($peticion->url(), '/documento'));
});

test('descarga una constancia bajo demanda y la sirve como adjunto', function () {
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');
    Storage::fake('public');

    Http::fake([
        'puente.test/buzon' => Http::response(['acciones_previas' => []]),
        'puente.test/documento' => Http::response('%PDF-1.4 acuse', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $notificacion = Notificacion::create([
        'company_id' => $this->company->id,
        'entidad' => 'SUNAFIL',
        'codigo' => '0000122241 - 2026',
        'asunto' => 'Carta inductiva',
        'detalle' => json_encode(['bandeja' => 'acciones_previas', 'indice' => 0]),
        'origen' => 'sincronizada',
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/documento", [
            'tipo' => 'constancia_acuse',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.ok', true);

    Http::assertSent(fn ($peticion) => str_ends_with($peticion->url(), '/documento')
        && $peticion['tipo'] === 'constancia_acuse');

    // La constancia sí se sirve directamente: no tiene efecto legal.
    $this->actingAs($this->user)
        ->get("/api/v1/notificaciones/{$notificacion->id}/adjunto?tipo=constancia_acuse")
        ->assertStatus(200);

    // La carta, en cambio, no se sirve hasta que se abra de verdad.
    $this->actingAs($this->user)
        ->get("/api/v1/notificaciones/{$notificacion->id}/adjunto")
        ->assertStatus(409)
        ->assertJsonPath('requiere_apertura', true);
});

test('no pide el documento si la notificación no dice de qué fila salió', function () {
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');
    Http::fake();

    $notificacion = Notificacion::create([
        'company_id' => $this->company->id,
        'entidad' => 'SUNAFIL',
        'codigo' => 'X-1',
        'asunto' => 'Sin origen',
        'origen' => 'manual',
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/documento")
        ->assertStatus(502)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();
});

test('re-sincronizar no devuelve a pendiente una notificación ya leída', function () {
    config()->set('services.buzon.sunat_endpoint', 'https://buzon.test/api/sunat');

    $notificacion = Notificacion::create([
        'company_id' => $this->company->id,
        'entidad' => 'SUNAT',
        'codigo' => 'N-1',
        'asunto' => 'Esquela',
        'origen' => 'sincronizada',
        'estado' => 'leida',
        'leida_en' => now(),
    ]);

    Http::fake([
        'buzon.test/*' => Http::response([
            'data' => [
                ['entidad' => 'SUNAT', 'codigo' => 'N-1', 'asunto' => 'Esquela'],
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/notificaciones/sincronizar', ['company_id' => $this->company->id])
        ->assertStatus(200);

    // El usuario ya la había leído: sincronizar no debe deshacer eso.
    expect($notificacion->refresh()->estado)->toBe('leida');
    expect($notificacion->refresh()->leida_en)->not->toBeNull();
});

/*
 * «Abrir y notificarse»: abrir la carta registra el acuse de recibo y arranca
 * un plazo legal, así que es la única vía que la baja y exige permiso y
 * confirmación explícita.
 */

function usuarioConRol(Company $company, string $rol): User
{
    $role = \App\Models\Role::create([
        'name' => $rol,
        'display_name' => $rol,
        'is_system' => true,
        'active' => true,
    ]);

    return User::factory()->create([
        'company_id' => $company->id,
        'role_id' => $role->id,
    ]);
}

function notificacionSunafil(Company $company, array $extra = []): Notificacion
{
    return Notificacion::create(array_merge([
        'company_id' => $company->id,
        'entidad' => 'SUNAFIL',
        'codigo' => '0000122241 - 2026 - SUNAFIL/DPPR/SDPA',
        'asunto' => 'Carta inductiva',
        'detalle' => json_encode(['bandeja' => 'acciones_previas', 'indice' => 0]),
        'origen' => 'sincronizada',
        'con_acuse' => false,
    ], $extra));
}

test('abrir la carta registra el acuse y baja los tres documentos', function () {
    Storage::fake('public');
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');

    Http::fake([
        'puente.test/documento' => Http::response('%PDF-1.4 doc', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $admin = usuarioConRol($this->company, 'company_admin');
    $notificacion = notificacionSunafil($this->company);

    $this->actingAs($admin)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir", ['confirmo' => true])
        ->assertStatus(200)
        ->assertJsonPath('data.ok', true);

    $notificacion->refresh();

    expect($notificacion->con_acuse)->toBeTrue();
    expect($notificacion->apertura_solicitada_por)->toBe($admin->id);
    expect($notificacion->apertura_solicitada_en)->not->toBeNull();
    expect($notificacion->ruta_carta)->not->toBeNull();
    expect($notificacion->ruta_constancia_acuse)->not->toBeNull();

    // Y el puente recibe el registro para no pulsar la fila equivocada.
    Http::assertSent(fn ($peticion) => str_ends_with($peticion->url(), '/documento')
        && $peticion['tipo'] === 'carta'
        && $peticion['registro_esperado'] === $notificacion->codigo);
});

test('abrir sin confirmar no toca la casilla', function () {
    Storage::fake('public');
    Http::fake();

    $admin = usuarioConRol($this->company, 'company_admin');
    $notificacion = notificacionSunafil($this->company);

    $this->actingAs($admin)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir")
        ->assertStatus(422);

    Http::assertNothingSent();
    expect($notificacion->refresh()->con_acuse)->toBeFalse();
});

test('un usuario sin permiso no puede abrir la carta', function () {
    Storage::fake('public');
    Http::fake();

    $lector = usuarioConRol($this->company, 'read_only');
    $notificacion = notificacionSunafil($this->company);

    $this->actingAs($lector)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir", ['confirmo' => true])
        ->assertStatus(403);

    Http::assertNothingSent();
    expect($notificacion->refresh()->con_acuse)->toBeFalse();
});

test('no se abre dos veces una notificación que ya tiene acuse', function () {
    Storage::fake('public');
    Http::fake();

    $admin = usuarioConRol($this->company, 'company_admin');
    $notificacion = notificacionSunafil($this->company, ['con_acuse' => true]);

    $this->actingAs($admin)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir", ['confirmo' => true])
        ->assertStatus(502)
        ->assertJsonPath('data.ok', false);

    Http::assertNothingSent();
});

test('si el puente detecta que la bandeja se reordenó, no se marca el acuse', function () {
    Storage::fake('public');
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');

    Http::fake([
        'puente.test/documento' => Http::response([
            'error' => 'La bandeja cambió de orden: la fila ya no es esa notificación.',
        ], 409),
    ]);

    $admin = usuarioConRol($this->company, 'company_admin');
    $notificacion = notificacionSunafil($this->company);

    $this->actingAs($admin)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/abrir", ['confirmo' => true])
        ->assertStatus(502)
        ->assertJsonPath('data.ok', false);

    $notificacion->refresh();

    // Clave: se anotó el intento, pero NO se dio por notificada.
    expect($notificacion->con_acuse)->toBeFalse();
    expect($notificacion->apertura_solicitada_por)->toBe($admin->id);
    expect($notificacion->apertura_resultado)->toContain('error');
});

test('el aviso de plazo aparece para las notificaciones sin acuse', function () {
    $admin = usuarioConRol($this->company, 'company_admin');

    notificacionSunafil($this->company, [
        'ultimo_dia_acuse_manual' => now()->addDay()->format('Y-m-d'),
    ]);

    $this->actingAs($admin)
        ->getJson("/api/v1/notificaciones?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('alertas.acuse.0.dias_restantes', 1)
        ->assertJsonPath('alertas.acuse.0.vencido', false);
});

test('el endpoint genérico de documentos no puede abrir la carta', function () {
    Storage::fake('public');
    config()->set('services.buzon.sunafil_endpoint', 'https://puente.test/buzon');
    Http::fake();

    $lector = usuarioConRol($this->company, 'read_only');
    $notificacion = notificacionSunafil($this->company);

    // `tipo=carta` era una puerta trasera: registraba el acuse sin Gate ni
    // confirmación. Ahora ni siquiera es un valor aceptado.
    $this->actingAs($lector)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/documento", ['tipo' => 'carta'])
        ->assertStatus(422);

    Http::assertNothingSent();
    expect($notificacion->refresh()->con_acuse)->toBeFalse();
});
