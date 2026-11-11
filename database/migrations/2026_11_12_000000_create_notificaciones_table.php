<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();

            // Buzón electrónico multiempresa: cada notificación pertenece a una empresa.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Entidad que emite la notificación.
            $table->string('entidad', 10);          // SUNAT | SUNAFIL

            // Identificador de la notificación en el buzón de la entidad (evita duplicados).
            $table->string('codigo', 100)->nullable();

            $table->string('asunto');
            $table->string('tipo', 100)->nullable();
            $table->date('fecha')->nullable();

            // Estado dentro del sistema: pendiente | leida | archivada.
            $table->string('estado', 20)->default('pendiente');
            $table->boolean('tiene_adjunto')->default(false);

            $table->text('detalle')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'entidad', 'codigo']);
            $table->index(['company_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
