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
        'xml_path',
        'cdr_path',
        'codigo_hash',
        'estado_sunat',
        'ticket',
        'respuesta_sunat',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_referencia' => 'date',
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

    /**
     * FileService arma la ruta con numero_completo; el resumen de bajas no lo
     * guarda como columna porque el identificador es RA-YYYYMMDD-correlativo.
     */
    public function getNumeroCompletoAttribute(): string
    {
        return 'RA-' . $this->fecha_emision->format('Ymd') . '-' . $this->correlativo;
    }
}
