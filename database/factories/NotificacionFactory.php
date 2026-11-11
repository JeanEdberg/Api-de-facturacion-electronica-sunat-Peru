<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Notificacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notificacion>
 */
class NotificacionFactory extends Factory
{
    protected $model = Notificacion::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'entidad' => fake()->randomElement([Notificacion::ENTIDAD_SUNAT, Notificacion::ENTIDAD_SUNAFIL]),
            'codigo' => 'N-' . fake()->unique()->numerify('#####'),
            'asunto' => fake()->sentence(4),
            'tipo' => fake()->randomElement(['Esquela', 'Orden de pago', 'Fiscalización', 'Requerimiento']),
            'fecha' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
            'estado' => Notificacion::ESTADO_PENDIENTE,
            'tiene_adjunto' => fake()->boolean(),
            'detalle' => fake()->paragraph(),
            'url' => null,
        ];
    }

    public function leida(): static
    {
        return $this->state(fn () => [
            'estado' => Notificacion::ESTADO_LEIDA,
            'leida_at' => now(),
        ]);
    }
}
