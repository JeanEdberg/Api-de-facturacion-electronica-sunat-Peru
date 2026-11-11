<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buzón electrónico: notificaciones de SUNAT (SIE) y SUNAFIL por empresa.
 *
 * Se registran manualmente o se importan al sincronizar con la entidad. La
 * clave (empresa, entidad, código) evita duplicar la misma notificación al
 * volver a sincronizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('entidad', 10);          // SUNAT | SUNAFIL
            $table->string('codigo')->nullable();   // N° de notificación / expediente
            $table->string('asunto');
            $table->string('tipo')->nullable();      // Urgente, Alerta, etc.
            $table->date('fecha')->nullable();
            $table->text('detalle')->nullable();
            $table->string('url')->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente | leida
            $table->boolean('tiene_adjunto')->default(false);
            $table->string('adjunto_path')->nullable();
            $table->string('origen', 12)->default('manual');     // manual | sincronizada
            $table->timestamp('leida_en')->nullable();
            $table->timestamps();

            // La misma notificación no se duplica al re-sincronizar.
            $table->unique(['company_id', 'entidad', 'codigo']);
            $table->index(['company_id', 'estado']);
            $table->index(['company_id', 'entidad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
