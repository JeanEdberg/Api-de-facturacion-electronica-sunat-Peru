<?php

use App\Models\Company;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

test('normaliza una notificación cruda de la entidad', function () {
    $servicio = new NotificacionService;

    $datos = $servicio->normalizar([
        'id' => 'N-001',
        'titulo' => 'Esquela de fiscalización',
        'tipo' => 'Fiscalización',
        'fecha' => '2026-11-05',
        'descripcion' => 'Se le notifica...',
        'enlace' => 'https://sunat.gob.pe/x',
        'adjunto' => true,
    ], Notificacion::ENTIDAD_SUNAT);

    expect($datos)->toMatchArray([
        'codigo' => 'N-001',
        'asunto' => 'Esquela de fiscalización',
        'tipo' => 'Fiscalización',
        'fecha' => '2026-11-05',
        'detalle' => 'Se le notifica...',
        'url' => 'https://sunat.gob.pe/x',
        'tiene_adjunto' => true,
    ]);
});

test('descarta una notificación sin asunto y genera código estable sin id', function () {
    $servicio = new NotificacionService;

    expect($servicio->normalizar(['tipo' => 'x'], Notificacion::ENTIDAD_SUNAT))->toBeNull();

    $a = $servicio->normalizar(['asunto' => 'Mismo asunto'], Notificacion::ENTIDAD_SUNAT);
    $b = $servicio->normalizar(['asunto' => 'Mismo asunto'], Notificacion::ENTIDAD_SUNAT);

    expect($a['codigo'])->toBe($b['codigo']);
    expect($a['codigo'])->toStartWith('auto-');
});

test('sincroniza el buzón y deduplica por código', function () {
    Http::fake([
        'https://buzon.test/sunat*' => Http::response([
            'notificaciones' => [
                ['codigo' => 'SUN-1', 'asunto' => 'Orden de pago', 'fecha' => '2026-11-01'],
                ['codigo' => 'SUN-2', 'asunto' => 'Esquela', 'fecha' => '2026-11-02'],
            ],
        ], 200),
    ]);

    config(['sunat.buzon.endpoints.SUNAT' => 'https://buzon.test/sunat']);

    $servicio = new NotificacionService;
    $resultado = $servicio->sincronizar($this->company);

    expect($resultado['importadas'])->toBe(2);
    expect(Notificacion::where('company_id', $this->company->id)->count())->toBe(2);

    // Segunda sincronización: no duplica.
    $resultado2 = $servicio->sincronizar($this->company);

    expect($resultado2['importadas'])->toBe(0);
    expect($resultado2['actualizadas'])->toBe(2);
    expect(Notificacion::where('company_id', $this->company->id)->count())->toBe(2);
});

test('sin endpoint configurado la sincronización no falla y no crea nada', function () {
    config(['sunat.buzon.endpoints.SUNAT' => null, 'sunat.buzon.endpoints.SUNAFIL' => null]);

    $servicio = new NotificacionService;
    $resultado = $servicio->sincronizar($this->company);

    expect($resultado['importadas'])->toBe(0);
    expect(Notificacion::count())->toBe(0);
});

test('lista las notificaciones de la empresa del usuario', function () {
    Notificacion::factory()->create(['company_id' => $this->company->id, 'asunto' => 'Propia']);
    Notificacion::factory()->create(['asunto' => 'De otra empresa']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/notificaciones');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.asunto', 'Propia');
});

test('el resumen respeta el filtro de entidad', function () {
    Notificacion::factory()->create(['company_id' => $this->company->id, 'entidad' => 'SUNAT', 'estado' => 'pendiente']);
    Notificacion::factory()->create(['company_id' => $this->company->id, 'entidad' => 'SUNAFIL', 'estado' => 'pendiente']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/notificaciones?entidad=SUNAT')
        ->assertOk()
        ->assertJsonPath('resumen.total', 1)
        ->assertJsonPath('resumen.pendientes', 1);

    $this->actingAs($this->user)
        ->getJson('/api/v1/notificaciones')
        ->assertOk()
        ->assertJsonPath('resumen.total', 2);
});

test('registra una notificación manualmente y evita duplicados', function () {
    $payload = [
        'company_id' => $this->company->id,
        'entidad' => 'SUNAFIL',
        'codigo' => 'SU-9',
        'asunto' => 'Requerimiento de información',
        'fecha' => '2026-11-03',
    ];

    $this->actingAs($this->user)->postJson('/api/v1/notificaciones', $payload)
        ->assertCreated()
        ->assertJsonPath('data.codigo', 'SU-9');

    // Mismo código: se actualiza, no se duplica.
    $this->actingAs($this->user)->postJson('/api/v1/notificaciones', $payload)->assertOk();

    expect(Notificacion::where('company_id', $this->company->id)->count())->toBe(1);
});

test('marca una notificación como leída', function () {
    $notificacion = Notificacion::factory()->create([
        'company_id' => $this->company->id,
        'estado' => Notificacion::ESTADO_PENDIENTE,
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/marcar-leida")
        ->assertOk()
        ->assertJsonPath('data.estado', Notificacion::ESTADO_LEIDA);

    expect($notificacion->fresh()->leida_at)->not->toBeNull();
});

test('no permite acceder a notificaciones de otra empresa', function () {
    $otra = Company::factory()->create();
    $notificacion = Notificacion::factory()->create(['company_id' => $otra->id]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/notificaciones/{$notificacion->id}/marcar-leida")
        ->assertForbidden();
});

test('requiere autenticación', function () {
    $this->getJson('/api/v1/notificaciones')->assertUnauthorized();
});
