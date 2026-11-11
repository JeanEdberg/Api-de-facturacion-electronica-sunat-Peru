<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Contribuyente ubicado en la Amazonía (Ley 27037): sus ventas
            // exoneradas del IGV deben llevar la leyenda correspondiente.
            $table->boolean('contribuyente_amazonia')->default(false)->after('modo_produccion');

            // Tipo de operación por defecto para la leyenda de Amazonía:
            // 2001 bienes, 2002 servicios, 2003 contratos de construcción.
            $table->string('tipo_amazonia', 4)->nullable()->after('contribuyente_amazonia');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['contribuyente_amazonia', 'tipo_amazonia']);
        });
    }
};
