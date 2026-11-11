<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'tipo_documento' => 'required|string|max:2',
            'serie' => 'required|string|max:4',
            'correlativo' => 'required|string|max:8',
            'fecha_emision' => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha_emision',
            'moneda' => 'nullable|string|in:PEN,USD',
            'tipo_cambio' => 'nullable|numeric|min:0',

            // Proveedor
            'proveedor_tipo_doc' => 'required|string|in:1,4,6,0',
            'proveedor_num_doc' => 'required|string|max:15',
            'proveedor_razon_social' => 'required|string|max:255',
            'proveedor_direccion' => 'nullable|string|max:255',

            // Importes
            'mto_oper_gravadas' => 'nullable|numeric|min:0',
            'mto_oper_exoneradas' => 'nullable|numeric|min:0',
            'mto_oper_inafectas' => 'nullable|numeric|min:0',
            'mto_oper_exportacion' => 'nullable|numeric|min:0',
            'mto_igv' => 'nullable|numeric|min:0',
            'mto_isc' => 'nullable|numeric|min:0',
            'mto_icbper' => 'nullable|numeric|min:0',
            'mto_otros_tributos' => 'nullable|numeric|min:0',
            'mto_imp_venta' => 'required|numeric|min:0',

            'detalles' => 'nullable|array',
            'origen' => 'nullable|string|in:manual,xml,historico',
        ];
    }
}
