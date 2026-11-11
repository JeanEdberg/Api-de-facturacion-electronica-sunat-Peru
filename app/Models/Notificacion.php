<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';

    public const ENTIDAD_SUNAT = 'SUNAT';
    public const ENTIDAD_SUNAFIL = 'SUNAFIL';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_LEIDA = 'leida';
    public const ESTADO_ARCHIVADA = 'archivada';

    protected $fillable = [
        'company_id',
        'entidad',
        'codigo',
        'asunto',
        'tipo',
        'fecha',
        'estado',
        'tiene_adjunto',
        'detalle',
        'url',
        'leida_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'tiene_adjunto' => 'boolean',
        'leida_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeDeEmpresa($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
