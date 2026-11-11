<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación del buzón electrónico (SUNAT o SUNAFIL).
 */
class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';

    protected $fillable = [
        'company_id',
        'entidad',
        'codigo',
        'asunto',
        'tipo',
        'fecha',
        'detalle',
        'url',
        'estado',
        'tiene_adjunto',
        'adjunto_path',
        'origen',
        'leida_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'tiene_adjunto' => 'boolean',
        'leida_en' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }
}
