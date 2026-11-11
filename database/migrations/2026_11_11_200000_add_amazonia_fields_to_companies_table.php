<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beneficio de exoneración del IGV de la Ley N° 27037 (Amazonía).
     *
     * La PWA ya envía `contribuyente_amazonia` y `tipo_amazonia` al guardar los
     * accesos SOL, pero la tabla `companies` no los tenía: el backend los
     * descartaba. `tipo_amazonia` usa el catálogo de leyendas SUNAT
     * (2001 bienes, 2002 servicios, 2003 contratos de construcción).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'contribuyente_amazonia')) {
                $table->boolean('contribuyente_amazonia')->default(false)->after('modo_produccion');
            }
            if (!Schema::hasColumn('companies', 'tipo_amazonia')) {
                $table->string('tipo_amazonia', 4)->nullable()->after('contribuyente_amazonia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['tipo_amazonia', 'contribuyente_amazonia'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
