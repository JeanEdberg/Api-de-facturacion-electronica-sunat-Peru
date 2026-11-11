<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos del comprobante de compra: XML, CDR y PDF.
 *
 * Los proveedores no siempre envían el XML ni el CDR (a veces solo el PDF),
 * así que se guardan rutas independientes por cada artefacto y se permite
 * subirlos manualmente cuando falten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('cdr_path')->nullable()->after('xml_path');
            $table->string('pdf_path')->nullable()->after('cdr_path');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn(['cdr_path', 'pdf_path']);
        });
    }
};
