<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de compras: comprobantes RECIBIDOS de proveedores. La API de
        // facturación solo emite comprobantes propios, así que los recibidos se
        // registran aquí (manual o importados desde su XML UBL).
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Identificación del documento
            $table->string('tipo_documento', 2)->default('01'); // 01,03,07,08,20...
            $table->string('serie', 10);
            $table->string('correlativo', 12);
            $table->string('numero_completo', 20);

            $table->date('fecha_emision');
            $table->date('fecha_vencimiento')->nullable();

            $table->string('moneda', 3)->default('PEN');
            $table->decimal('tipo_cambio', 10, 3)->default(1);

            // Proveedor (emisor del comprobante)
            $table->string('proveedor_tipo_doc', 1)->default('6');
            $table->string('proveedor_num_doc', 15);
            $table->string('proveedor_razon_social', 255);

            // Montos (mismos buckets que el registro de compras 8.1)
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_oper_exportacion', 12, 2)->default(0);
            $table->decimal('mto_oper_gratuitas', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('mto_isc', 12, 2)->default(0);
            $table->decimal('mto_icbper', 12, 2)->default(0);
            $table->decimal('mto_otros_tributos', 12, 2)->default(0);
            $table->decimal('mto_imp_venta', 12, 2)->default(0);

            $table->string('estado_sunat', 20)->default('ACEPTADO');
            $table->string('xml_path')->nullable();
            $table->string('observacion')->nullable();

            $table->timestamps();

            // Un mismo comprobante de un proveedor no se duplica por empresa.
            $table->unique(
                ['company_id', 'proveedor_num_doc', 'tipo_documento', 'serie', 'correlativo'],
                'compras_documento_unico'
            );
            $table->index(['company_id', 'fecha_emision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
