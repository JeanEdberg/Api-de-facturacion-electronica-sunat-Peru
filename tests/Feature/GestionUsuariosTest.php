<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->empresa = Company::factory()->create();
    Company::factory()->create();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function crearUsuario(string $rol, ?int $empresaId, bool $activo = true): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $rol)->first()->id,
        'company_id' => $empresaId,
        'active' => $activo,
    ]);
}

function comoActor(User $actor): array
{
    return [$actor, $actor->createToken('test', ['*'])->plainTextToken];
}

function enviar(string $token, string $metodo, string $ruta, array $datos = [])
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->json($metodo, $ruta, $datos);
}

describe('desactivar una cuenta', function () {
    test('el super administrador desactiva y se revocan los tokens abiertos', function () {
        $admin = crearUsuario('super_admin', null);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        $tokenObjetivo = $objetivo->createToken('test', ['*'])->plainTextToken;
        [$actor, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/active", ['active' => false])
            ->assertStatus(200)
            ->assertJsonPath('user.active', false);

        expect($objetivo->fresh()->active)->toBeFalse();
        expect($objetivo->tokens()->count())->toBe(0);

        // La sesión abierta deja de servir de inmediato.
        enviar($tokenObjetivo, 'GET', '/api/v1/auth/me')->assertStatus(401);
    });

    test('el admin de empresa desactiva a un usuario de su empresa', function () {
        $admin = crearUsuario('company_admin', $this->empresa->id);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/active", ['active' => false])
            ->assertStatus(200);

        expect($objetivo->fresh()->active)->toBeFalse();
    });

    test('el admin de empresa no puede desactivar a un usuario de otra empresa', function () {
        $admin = crearUsuario('company_admin', $this->empresa->id);
        $ajeno = crearUsuario('company_user', Company::where('id', '!=', $this->empresa->id)->value('id'));
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$ajeno->id}/active", ['active' => false])
            ->assertStatus(403);

        expect($ajeno->fresh()->active)->toBeTrue();
    });

    test('el admin de empresa no puede desactivar a un super administrador', function () {
        $admin = crearUsuario('company_admin', $this->empresa->id);
        $superAdmin = crearUsuario('super_admin', $this->empresa->id);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$superAdmin->id}/active", ['active' => false])
            ->assertStatus(403);

        expect($superAdmin->fresh()->active)->toBeTrue();
    });

    test('nadie puede desactivar su propia cuenta', function () {
        $admin = crearUsuario('super_admin', null);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$admin->id}/active", ['active' => false])
            ->assertStatus(403);

        expect($admin->fresh()->active)->toBeTrue();
    });

    test('un usuario sin rol de gestión no puede desactivar a nadie', function () {
        $normal = crearUsuario('company_user', $this->empresa->id);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        [, $tokenActor] = comoActor($normal);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/active", ['active' => false])
            ->assertStatus(403);
    });

    test('reactivar la cuenta deja entrar de nuevo', function () {
        $admin = crearUsuario('super_admin', null);
        $objetivo = crearUsuario('company_user', $this->empresa->id, false);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/active", ['active' => true])
            ->assertStatus(200);

        expect($objetivo->fresh()->active)->toBeTrue();
    });
});

describe('cambiar el rol', function () {
    test('el super administrador cambia el rol y el cambio se aplica', function () {
        $admin = crearUsuario('super_admin', null);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/role", ['role_name' => 'company_admin'])
            ->assertStatus(200)
            ->assertJsonPath('user.role', 'company_admin');

        expect($objetivo->fresh()->hasRole('company_admin'))->toBeTrue();
    });

    test('el admin de empresa no puede repartir super_admin', function () {
        $admin = crearUsuario('company_admin', $this->empresa->id);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/role", ['role_name' => 'super_admin'])
            ->assertStatus(403);

        expect($objetivo->fresh()->hasRole('super_admin'))->toBeFalse();
    });

    test('el admin de empresa no puede cambiar el rol de otra empresa', function () {
        $admin = crearUsuario('company_admin', $this->empresa->id);
        $ajeno = crearUsuario('company_user', Company::where('id', '!=', $this->empresa->id)->value('id'));
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$ajeno->id}/role", ['role_name' => 'company_admin'])
            ->assertStatus(403);
    });

    test('un rol inexistente se rechaza', function () {
        $admin = crearUsuario('super_admin', null);
        $objetivo = crearUsuario('company_user', $this->empresa->id);
        [, $tokenActor] = comoActor($admin);

        enviar($tokenActor, 'PATCH', "/api/v1/auth/users/{$objetivo->id}/role", ['role_name' => 'rol_fantasma'])
            ->assertStatus(422);
    });
});
