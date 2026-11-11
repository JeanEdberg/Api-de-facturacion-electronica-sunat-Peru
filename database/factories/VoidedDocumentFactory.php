<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\VoidedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoidedDocument>
 */
class VoidedDocumentFactory extends Factory
{
    protected $model = VoidedDocument::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'tipo_documento' => 'RA',
            'correlativo' => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->format('Y-m-d'),
            'fecha_referencia' => now()->subDay()->format('Y-m-d'),
            'ubl_version' => '2.0',
            'detalles' => [
                [
                    'tipo_documento' => '01',
                    'serie' => 'F001',
                    'correlativo' => '000001',
                    'motivo_especifico' => 'ANULACION DE LA OPERACION',
                ],
            ],
            'motivo_baja' => 'ERROR EN LA EMISION',
            'total_documentos' => 1,
            'estado_sunat' => 'PENDIENTE',
            'usuario_creacion' => 'SYSTEM',
        ];
    }
}
