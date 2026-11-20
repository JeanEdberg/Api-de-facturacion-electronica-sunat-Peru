<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * La Clave SOL es la credencial con la que se entra al Menú SOL de SUNAT, así
 * que no debe quedar en texto plano. Se cifra en la propia columna: el cast
 * `encrypted` del modelo se encarga de leerla y escribirla, y aquí solo hay que
 * convertir los valores existentes.
 *
 * Se usa `encryptString`/`decryptString` (no `encrypt`/`decrypt`) porque es lo
 * que hace internamente el cast `encrypted`; con `encrypt` el valor se
 * serializaría y el modelo devolvería `s:8:"clave";` en vez de la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convertir(fn (string $valor) => Crypt::encryptString($valor));
    }

    public function down(): void
    {
        $this->convertir(fn (string $valor) => Crypt::decryptString($valor));
    }

    private function convertir(callable $transformar): void
    {
        // Se lee con el query builder para evitar el cast del modelo y no
        // cifrar dos veces un valor ya cifrado.
        DB::table('companies')
            ->whereNotNull('clave_sol')
            ->where('clave_sol', '!=', '')
            ->orderBy('id')
            ->each(function ($fila) use ($transformar) {
                DB::table('companies')
                    ->where('id', $fila->id)
                    ->update(['clave_sol' => $transformar($fila->clave_sol)]);
            });
    }
};
