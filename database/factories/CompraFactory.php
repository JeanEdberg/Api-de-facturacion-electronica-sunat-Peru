<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Compra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    public function definition(): array
    {
        $serie = fake()->randomElement(['F001', 'F010', 'E001']);
        $correlativo = fake()->unique()->numerify('########');
        $gravada = fake()->randomFloat(2, 100, 5000);
        $igv = round($gravada * 0.18, 2);

        return [
            'company_id' => Company::factory(),
            'tipo_documento' => '01',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero_completo' => $serie . '-' . $correlativo,
            'fecha_emision' => fake()->dateTimeBetween('-2 months')->format('Y-m-d'),
            'moneda' => 'PEN',
            'tipo_cambio' => 1,
            'proveedor_tipo_doc' => '6',
            'proveedor_num_doc' => '20' . fake()->unique()->numerify('#########'),
            'proveedor_razon_social' => fake()->company(),
            'mto_oper_gravadas' => $gravada,
            'mto_igv' => $igv,
            'mto_imp_venta' => round($gravada + $igv, 2),
            'estado_sunat' => 'ACEPTADO',
        ];
    }
}
