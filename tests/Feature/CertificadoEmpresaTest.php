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
    $user = \App\Models\User::factory()->create();
    $company = Company::factory()->create(['ruc' => '20100000005']);

    $pem = pemEmpresa(365);
    $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('cert.pem', $pem);

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
        ]);

    $company->refresh();

    expect($company->certificado_pem)->toContain('BEGIN CERTIFICATE')
        ->and($company->certificado_pem)->not->toContain('certificado/certificado.pem');
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
