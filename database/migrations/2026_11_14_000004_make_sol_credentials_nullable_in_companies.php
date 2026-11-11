<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El onboarding self-service permite registrar la empresa antes de tener las
     * credenciales SOL (se completan después). Por eso pasan a ser opcionales.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('usuario_sol')->nullable()->change();
            $table->string('clave_sol')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('usuario_sol')->nullable(false)->change();
            $table->string('clave_sol')->nullable(false)->change();
        });
    }
};
