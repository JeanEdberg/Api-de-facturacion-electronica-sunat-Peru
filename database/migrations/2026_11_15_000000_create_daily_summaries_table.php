<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');

            // Identificación: RC-YYYYMMDD-correlativo
            $table->string('correlativo', 3);

            $table->date('fecha_generacion');
            $table->date('fecha_resumen');
            $table->string('ubl_version', 3)->default('2.1');
            $table->string('moneda', 3)->default('PEN');

            $table->json('detalles');

            $table->string('estado_proceso', 20)->default('GENERADO');
            $table->string('estado_sunat', 20)->default('PENDIENTE');
            $table->string('ticket')->nullable();
            $table->text('respuesta_sunat')->nullable();

            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('codigo_hash')->nullable();

            $table->string('usuario_creacion')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fecha_resumen', 'correlativo']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_resumen']);
            $table->index(['estado_sunat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
