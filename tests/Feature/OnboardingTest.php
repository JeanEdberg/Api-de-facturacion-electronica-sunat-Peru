<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El seeder de roles crea usuarios de ejemplo que asumen la empresa id 1.
    Company::factory()->create();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function datosRegistro(array $overrides = []): array
{
    return array_merge([
        'name' => 'Juan Pérez',
        'email' => 'juan@nuevaempresa.com',
        'password' => 'ClaveSegura123',
        'ruc' => '20987654321',
        'razon_social' => 'NUEVA EMPRESA S.A.C.',
        'direccion' => 'AV. SIEMPRE VIVA 123',
        'ubigeo' => '150101',
        'distrito' => 'LIMA',
        'provincia' => 'LIMA',
        'departamento' => 'LIMA',
        'email_empresa' => 'contacto@nuevaempresa.com',
        'usuario_sol' => 'NUEVOUSER',
        'clave_sol' => 'NUEVOPASS',
    ], $overrides);
}

test('una empresa se registra sola y queda lista para emitir', function () {
    $response = $this->postJson('/api/auth/register', datosRegistro())
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('company.ruc', '20987654321')
        ->assertJsonPath('company.has_certificate', false);

    // Devuelve token utilizable de inmediato.
    expect($response->json('access_token'))->not->toBeEmpty();

    // Se crearon empresa, sucursal principal y usuario admin.
    $company = Company::where('ruc', '20987654321')->first();
    expect($company)->not->toBeNull()
        ->and($company->branches()->count())->toBe(1);

    $user = User::where('email', 'juan@nuevaempresa.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->company_id)->toBe($company->id)
        ->and($user->role->name)->toBe('company_admin');
});

test('el registro con certificado lo guarda como contenido PEM de esa empresa', function () {
    $pem = pemRegistro(365);
    $ruta = tempnam(sys_get_temp_dir(), 'cert') . '.pem';
    file_put_contents($ruta, $pem);
    $archivo = new \Illuminate\Http\UploadedFile($ruta, 'cert.pem', 'text/plain', null, true);

    $this->post('/api/auth/register', datosRegistro([
        'ruc' => '20987654322',
        'email' => 'cert@nuevaempresa.com',
        'certificado_pem' => $archivo,
    ]))->assertStatus(201)
        ->assertJsonPath('company.has_certificate', true);

    $company = Company::where('ruc', '20987654322')->first();
    expect($company->certificado_pem)->toContain('BEGIN CERTIFICATE')
        ->and($company->certificadoContenido())->toContain('BEGIN CERTIFICATE');

    @unlink($ruta);
});

test('no permite registrar dos empresas con el mismo RUC', function () {
    Company::factory()->create(['ruc' => '20111111111']);

    $this->postJson('/api/auth/register', datosRegistro(['ruc' => '20111111111']))
        ->assertStatus(422);
});

test('no permite registrar un correo ya usado', function () {
    User::factory()->create(['email' => 'ocupado@empresa.com']);

    $this->postJson('/api/auth/register', datosRegistro(['email' => 'ocupado@empresa.com']))
        ->assertStatus(422);
});

test('el usuario registrado puede iniciar sesión con su token', function () {
    $this->postJson('/api/auth/register', datosRegistro([
        'email' => 'login@nuevaempresa.com',
        'ruc' => '20987654323',
    ]))->assertStatus(201);

    $this->postJson('/api/auth/login', [
        'email' => 'login@nuevaempresa.com',
        'password' => 'ClaveSegura123',
    ])->assertStatus(200)
        ->assertJsonPath('user.email', 'login@nuevaempresa.com');
});

function pemRegistro(int $dias): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'NUEVA EMPRESA'], $key);
    $cert = openssl_csr_sign($csr, null, $key, $dias);
    openssl_x509_export($cert, $certOut);
    openssl_pkey_export($key, $keyOut);

    return $keyOut."\n".$certOut;
}
