<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro del padrón de Sujetos Sin Capacidad Operativa (SSCO) publicado por
 * la SUNAT. Los comprobantes emitidos por estos RUC no otorgan crédito fiscal
 * ni sustentan gasto para el Impuesto a la Renta del adquirente.
 */
class SscoPadron extends Model
{
    protected $table = 'ssco_padron';

    protected $fillable = [
        'ruc',
        'razon_social',
        'domicilio',
        'resolucion',
        'fecha_emision',
        'fecha_firme',
        'rep_legal_doc',
        'rep_legal_nombre',
        'fecha_publicacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_firme' => 'date',
        'fecha_publicacion' => 'date',
    ];
}
