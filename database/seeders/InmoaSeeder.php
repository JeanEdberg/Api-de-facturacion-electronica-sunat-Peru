<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos reales de INMOA (RUC 20614684772) para empezar a probar la app.
 *
 * Deja una sola empresa (INMOA), su sucursal principal, el superusuario de
 * Jean Edberg y el usuario administrador de INMOA de Ericka. Es idempotente:
 * se puede ejecutar varias veces sin duplicar registros.
 */
class InmoaSeeder extends Seeder
{
    private const RUC = '20614684772';

    public function run(): void
    {
        $empresa = Company::updateOrCreate(
            ['ruc' => self::RUC],
            [
                'razon_social' => 'INMOA LOGISTICA Y SOLUCIONES E.I.R.L.',
                'nombre_comercial' => 'INMOA',
                'direccion' => 'JR. LA CULTURA MZA. J7 LOTE 1A',
                'ubigeo' => '170101',
                'distrito' => 'TAMBOPATA',
                'provincia' => 'TAMBOPATA',
                'departamento' => 'MADRE DE DIOS',
                'telefono' => '982796570',
                'email' => 'inmoalogisticaysoluciones@gmail.com',
                'usuario_sol' => env('INMOA_USUARIO_SOL', 'MODDATOS'),
                'clave_sol' => env('INMOA_CLAVE_SOL', 'moddatos'),
                'certificado_pem' => $this->leerCertificado(),
                'certificado_password' => env('INMOA_CERT_PASSWORD'),
                'modo_produccion' => false,
                'activo' => true,
            ]
        );

        Branch::updateOrCreate(
            ['company_id' => $empresa->id, 'codigo' => '0000'],
            [
                'nombre' => 'Sucursal Principal',
                'direccion' => $empresa->direccion,
                'ubigeo' => $empresa->ubigeo,
                'distrito' => $empresa->distrito,
                'provincia' => $empresa->provincia,
                'departamento' => $empresa->departamento,
                'activo' => true,
            ]
        );

        $this->crearUsuario(
            'jguevara91@inmoa.com',
            'JEAN EDBERG GUEVARA ESCOBAR',
            'super_admin',
            null,
            env('INMOA_SUPERADMIN_PASSWORD')
        );

        $this->crearUsuario(
            'inmoalogisticaysoluciones@gmail.com',
            'ERICKA CAROLINA COVEÑAS MOGOLLON',
            'company_admin',
            $empresa->id,
            env('INMOA_ADMIN_PASSWORD')
        );

        $this->command->info("INMOA lista (empresa #{$empresa->id}, certificado: "
            . ($empresa->hasCertificado() ? 'sí' : 'no') . ').');
    }

    private function crearUsuario(string $email, string $nombre, string $rol, ?int $companyId, ?string $password): void
    {
        if (empty($password)) {
            $this->command->warn("Sin contraseña en el entorno para {$email}; se omite.");
            return;
        }

        $role = Role::where('name', $rol)->firstOrFail();

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $nombre,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'company_id' => $companyId,
                'user_type' => $companyId ? 'user' : 'system',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );
    }

    private function leerCertificado(): ?string
    {
        $path = storage_path('app/private/inmoa.pem');

        return file_exists($path) ? file_get_contents($path) : null;
    }
}
