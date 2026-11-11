<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La API no persiste el tipo de cambio de cada comprobante y SUNAT solo
     * publica la tasa del día, sin histórico accesible. Por eso se guarda una
     * fila por fecha: el comando diario la va acumulando y así queda histórico
     * para los libros de periodos ya cerrados.
     */
    public function up(): void
    {
        Schema::create('tipo_cambios', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('compra', 8, 3)->nullable();
            $table->decimal('venta', 8, 3)->nullable();
            $table->string('fuente', 30)->default('sunat');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_cambios');
    }
};
