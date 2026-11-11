<?php

namespace Database\Factories;

use App\Models\Boleta;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Boleta>
 */
class BoletaFactory extends Factory
{
    protected $model = Boleta::class;

    public function definition(): array
    {
        $correlativo = str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'client_id' => Client::factory(),
            'tipo_documento' => '03',
            'serie' => 'B001',
            'correlativo' => $correlativo,
            'numero_completo' => 'B001-' . $correlativo,
            'fecha_emision' => now()->format('Y-m-d'),
            'ubl_version' => '2.1',
            'tipo_operacion' => '0101',
            'moneda' => 'PEN',
            'metodo_envio' => 'resumen_diario',
            'valor_venta' => 100.00,
            'mto_oper_gravadas' => 100.00,
            'mto_oper_exoneradas' => 0.00,
            'mto_oper_inafectas' => 0.00,
            'mto_oper_gratuitas' => 0.00,
            'mto_igv_gratuitas' => 0.00,
            'mto_igv' => 18.00,
            'mto_base_ivap' => 0.00,
            'mto_ivap' => 0.00,
            'mto_isc' => 0.00,
            'mto_icbper' => 0.00,
            'total_impuestos' => 18.00,
            'sub_total' => 100.00,
            'mto_imp_venta' => 118.00,
            'detalles' => [
                [
                    'codigo' => 'PROD001',
                    'descripcion' => 'Producto de prueba',
                    'unidad' => 'NIU',
                    'cantidad' => 2,
                    'mto_valor_unitario' => 50.00,
                    'porcentaje_igv' => 18.00,
                    'tip_afe_igv' => '10',
                ],
            ],
            'leyendas' => null,
            'datos_adicionales' => null,
            'xml_path' => null,
            'cdr_path' => null,
            'pdf_path' => null,
            'daily_summary_id' => null,
            'estado_sunat' => 'PENDIENTE',
            'respuesta_sunat' => null,
            'codigo_hash' => null,
            'usuario_creacion' => 'SYSTEM',
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['estado_sunat' => 'ACEPTADO']);
    }
}
