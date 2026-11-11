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
        $serie = 'F' . fake()->numerify('###');
        $correlativo = (string) fake()->numberBetween(1, 99999999);

        return [
            'company_id' => Company::factory(),
            'tipo_documento' => '01',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'fecha_emision' => now()->toDateString(),
            'fecha_vencimiento' => null,
            'moneda' => 'PEN',
            'tipo_cambio' => 1,
            'proveedor_tipo_doc' => '6',
            'proveedor_num_doc' => '20' . fake()->unique()->numerify('#########'),
            'proveedor_razon_social' => fake()->company(),
            'proveedor_direccion' => fake()->address(),
            'mto_oper_gravadas' => 100,
            'mto_igv' => 18,
            'mto_imp_venta' => 118,
            'origen' => 'manual',
            'estado_sunat' => 'REGISTRADO',
        ];
    }
}
