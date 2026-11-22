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
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_generacion' => 'date',
        'fecha_resumen' => 'date',
        'detalles' => 'array',
    ];

    protected $appends = ['numero_completo'];

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

    public function getNumeroCompletoAttribute(): string
    {
        return 'RC-' . $this->fecha_resumen?->format('Ymd') . '-' . $this->correlativo;
    }
}
