<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    Company::factory()->create();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function usuarioConEstado(string $rol, bool $activo, ?int $empresaId = 1): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $rol)->first()->id,
        'company_id' => $empresaId,
        'active' => $activo,
    ]);
}

// El guard de Sanctum recuerda al usuario resuelto entre peticiones del mismo
// test; en producción cada petición arranca limpia, así que aquí se imita eso.
function pedirCon(string $token, string $ruta = '/api/v1/auth/me')
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson($ruta);
}

test('un usuario activo entra con su token', function () {
    $user = usuarioConEstado('company_admin', true);
    $token = $user->createToken('test', ['*'])->plainTextToken;

    pedirCon($token)->assertStatus(200);
});

test('el token de una cuenta desactivada deja de servir', function () {
    $user = usuarioConEstado('company_admin', true);
    $token = $user->createToken('test', ['*'])->plainTextToken;

    pedirCon($token)->assertStatus(200);

    $user->update(['active' => false]);

    pedirCon($token)
        ->assertStatus(401)
        ->assertJsonPath('status', 'error');
});

test('el corte alcanza a cualquier endpoint autenticado, no sólo a /me', function () {
    $user = usuarioConEstado('company_admin', true);
    $token = $user->createToken('test', ['*'])->plainTextToken;
    $user->update(['active' => false]);

    pedirCon($token, '/api/v1/boletas')->assertStatus(401);
    pedirCon($token, '/api/v1/auth/users')->assertStatus(401);
});

test('el token revocado se borra y no puede reutilizarse', function () {
    $user = usuarioConEstado('company_admin', true);
    $nuevo = $user->createToken('test', ['*']);
    $token = $nuevo->plainTextToken;
    $idToken = $nuevo->accessToken->id;

    expect(PersonalAccessToken::find($idToken))->not->toBeNull();

    $user->update(['active' => false]);
    pedirCon($token)->assertStatus(401);

    expect(PersonalAccessToken::find($idToken))->toBeNull();
});

test('reactivar la cuenta no revive el token revocado', function () {
    $user = usuarioConEstado('company_admin', true);
    $token = $user->createToken('test', ['*'])->plainTextToken;

    $user->update(['active' => false]);
    pedirCon($token)->assertStatus(401);

    $user->update(['active' => true]);

    // Debe volver a iniciar sesión: el token anterior ya no existe.
    pedirCon($token)->assertStatus(401);
});

test('una cuenta desactivada no puede iniciar sesión', function () {
    $user = usuarioConEstado('company_admin', false);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(401);
});

test('una cuenta inactiva autenticada por sesión recibe 401 y no un 500', function () {
    // actingAs() adjunta un TransientToken, que no tiene delete(): el
    // middleware no debe asumir que el token es persistente.
    $user = usuarioConEstado('company_admin', false);

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('status', 'error');
});

test('el token de otra cuenta activa no se ve afectado', function () {
    $desactivado = usuarioConEstado('company_admin', true);
    $activo = usuarioConEstado('company_user', true);
    $tokenDesactivado = $desactivado->createToken('test', ['*'])->plainTextToken;
    $tokenActivo = $activo->createToken('test', ['*'])->plainTextToken;

    $desactivado->update(['active' => false]);

    pedirCon($tokenDesactivado)->assertStatus(401);
    pedirCon($tokenActivo)->assertStatus(200);
});
