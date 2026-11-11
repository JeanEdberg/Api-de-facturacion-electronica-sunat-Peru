<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoCambio extends Model
{
    protected $table = 'tipo_cambios';

    protected $fillable = [
        'fecha',
        'compra',
        'venta',
        'fuente',
    ];

    protected $casts = [
        'fecha' => 'date',
        'compra' => 'decimal:3',
        'venta' => 'decimal:3',
    ];
}
