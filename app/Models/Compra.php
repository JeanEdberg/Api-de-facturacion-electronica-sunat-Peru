<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprobante recibido de un proveedor (registro de compras).
 *
 * El proveedor se guarda desnormalizado porque la contraparte de una compra no
 * es un cliente de la empresa; así el registro es autocontenido y sobrevive a
 * cambios en el padrón de clientes.
 */
class Compra extends Model
{
    use HasFactory;

    protected $table = 'compras';

    protected $fillable = [
        'company_id',
        'tipo_documento',
        'serie',
        'correlativo',
        'numero_completo',
        'fecha_emision',
        'fecha_vencimiento',
        'moneda',
        'tipo_cambio',
        'proveedor_tipo_doc',
        'proveedor_num_doc',
        'proveedor_razon_social',
        'proveedor_direccion',
        'mto_oper_gravadas',
        'mto_oper_exoneradas',
        'mto_oper_inafectas',
        'mto_oper_exportacion',
        'mto_igv',
        'mto_isc',
        'mto_icbper',
        'mto_otros_tributos',
        'mto_imp_venta',
        'detalles',
        'origen',
        'estado_sunat',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'usuario_creacion',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'tipo_cambio' => 'decimal:3',
        'mto_oper_gravadas' => 'decimal:2',
        'mto_oper_exoneradas' => 'decimal:2',
        'mto_oper_inafectas' => 'decimal:2',
        'mto_oper_exportacion' => 'decimal:2',
        'mto_igv' => 'decimal:2',
        'mto_isc' => 'decimal:2',
        'mto_icbper' => 'decimal:2',
        'mto_otros_tributos' => 'decimal:2',
        'mto_imp_venta' => 'decimal:2',
        'detalles' => 'array',
    ];

    protected $appends = ['proveedor'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Contraparte con la forma que espera el mapeo del frontend
     * (tipo_documento / numero_documento / razon_social).
     */
    public function getProveedorAttribute(): array
    {
        return [
            'tipo_documento' => $this->proveedor_tipo_doc,
            'numero_documento' => $this->proveedor_num_doc,
            'razon_social' => $this->proveedor_razon_social,
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($compra) {
            if (empty($compra->numero_completo)) {
                $compra->numero_completo = $compra->serie . '-' . $compra->correlativo;
            }
        });
    }
}
