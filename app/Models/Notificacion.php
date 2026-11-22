<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Services\BuzonService;

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
        'buzon_indice',
        'asunto',
        'tipo',
        'tipo_requerimiento',
        'fecha',
        'fecha_deposito',
        'fecha_efectos_deposito',
        'fecha_acuse',
        'plazo_dias',
        'fecha_limite',
        'con_acuse',
        'ultimo_dia_acuse_manual',
        'acuse_automatico_el',
        'documentos',
        'detalle',
        'url',
        'estado',
        'tiene_adjunto',
        'adjunto_path',
        'ruta_carta',
        'ruta_constancia_deposito',
        'ruta_constancia_acuse',
        'apertura_solicitada_por',
        'apertura_solicitada_en',
        'apertura_resultado',
        'origen',
        'leida_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_deposito' => 'datetime',
        'fecha_efectos_deposito' => 'date',
        'fecha_acuse' => 'datetime',
        'fecha_limite' => 'date',
        'con_acuse' => 'boolean',
        'ultimo_dia_acuse_manual' => 'date',
        'acuse_automatico_el' => 'date',
        'documentos' => 'array',
        'apertura_solicitada_en' => 'datetime',
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

    /**
     * Ruta del disco del documento pedido, o null si aún no se ha bajado.
     */
    public function rutaDocumento(string $tipo): ?string
    {
        return in_array($tipo, BuzonService::TIPOS, true)
            ? $this->{'ruta_'.$tipo}
            : null;
    }

    /**
     * Días que quedan para el último día de acuse manual.
     *
     * Negativo si ya pasó. `null` si no se pudo estimar (falta la fecha de
     * efectos del depósito), que no es lo mismo que «sin plazo».
     */
    public function diasParaAcuse(): ?int
    {
        if ($this->con_acuse || ! $this->ultimo_dia_acuse_manual) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(
            $this->ultimo_dia_acuse_manual->startOfDay(),
            false
        );
    }
}
