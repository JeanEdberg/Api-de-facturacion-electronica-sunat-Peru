<?php

use App\Models\Company;
use App\Services\CertificadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Genera un PEM autofirmado (clave privada + certificado) válido por N días.
 */
function pemEmpresa(int $dias): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'EMPRESA PRUEBA'], $key);
    $cert = openssl_csr_sign($csr, null, $key, $dias);
    openssl_x509_export($cert, $certOut);
    openssl_pkey_export($key, $keyOut);

    return $keyOut."\n".$certOut;
}

test('cada empresa resuelve su propio certificado, no uno compartido', function () {
    $empresaA = Company::factory()->create([
        'ruc' => '20100000001',
        'certificado_pem' => pemEmpresa(365),
    ]);
    $empresaB = Company::factory()->create([
        'ruc' => '20100000002',
        'certificado_pem' => pemEmpresa(365),
    ]);

    expect($empresaA->certificadoContenido())->toContain('BEGIN CERTIFICATE')
        ->and($empresaB->certificadoContenido())->toContain('BEGIN CERTIFICATE')
        ->and($empresaA->certificadoContenido())->not->toBe($empresaB->certificadoContenido());
});

test('una empresa sin certificado no hereda el de otra', function () {
    Company::factory()->create([
        'ruc' => '20100000003',
        'certificado_pem' => pemEmpresa(365),
    ]);
    $sinCert = Company::factory()->create([
        'ruc' => '20100000004',
        'certificado_pem' => null,
    ]);

    expect($sinCert->certificadoContenido())->toBeNull()
        ->and($sinCert->hasCertificado())->toBeFalse();
});

test('certificadoContenido acepta una ruta legacy del disco public', function () {
    Storage::disk('public')->put('certificado/legacy.pem', pemEmpresa(365));

    $company = Company::factory()->create([
        'certificado_pem' => 'certificado/legacy.pem',
    ]);

    expect($company->certificadoContenido())->toContain('BEGIN CERTIFICATE');
});

test('subir el certificado guarda el contenido PEM en la empresa, no una ruta fija', function () {
    $company = Company::factory()->create(['ruc' => '20100000005']);
    $user = \App\Models\User::factory()->create(['company_id' => $company->id]);

    $pem = pemEmpresa(365);
    // Archivo real en disco: la validación de tipo ve el mismo contenido que
    // en producción (un .pem subido por multipart).
    $ruta = tempnam(sys_get_temp_dir(), 'inmoa') . '.pem';
    file_put_contents($ruta, $pem);
    $archivo = new \Illuminate\Http\UploadedFile($ruta, 'cert.pem', 'application/x-pem-file', null, true);

    $this->actingAs($user)
        ->put("/api/v1/companies/{$company->id}", [
            'ruc' => $company->ruc,
            'razon_social' => $company->razon_social,
            'direccion' => 'AV. EJEMPLO 123',
            'ubigeo' => '150101',
            'distrito' => 'IQUITOS',
            'provincia' => 'MAYNAS',
            'departamento' => 'LORETO',
            'email' => 'test@empresa.com',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'MODDATOS',
            'certificado_pem' => $archivo,
        ])
        ->assertOk();

    @unlink($ruta);

    $company->refresh();

    expect($company->certificado_pem)->toContain('BEGIN CERTIFICATE')
        ->and($company->certificado_pem)->not->toContain('certificado/certificado.pem');
});

test('actualizar solo las credenciales SOL no exige los datos fiscales (panel Accesos SOL)', function () {
    $company = Company::factory()->create([
        'ruc' => '20100000006',
        'modo_produccion' => true,
    ]);
    // El panel lo usa el administrador de esa misma empresa.
    $user = \App\Models\User::factory()->create(['company_id' => $company->id]);

    // Archivo real en disco: el panel sube el .pem tal cual (multipart), y así
    // la validación de tipo ve el mismo contenido que en producción.
    $ruta = tempnam(sys_get_temp_dir(), 'inmoa') . '.pem';
    file_put_contents($ruta, pemEmpresa(365));
    $archivo = new \Illuminate\Http\UploadedFile($ruta, 'inmoa.pem', 'application/x-pem-file', null, true);

    $this->actingAs($user)
        ->put("/api/v1/companies/{$company->id}", [
            'usuario_sol' => 'MODDATOS',
            'certificado_pem' => $archivo,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $company->refresh();
    @unlink($ruta);

    expect($company->certificado_pem)->toContain('BEGIN CERTIFICATE')
        ->and($company->usuario_sol)->toBe('MODDATOS')
        // La actualización parcial no debe apagar el modo producción ni borrar datos fiscales.
        ->and($company->modo_produccion)->toBeTrue()
        ->and($company->razon_social)->not->toBeNull()
        ->and($company->activo)->toBeTrue();
});

test('/auth/me expone el company_id de la sesión', function () {
    $company = Company::factory()->create(['ruc' => '20100000007']);
    $user = \App\Models\User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.company_id', $company->id);
});

test('CertificadoService lee el vencimiento del certificado de la empresa', function () {
    $company = Company::factory()->create([
        'certificado_pem' => pemEmpresa(20),
    ]);

    $estado = app(CertificadoService::class)->estado($company);

    expect($estado['tiene'])->toBeTrue()
        ->and($estado['vencido'])->toBeFalse()
        ->and($estado['por_vencer'])->toBeTrue()
        ->and($estado['dias_restantes'])->toBeLessThanOrEqual(20);
});
