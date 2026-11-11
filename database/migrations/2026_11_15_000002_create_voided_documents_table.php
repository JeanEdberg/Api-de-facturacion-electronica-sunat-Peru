<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voided_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');

            $table->string('tipo_documento', 2)->default('RA');
            $table->string('correlativo', 8);

            $table->date('fecha_emision');
            $table->date('fecha_referencia');
            $table->string('ubl_version', 3)->default('2.0');

            $table->json('detalles');
            $table->string('motivo_baja', 500);
            $table->unsignedInteger('total_documentos')->default(0);

            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('codigo_hash')->nullable();

            $table->string('estado_sunat', 20)->default('PENDIENTE');
            $table->string('ticket')->nullable();
            $table->text('respuesta_sunat')->nullable();

            $table->string('usuario_creacion')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fecha_emision', 'correlativo']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['fecha_referencia']);
            $table->index(['estado_sunat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voided_documents');
    }
};
