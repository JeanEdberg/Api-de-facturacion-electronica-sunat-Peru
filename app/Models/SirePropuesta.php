<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta SIRE (RVIE/RCE) archivada por periodo.
 *
 * El repositorio guarda una sola propuesta por empresa, libro y periodo: volver
 * a descargarla o subirla reemplaza la anterior, de modo que nunca se acumulan
 * versiones duplicadas del mismo mes.
 */
class SirePropuesta extends Model
{
    protected $table = 'sire_propuestas';

    protected $fillable = [
        'company_id',
        'libro',
        'periodo',
        'origen',
        'nombre',
        'contenido',
        'registros',
        'hash',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
