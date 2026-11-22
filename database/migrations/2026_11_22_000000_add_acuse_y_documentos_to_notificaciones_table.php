<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de acuse y plazos de las notificaciones de SUNAFIL.
 *
 * Abrir la carta en la casilla registra el acuse de recibo y arranca un plazo
 * legal contra la empresa, así que esa acción necesita constancia de quién la
 * pidió y cuándo. Las fechas de plazo son estimaciones a partir de los
 * «efectos del depósito»: la fecha buena es la que publique SUNAFIL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            // Posición de la fila en la bandeja. No identifica nada: sólo sirve
            // junto a `codigo`, que el puente valida antes de pulsar.
            $table->unsignedInteger('buzon_indice')->nullable()->after('codigo');

            $table->string('tipo_requerimiento')->nullable()->after('asunto');
            $table->dateTime('fecha_deposito')->nullable()->after('fecha');
            $table->date('fecha_efectos_deposito')->nullable()->after('fecha_deposito');
            $table->dateTime('fecha_acuse')->nullable()->after('fecha_efectos_deposito');
            $table->unsignedSmallInteger('plazo_dias')->nullable()->after('fecha_acuse');
            $table->date('fecha_limite')->nullable()->after('plazo_dias');

            $table->boolean('con_acuse')->default(false)->after('fecha_limite');
            $table->date('ultimo_dia_acuse_manual')->nullable()->after('con_acuse');
            $table->date('acuse_automatico_el')->nullable()->after('ultimo_dia_acuse_manual');

            $table->json('documentos')->nullable()->after('acuse_automatico_el');

            // Una ruta por documento: la carta y las dos constancias se piden
            // por separado y cualquiera puede faltar.
            $table->string('ruta_carta')->nullable()->after('adjunto_path');
            $table->string('ruta_constancia_deposito')->nullable()->after('ruta_carta');
            $table->string('ruta_constancia_acuse')->nullable()->after('ruta_constancia_deposito');

            // Auditoría de «Abrir y notificarse». Se escribe ANTES de pulsar:
            // si la descarga falla, el acuse pudo quedar registrado igual.
            $table->unsignedBigInteger('apertura_solicitada_por')->nullable()->after('ruta_constancia_acuse');
            $table->timestamp('apertura_solicitada_en')->nullable()->after('apertura_solicitada_por');
            $table->text('apertura_resultado')->nullable()->after('apertura_solicitada_en');
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropColumn([
                'buzon_indice',
                'tipo_requerimiento',
                'fecha_deposito',
                'fecha_efectos_deposito',
                'fecha_acuse',
                'plazo_dias',
                'fecha_limite',
                'con_acuse',
                'ultimo_dia_acuse_manual',
                'acuse_automatico_el',
                'documentos',
                'ruta_carta',
                'ruta_constancia_deposito',
                'ruta_constancia_acuse',
                'apertura_solicitada_por',
                'apertura_solicitada_en',
                'apertura_resultado',
            ]);
        });
    }
};
