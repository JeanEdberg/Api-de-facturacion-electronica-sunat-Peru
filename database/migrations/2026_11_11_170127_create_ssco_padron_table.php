<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssco_padron', function (Blueprint $table) {
            $table->id();
            $table->string('ruc', 20)->unique();
            $table->string('razon_social', 255)->nullable();
            $table->string('domicilio', 500)->nullable();
            $table->string('resolucion', 255)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_firme')->nullable();
            $table->string('rep_legal_doc', 30)->nullable();
            $table->string('rep_legal_nombre', 255)->nullable();
            $table->date('fecha_publicacion')->nullable();
            $table->timestamps();

            $table->index('fecha_publicacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssco_padron');
    }
};
