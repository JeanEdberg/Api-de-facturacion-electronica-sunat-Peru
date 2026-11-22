<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('proveedor_id')->constrained('clients')->onDelete('cascade');

            $table->string('serie', 4);
            $table->string('correlativo', 8);
            $table->date('fecha_emision');

            $table->string('regimen', 4);
            $table->decimal('tasa', 5, 2);
            $table->string('observacion', 250)->default('');

            $table->decimal('imp_retenido', 12, 2)->default(0);
            $table->decimal('imp_pagado', 12, 2)->default(0);
            $table->string('moneda', 3)->default('PEN');

            $table->json('detalles');

            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();

            $table->string('estado_sunat', 20)->default('PENDIENTE');
            $table->text('respuesta_sunat')->nullable();
            $table->string('hash_cdr')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'serie', 'correlativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retentions');
    }
};
