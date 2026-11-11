<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'mto_oper_gravadas',
        'mto_oper_exoneradas',
        'mto_oper_inafectas',
        'mto_oper_exportacion',
        'mto_oper_gratuitas',
        'mto_igv',
        'mto_isc',
        'mto_icbper',
        'mto_otros_tributos',
        'mto_imp_venta',
        'estado_sunat',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'observacion',
    ];

    /**
     * Expone la contraparte anidada con el vocabulario que ya usa el frontend
     * (doc.proveedor.{tipo_documento,numero_documento,razon_social}).
     */
    protected $appends = ['proveedor'];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'tipo_cambio' => 'decimal:3',
        'mto_oper_gravadas' => 'decimal:2',
        'mto_oper_exoneradas' => 'decimal:2',
        'mto_oper_inafectas' => 'decimal:2',
        'mto_oper_exportacion' => 'decimal:2',
        'mto_oper_gratuitas' => 'decimal:2',
        'mto_igv' => 'decimal:2',
        'mto_isc' => 'decimal:2',
        'mto_icbper' => 'decimal:2',
        'mto_otros_tributos' => 'decimal:2',
        'mto_imp_venta' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getProveedorAttribute(): array
    {
        return [
            'tipo_documento' => $this->proveedor_tipo_doc,
            'numero_documento' => $this->proveedor_num_doc,
            'razon_social' => $this->proveedor_razon_social,
        ];
    }
}
