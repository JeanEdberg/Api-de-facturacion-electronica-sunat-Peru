<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\BuzonService;
use Illuminate\Console\Command;

class ConfigurarClaveSol extends Command
{
    protected $signature = 'sunat:clave-sol
                            {ruc : RUC de la empresa}
                            {--usuario= : Usuario SOL (si se omite, se conserva el actual)}
                            {--probar : Probar la fuente contra SUNAT al terminar}';

    protected $description = 'Guarda la Clave SOL de una empresa sin dejarla en el historial del shell';

    public function handle(BuzonService $buzon): int
    {
        $ruc = (string) $this->argument('ruc');
        $empresa = Company::where('ruc', $ruc)->first();

        if (! $empresa) {
            $this->error("No existe una empresa con RUC {$ruc}.");
            return self::FAILURE;
        }

        $usuario = $this->option('usuario') ?: $empresa->usuario_sol;
        if (! $usuario) {
            $this->error('Falta el usuario SOL: pásalo con --usuario=MODDATOS.');
            return self::FAILURE;
        }

        // `secret()` no hace eco de lo que se escribe, así que la clave no queda
        // en el historial del shell ni en la salida de la terminal.
        $clave = (string) $this->secret('Clave SOL (no se muestra al escribir)');
        if ($clave === '') {
            $this->error('La clave está vacía. No se guardó nada.');
            return self::FAILURE;
        }

        $empresa->usuario_sol = $usuario;
        $empresa->clave_sol = $clave;
        $empresa->save();

        $this->info("Clave SOL guardada para {$empresa->razon_social} (RUC {$ruc}, usuario {$usuario}).");

        if (! $this->option('probar')) {
            return self::SUCCESS;
        }

        $this->line('Probando la fuente del buzón…');
        $resultado = $buzon->probar($empresa->fresh(), 'SUNAT');

        if ($resultado['conectado'] ?? false) {
            $total = $resultado['total'] ?? 0;
            $this->info("Conexión correcta. Notificaciones leídas: {$total}");
            return self::SUCCESS;
        }

        $this->error($resultado['message'] ?? 'No se pudo conectar con el buzón.');
        return self::FAILURE;
    }
}
