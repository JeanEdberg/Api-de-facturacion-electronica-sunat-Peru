<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('gre_client_id_beta')->nullable()->after('clave_sol');
            $table->string('gre_client_secret_beta')->nullable()->after('gre_client_id_beta');
            $table->string('gre_client_id_produccion')->nullable()->after('gre_client_secret_beta');
            $table->string('gre_client_secret_produccion')->nullable()->after('gre_client_id_produccion');
            $table->string('gre_ruc_proveedor', 11)->nullable()->after('gre_client_secret_produccion');
            $table->string('gre_usuario_sol')->nullable()->after('gre_ruc_proveedor');
            $table->string('gre_clave_sol')->nullable()->after('gre_usuario_sol');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'gre_client_id_beta',
                'gre_client_secret_beta',
                'gre_client_id_produccion',
                'gre_client_secret_produccion',
                'gre_ruc_proveedor',
                'gre_usuario_sol',
                'gre_clave_sol',
            ]);
        });
    }
};
