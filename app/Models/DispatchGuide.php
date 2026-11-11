<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchGuide extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'client_id',
        'tipo_documento',
        'serie',
        'correlativo',
        'numero_completo',
        'fecha_emision',
        'fecha_traslado',
        'version',
        'cod_traslado',
        'des_traslado',
        'mod_traslado',
        'peso_total',
        'und_peso_total',
        'num_bultos',
        'partida',
        'llegada',
        'transportista',
        'vehiculo',
        'indicadores',
        'detalles',
        'observaciones',
        'documentos_relacionados',
        'datos_adicionales',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'estado_sunat',
        'respuesta_sunat',
        'ticket',
        'codigo_hash',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_traslado' => 'date',
        'peso_total' => 'decimal:3',
        'num_bultos' => 'integer',
        'partida' => 'array',
        'llegada' => 'array',
        'transportista' => 'array',
        'vehiculo' => 'array',
        'indicadores' => 'array',
        'detalles' => 'array',
        'documentos_relacionados' => 'array',
        'datos_adicionales' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function getDestinatarioIdAttribute(): ?int
    {
        return $this->client_id;
    }

    /** Motivo de traslado: usa la descripción enviada o el catálogo por código. */
    public function getMotivoTrasladoNameAttribute(): string
    {
        return $this->des_traslado ?: (self::MOTIVOS_TRASLADO[$this->cod_traslado] ?? 'Desconocido');
    }

    /** Modalidad de traslado según catálogo. */
    public function getModalidadTrasladoNameAttribute(): string
    {
        return self::MODALIDADES_TRASLADO[$this->mod_traslado] ?? 'Desconocido';
    }

    /** Vehículos secundarios declarados en la guía. */
    public function getVehiculosSecundariosAttribute(): array
    {
        $vehiculo = $this->vehiculo ?? [];

        if (! empty($vehiculo['vehiculos_secundarios'])) {
            return $vehiculo['vehiculos_secundarios'];
        }

        $placa = $vehiculo['placa_secundaria'] ?? null;
        if (is_array($placa)) {
            return array_map(fn ($p) => ['placa' => $p], $placa);
        }
        if (is_string($placa) && $placa !== '') {
            return [['placa' => $placa]];
        }

        return [];
    }

    public function getConductorNombresAttribute(): ?string
    {
        return data_get($this->vehiculo, 'conductor.nombres');
    }

    public function getConductorApellidosAttribute(): ?string
    {
        return data_get($this->vehiculo, 'conductor.apellidos');
    }

    public function getConductorTipoDocAttribute(): ?string
    {
        return data_get($this->vehiculo, 'conductor.tipo_doc');
    }

    public function getConductorNumDocAttribute(): ?string
    {
        return data_get($this->vehiculo, 'conductor.num_doc');
    }

    public function getConductorLicenciaAttribute(): ?string
    {
        return data_get($this->vehiculo, 'conductor.licencia');
    }

    public function getVehiculoPlacaAttribute(): ?string
    {
        return data_get($this->vehiculo, 'placa') ?? data_get($this->vehiculo, 'placa_principal');
    }

    public function getTransportistaRazonSocialAttribute(): ?string
    {
        return data_get($this->transportista, 'razon_social');
    }

    public function getTransportistaNroMtcAttribute(): ?string
    {
        return data_get($this->transportista, 'nro_mtc');
    }

    /** Catálogo de motivos de traslado (Anexo 8). */
    public const MOTIVOS_TRASLADO = [
        '01' => 'Venta',
        '02' => 'Compra',
        '03' => 'Venta con entrega a terceros',
        '04' => 'Traslado entre establecimientos de la misma empresa',
        '05' => 'Consignación',
        '06' => 'Devolución',
        '07' => 'Recojo de bienes transformados',
        '08' => 'Traslado de bienes para transformación',
        '09' => 'Traslado por emisor itinerante de comprobantes de pago',
        '10' => 'Traslado para exportación',
        '11' => 'Venta sujeta a confirmación del comprador',
        '12' => 'Traslado de mercancía extranjera',
        '13' => 'Traslado de bienes para devolución',
        '14' => 'Traslado de bienes por cambio de oficina',
        '15' => 'Traslado de bienes para exhibición',
        '16' => 'Traslado de bienes para ferias',
        '17' => 'Traslado por cuenta propia',
    ];

    /** Catálogo de modalidades de transporte. */
    public const MODALIDADES_TRASLADO = [
        '01' => 'Transporte público',
        '02' => 'Transporte privado',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($guide) {
            if (empty($guide->numero_completo)) {
                $guide->numero_completo = $guide->serie . '-' . $guide->correlativo;
            }
        });
    }
}
