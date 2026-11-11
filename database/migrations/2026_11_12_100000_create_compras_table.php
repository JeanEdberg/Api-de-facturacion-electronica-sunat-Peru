<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de compras: comprobantes recibidos de proveedores.
 *
 * A diferencia de los comprobantes emitidos (invoices/boletas), una compra no
 * se envía a SUNAT: se registra desde el XML UBL del proveedor o con un alta
 * manual, y alimenta el Registro de Compras (PLE 8.1 / RCE del SIRE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Identificación del documento
            $table->string('tipo_documento', 2)->default('01'); // 01 factura, 03 boleta, 07 NC, 08 ND, etc.
            $table->string('serie', 4);
            $table->string('correlativo', 8);
            $table->string('numero_completo', 15);
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('tipo_cambio', 9, 3)->default(1);

            // Proveedor (se guarda desnormalizado: la contraparte no es un cliente)
            $table->string('proveedor_tipo_doc', 2)->default('6');
            $table->string('proveedor_num_doc', 15);
            $table->string('proveedor_razon_social');
            $table->string('proveedor_direccion')->nullable();

            // Montos (mismos nombres que las tablas de emitidos para reutilizar el mapeo)
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_oper_exportacion', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('mto_isc', 12, 2)->default(0);
            $table->decimal('mto_icbper', 12, 2)->default(0);
            $table->decimal('mto_otros_tributos', 12, 2)->default(0);
            $table->decimal('mto_imp_venta', 12, 2)->default(0);

            // Detalle y trazabilidad del origen
            $table->json('detalles')->nullable();
            $table->string('origen', 12)->default('manual'); // manual | xml | historico
            $table->string('estado_sunat', 20)->default('REGISTRADO');

            // Artefactos (XML del proveedor, CDR y PDF adjuntados a mano)
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();

            $table->string('usuario_creacion')->nullable();
            $table->timestamps();

            // Un comprobante recibido se identifica por emisor y numeración: dos
            // proveedores distintos pueden usar la misma serie/correlativo.
            $table->unique(['company_id', 'proveedor_num_doc', 'tipo_documento', 'serie', 'correlativo']);
            $table->index(['company_id', 'fecha_emision']);
            $table->index(['company_id', 'proveedor_num_doc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
