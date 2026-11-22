<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoidedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'tipo_documento',
        'correlativo',
        'fecha_emision',
        'fecha_referencia',
        'ubl_version',
        'detalles',
        'motivo_baja',
        'total_documentos',
        'estado_sunat',
        'ticket',
        'respuesta_sunat',
        'codigo_hash',
        'xml_path',
        'cdr_path',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_referencia' => 'date',
        'detalles' => 'array',
        'total_documentos' => 'integer',
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

    public function getNumeroCompletoAttribute(): string
    {
        return 'RA-' . $this->fecha_referencia?->format('Ymd') . '-' . $this->correlativo;
    }
}
