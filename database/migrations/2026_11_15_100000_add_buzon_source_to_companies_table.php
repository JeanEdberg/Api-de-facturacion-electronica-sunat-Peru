<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuente del buzón configurable por empresa.
 *
 * Hasta ahora el buzón solo podía apuntar a los endpoints de `config/services`,
 * iguales para todas las empresas. Estos campos permiten que cada empresa tenga
 * su propia fuente (endpoint, token) y sus propias credenciales SOL con las que
 * el puente autentica contra el Menú SOL de SUNAT.
 *
 * El token de la fuente (`buzon_token`) se guarda cifrado y se oculta en las
 * respuestas; `clave_sol` ya existía y también está en `hidden`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('buzon_sunat_endpoint', 500)->nullable()->after('usuario_sol');
            $table->string('buzon_sunafil_endpoint', 500)->nullable()->after('buzon_sunat_endpoint');
            $table->text('buzon_token')->nullable()->after('buzon_sunafil_endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['buzon_sunat_endpoint', 'buzon_sunafil_endpoint', 'buzon_token']);
        });
    }
};
