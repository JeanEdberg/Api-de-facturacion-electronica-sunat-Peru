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
            'entidad' => fake()->randomElement(['SUNAT', 'SUNAFIL']),
            'codigo' => fake()->unique()->bothify('###-####-####'),
            'asunto' => fake()->sentence(4),
            'tipo' => fake()->randomElement(['Urgente', 'Alerta', 'Informativa']),
            'fecha' => now()->toDateString(),
            'detalle' => fake()->paragraph(),
            'url' => null,
            'estado' => 'pendiente',
            'origen' => 'manual',
        ];
    }

    public function leida(): static
    {
        return $this->state(fn () => ['estado' => 'leida', 'leida_en' => now()]);
    }
}
