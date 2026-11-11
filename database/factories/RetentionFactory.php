<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Retention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Retention>
 */
class RetentionFactory extends Factory
{
    protected $model = Retention::class;

    public function definition(): array
    {
        $correlativo = str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'proveedor_id' => Client::factory(),
            'tipo_documento' => '20',
            'serie' => 'R001',
            'correlativo' => $correlativo,
            'numero_completo' => 'R001-' . $correlativo,
            'fecha_emision' => now()->format('Y-m-d'),
            'regimen' => '01',
            'tasa' => 3.00,
            'observacion' => '',
            'imp_retenido' => 3.00,
            'imp_pagado' => 97.00,
            'moneda' => 'PEN',
            'detalles' => [
                [
                    'tipo_doc' => '01',
                    'num_doc' => 'F001-000001',
                    'fecha_emision' => now()->format('Y-m-d'),
                    'fecha_retencion' => now()->format('Y-m-d'),
                    'moneda' => 'PEN',
                    'imp_total' => 100.00,
                    'imp_pagar' => 97.00,
                    'imp_retenido' => 3.00,
                    'pagos' => [
                        ['moneda' => 'PEN', 'fecha' => now()->format('Y-m-d'), 'importe' => 97.00],
                    ],
                    'tipo_cambio' => [
                        'fecha' => now()->format('Y-m-d'),
                        'factor' => 1,
                        'moneda_obj' => 'PEN',
                        'moneda_ref' => 'PEN',
                    ],
                ],
            ],
            'estado_sunat' => 'PENDIENTE',
            'usuario_creacion' => 'SYSTEM',
        ];
    }
}
