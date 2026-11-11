<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sire_propuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('libro', 10);            // ventas (RVIE) | compras (RCE)
            $table->string('periodo', 7);           // AAAA-MM
            $table->string('origen', 20)->default('servidor'); // servidor | manual
            $table->string('nombre')->nullable();
            $table->longText('contenido');
            $table->unsignedInteger('registros')->default(0);
            $table->string('hash', 64)->nullable();
            $table->timestamps();

            // Una sola propuesta por empresa, libro y periodo: volver a
            // descargarla o subirla reemplaza la anterior (sin duplicados).
            $table->unique(['company_id', 'libro', 'periodo']);
            $table->index(['company_id', 'libro']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_propuestas');
    }
};
