<?php

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SirePropuesta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create([
        'ruc' => '20614684772',
        'razon_social' => 'INMOA ERP & FACTURACIÓN S.A.C.',
    ]);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->client = Client::factory()->create([
        'company_id' => $this->company->id,
        'tipo_documento' => '6',
        'numero_documento' => '20512345678',
        'razon_social' => 'INVERSIONES PLUMÍFERO E.I.R.L.',
    ]);
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

function crearFacturaPrueba(Company $company, Branch $branch, Client $client, string $correlativo, string $fecha): Invoice
{
    return Invoice::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'tipo_documento' => '01',
        'serie' => 'F001',
        'correlativo' => $correlativo,
        'numero_completo' => "F001-{$correlativo}",
        'fecha_emision' => $fecha,
        'moneda' => 'PEN',
        'mto_oper_gravadas' => 1000,
        'mto_igv' => 180,
        'mto_imp_venta' => 1180,
        'detalles' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'valor_unitario' => 1000]],
        'estado_sunat' => 'ACEPTADO',
    ]);
}

test('genera la propuesta RVIE con los comprobantes del periodo y la archiva', function () {
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000142', '2026-11-10');
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000143', '2026-11-20');

    $res = $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.libro', 'ventas')
        ->assertJsonPath('data.periodo', '2026-11')
        ->assertJsonPath('data.registros', 2);

    $contenido = $res->json('data.contenido');
    expect($contenido)->toContain('20614684772')
        ->and($contenido)->toContain('20512345678')
        ->and($contenido)->toContain('F001');

    // Queda archivada en el repositorio.
    $this->assertDatabaseHas('sire_propuestas', [
        'company_id' => $this->company->id,
        'libro' => 'ventas',
        'periodo' => '2026-11',
        'registros' => 2,
    ]);
});

test('no duplica: regenerar el mismo periodo reemplaza la propuesta anterior', function () {
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000142', '2026-11-10');
    $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200);

    // Se agrega un comprobante y se vuelve a generar.
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000143', '2026-11-20');
    $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.registros', 2);

    expect(SirePropuesta::where('company_id', $this->company->id)
        ->where('libro', 'ventas')
        ->where('periodo', '2026-11')
        ->count())->toBe(1);

    $this->assertDatabaseHas('sire_propuestas', [
        'company_id' => $this->company->id,
        'libro' => 'ventas',
        'periodo' => '2026-11',
        'registros' => 2,
    ]);
});

test('excluye comprobantes de otro periodo', function () {
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000142', '2026-11-10');
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000200', '2026-10-15');

    $this->actingAs($this->user)
        ->getJson("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11&preview=1")
        ->assertStatus(200)
        ->assertJsonPath('data.registros', 1);
});

test('el endpoint de descarga devuelve el TXT con el nombre oficial', function () {
    crearFacturaPrueba($this->company, $this->branch, $this->client, '00000142', '2026-11-10');

    $res = $this->actingAs($this->user)
        ->get("/api/v1/sire/ventas?company_id={$this->company->id}&periodo=2026-11")
        ->assertStatus(200);

    expect($res->headers->get('content-disposition'))
        ->toContain('LE2061468477220261100140400021112.TXT');
});

test('archiva una propuesta subida manualmente y la lista', function () {
    $contenido = "20614684772|INMOA S.A.C.|202611|...|10/11/2026|...|01|F001|00000142||6|20512345678|CLIENTE|...|\r\n";

    $this->actingAs($this->user)
        ->postJson('/api/v1/sire/subir', [
            'company_id' => $this->company->id,
            'libro' => 'ventas',
            'periodo' => '2026-11',
            'contenido' => $contenido,
            'nombre' => 'LE2061468477220261100140400021112.TXT',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.origen', 'manual');

    $this->actingAs($this->user)
        ->getJson("/api/v1/sire?company_id={$this->company->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.0.periodo', '2026-11')
        ->assertJsonPath('data.0.origen', 'manual');
});

test('requiere autenticación', function () {
    $this->getJson('/api/v1/sire/ventas?periodo=2026-11')->assertStatus(401);
});
