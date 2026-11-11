<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');

            $table->string('tipo_documento', 2)->default('07');
            $table->string('serie', 4);
            $table->string('correlativo', 8);
            $table->string('numero_completo', 15);

            $table->string('tipo_doc_afectado', 2);
            $table->string('num_doc_afectado', 20);
            $table->string('cod_motivo', 2);
            $table->string('des_motivo', 250);

            $table->date('fecha_emision');
            $table->string('ubl_version', 3)->default('2.1');
            $table->string('moneda', 3)->default('PEN');

            $table->string('forma_pago_tipo', 20)->default('Contado');
            $table->json('forma_pago_cuotas')->nullable();

            $table->decimal('valor_venta', 12, 2)->default(0);
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_oper_gratuitas', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('mto_base_ivap', 12, 2)->default(0);
            $table->decimal('mto_ivap', 12, 2)->default(0);
            $table->decimal('mto_isc', 12, 2)->default(0);
            $table->decimal('mto_icbper', 12, 2)->default(0);
            $table->decimal('total_impuestos', 12, 2)->default(0);
            $table->decimal('mto_imp_venta', 12, 2)->default(0);

            $table->json('detalles');
            $table->json('leyendas')->nullable();
            $table->json('guias')->nullable();
            $table->json('datos_adicionales')->nullable();

            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();

            $table->string('estado_sunat', 20)->default('PENDIENTE');
            $table->text('respuesta_sunat')->nullable();
            $table->string('codigo_hash')->nullable();

            $table->string('usuario_creacion')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'serie', 'correlativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
