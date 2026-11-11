<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DailySummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailySummary>
 */
class DailySummaryFactory extends Factory
{
    protected $model = DailySummary::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'correlativo' => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'fecha_generacion' => now()->format('Y-m-d'),
            'fecha_resumen' => now()->format('Y-m-d'),
            'ubl_version' => '2.1',
            'moneda' => 'PEN',
            'detalles' => [
                [
                    'tipo_documento' => '03',
                    'serie_numero' => 'B001-000001',
                    'estado' => '1',
                    'cliente_tipo' => '1',
                    'cliente_numero' => '12345678',
                    'total' => 118.00,
                    'mto_oper_gravadas' => 100.00,
                    'mto_oper_exoneradas' => 0,
                    'mto_oper_inafectas' => 0,
                    'mto_oper_gratuitas' => 0,
                    'mto_igv' => 18.00,
                    'mto_isc' => 0,
                    'mto_icbper' => 0,
                ],
            ],
            'estado_proceso' => 'GENERADO',
            'estado_sunat' => 'PENDIENTE',
            'ticket' => null,
            'respuesta_sunat' => null,
            'xml_path' => null,
            'cdr_path' => null,
            'codigo_hash' => null,
            'usuario_creacion' => 'SYSTEM',
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'estado_proceso' => 'COMPLETADO',
            'estado_sunat' => 'ACEPTADO',
        ]);
    }
}
