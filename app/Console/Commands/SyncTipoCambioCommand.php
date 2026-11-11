<?php

namespace App\Console\Commands;

use App\Services\TipoCambioService;
use Illuminate\Console\Command;

class SyncTipoCambioCommand extends Command
{
    protected $signature = 'sunat:sync-tipo-cambio {--fecha= : Fecha YYYY-MM-DD (por defecto, hoy)}';

    protected $description = 'Guarda el tipo de cambio del día publicado por SUNAT';

    public function handle(TipoCambioService $service): int
    {
        $cotizacion = $service->sincronizar($this->option('fecha'));

        if ($cotizacion === null) {
            $this->warn('SUNAT no publicó un tipo de cambio para la fecha indicada.');

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Tipo de cambio %s guardado: compra %s / venta %s',
            $cotizacion['fecha'],
            $cotizacion['compra'],
            $cotizacion['venta'],
        ));

        return self::SUCCESS;
    }
}
