<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Company::factory()->create();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function usuarioUsuarios(string $rol, ?int $empresaId = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $rol)->first()->id,
        'company_id' => $empresaId,
        'active' => true,
    ]);
}

function datosUsuarioNuevo(array $overrides = []): array
{
    return array_merge([
        'name' => 'Operador Nuevo',
        'email' => 'operador@empresa.com',
        'password' => 'ClaveSegura123',
        'role_name' => 'company_user',
        'user_type' => 'user',
    ], $overrides);
}

test('el admin de empresa crea usuarios en su propia empresa', function () {
    $admin = usuarioUsuarios('company_admin', 1);

    $this->actingAs($admin)
        ->postJson('/api/v1/auth/create-user', datosUsuarioNuevo(['company_id' => 1]))
        ->assertStatus(200)
        ->assertJsonPath('user.email', 'operador@empresa.com');

    expect(User::where('email', 'operador@empresa.com')->first()->company_id)->toBe(1);
});

test('el admin de empresa no puede crear usuarios en otra empresa', function () {
    $otra = Company::factory()->create();
    $admin = usuarioUsuarios('company_admin', 1);

    $this->actingAs($admin)
        ->postJson('/api/v1/auth/create-user', datosUsuarioNuevo(['company_id' => $otra->id]))
        ->assertStatus(403);

    expect(User::where('email', 'operador@empresa.com')->exists())->toBeFalse();
});

test('el admin de empresa no puede repartir el rol super_admin', function () {
    $admin = usuarioUsuarios('company_admin', 1);

    $this->actingAs($admin)
        ->postJson('/api/v1/auth/create-user', datosUsuarioNuevo([
            'company_id' => 1,
            'role_name' => 'super_admin',
        ]))
        ->assertStatus(403);
});

test('el admin de empresa no puede crear cuentas de alcance global', function () {
    $admin = usuarioUsuarios('company_admin', 1);

    foreach (['system', 'api_client'] as $tipo) {
        $this->actingAs($admin)
            ->postJson('/api/v1/auth/create-user', datosUsuarioNuevo([
                'company_id' => 1,
                'user_type' => $tipo,
            ]))
            ->assertStatus(403);
    }

    expect(User::where('email', 'operador@empresa.com')->exists())->toBeFalse();
});

test('un usuario raso no puede crear usuarios', function () {
    $usuario = usuarioUsuarios('company_user', 1);

    $this->actingAs($usuario)
        ->postJson('/api/v1/auth/create-user', datosUsuarioNuevo(['company_id' => 1]))
        ->assertStatus(403);
});

test('el listado de usuarios se limita a la empresa del admin', function () {
    $otra = Company::factory()->create();
    usuarioUsuarios('company_user', $otra->id);
    $admin = usuarioUsuarios('company_admin', 1);

    $respuesta = $this->actingAs($admin)->getJson('/api/v1/auth/users')->assertStatus(200);

    $empresas = collect($respuesta->json('items'))->pluck('company_id')->unique()->values();
    expect($empresas->all())->toBe([1]);
});

test('el super admin ve usuarios de todas las empresas', function () {
    $otra = Company::factory()->create();
    usuarioUsuarios('company_user', $otra->id);
    $super = usuarioUsuarios('super_admin');

    $respuesta = $this->actingAs($super)->getJson('/api/v1/auth/users')->assertStatus(200);

    $empresas = collect($respuesta->json('items'))->pluck('company_id')->filter()->unique()->values();
    expect($empresas->count())->toBeGreaterThan(1);
});

test('el admin de empresa no ve el rol super_admin entre los asignables', function () {
    $admin = usuarioUsuarios('company_admin', 1);

    $roles = collect(
        $this->actingAs($admin)->getJson('/api/v1/auth/roles')->assertStatus(200)->json('items')
    )->pluck('name');

    expect($roles)->not->toContain('super_admin')
        ->and($roles)->toContain('company_user');
});

test('el super admin sí puede asignar super_admin', function () {
    $super = usuarioUsuarios('super_admin');

    $roles = collect(
        $this->actingAs($super)->getJson('/api/v1/auth/roles')->assertStatus(200)->json('items')
    )->pluck('name');

    expect($roles)->toContain('super_admin');
});
