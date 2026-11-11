<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailySummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'correlativo',
        'fecha_generacion',
        'fecha_resumen',
        'ubl_version',
        'moneda',
        'detalles',
        'estado_proceso',
        'estado_sunat',
        'ticket',
        'respuesta_sunat',
        'xml_path',
        'cdr_path',
        'codigo_hash',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_generacion' => 'date',
        'fecha_resumen' => 'date',
        'detalles' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class);
    }

    /**
     * FileService genera la ruta a partir de fecha_emision y numero_completo.
     * El resumen no los tiene como columnas, así que se exponen aquí.
     */
    public function getFechaEmisionAttribute(): string
    {
        return $this->fecha_resumen->toDateString();
    }

    public function getNumeroCompletoAttribute(): string
    {
        return 'RC-' . $this->fecha_resumen->format('Ymd') . '-' . $this->correlativo;
    }
}
