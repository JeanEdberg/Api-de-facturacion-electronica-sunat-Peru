<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajusta si necesitas lógica de autorización
    }

    public function rules(): array
    {
        $companyId = $this->route('company')->id ?? null;

        // Actualización parcial: el formulario de Accesos SOL solo envía los
        // campos de credenciales. Validamos formato cuando el campo viene, sin
        // exigir el resto de datos de la empresa.
        return [
            'ruc' => [
                'sometimes',
                'required',
                'string',
                'size:11',
                Rule::unique('companies', 'ruc')->ignore($companyId),
            ],
            'razon_social' => 'sometimes|required|string|max:255',
            'nombre_comercial' => 'nullable|string|max:255',
            'direccion' => 'sometimes|required|string|max:255',
            'ubigeo' => 'sometimes|required|string|size:6',
            'distrito' => 'sometimes|required|string|max:100',
            'provincia' => 'sometimes|required|string|max:100',
            'departamento' => 'sometimes|required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'email' => 'sometimes|required|email|max:255',
            'web' => 'nullable|url|max:255',
            'usuario_sol' => 'sometimes|required|string|max:50',
            // En la actualización puede venir vacía: el frontend no muestra la
            // clave actual y solo la envía si el usuario la cambia.
            'clave_sol' => 'nullable|string|max:100',
            'certificado_pem' => 'nullable|file|mimes:pem,crt,cer,txt|max:2048',
            'certificado_password' => 'nullable|string|max:100',
            'endpoint_beta' => 'nullable|url|max:255',
            'endpoint_produccion' => 'nullable|url|max:255',
            'modo_produccion' => 'nullable|in:true,false,1,0',
            'contribuyente_amazonia' => 'nullable|boolean',
            'tipo_amazonia' => 'nullable|in:2001,2002,2003',
            'logo_path' => 'nullable|file|mimes:png,jpeg,jpg|max:2048',
            'activo' => 'boolean'
        ];
    }
}
