<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Compra;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Registro de compras: alta manual, importación del XML del proveedor y manejo
 * de los artefactos (XML/CDR/PDF) que el proveedor envía o que el usuario
 * adjunta cuando solo tiene el PDF.
 */
class CompraService
{
    public function __construct(private UblXmlReader $reader)
    {
    }

    /**
     * Alta manual: el usuario teclea los importes y la contraparte.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos): Compra
    {
        return Compra::create($datos + ['origen' => $datos['origen'] ?? 'manual']);
    }

    /**
     * Importa el XML UBL del proveedor y, si trae el documento, guarda el
     * archivo como artefacto de la compra.
     *
     * @param  array<string, mixed>  $extra  datos que el XML no trae (p. ej. tipo_cambio)
     */
    public function importarXml(Company $company, UploadedFile $file, array $extra = []): Compra
    {
        $contenido = file_get_contents($file->getRealPath());
        $datos = $this->reader->leer($contenido);

        $datos['company_id'] = $company->id;
        $datos['estado_sunat'] = 'REGISTRADO';
        $datos += $extra;

        // Reimportar el mismo comprobante actualiza en vez de duplicar.
        $compra = Compra::updateOrCreate(
            [
                'company_id' => $company->id,
                'proveedor_num_doc' => $datos['proveedor_num_doc'],
                'tipo_documento' => $datos['tipo_documento'],
                'serie' => $datos['serie'],
                'correlativo' => $datos['correlativo'],
            ],
            $datos,
        );

        $compra->update([
            'xml_path' => $this->guardar($compra, 'xml', $contenido),
        ]);

        return $compra->refresh();
    }

    /**
     * Adjunta un artefacto a una compra existente.
     *
     * @param  string  $tipo  xml | cdr | pdf
     */
    public function adjuntar(Compra $compra, string $tipo, UploadedFile $file): Compra
    {
        $campo = "{$tipo}_path";
        $anterior = $compra->{$campo};

        $ruta = $this->guardar($compra, $tipo, file_get_contents($file->getRealPath()));
        $compra->update([$campo => $ruta]);

        if ($anterior && $anterior !== $ruta) {
            Storage::disk('public')->delete($anterior);
        }

        return $compra->refresh();
    }

    /**
     * @param  string  $tipo  xml | cdr | pdf
     */
    public function rutaDescarga(Compra $compra, string $tipo): ?string
    {
        $ruta = $compra->{"{$tipo}_path"} ?? null;

        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return $ruta;
    }

    private function guardar(Compra $compra, string $tipo, string $contenido): string
    {
        $fecha = $compra->fecha_emision?->format('dmY') ?? now()->format('dmY');
        $extension = $tipo === 'cdr' ? 'zip' : $tipo;
        $prefijo = $tipo === 'cdr' ? 'R-' : '';
        $ruta = "compras/{$tipo}/{$fecha}/{$prefijo}{$compra->numero_completo}.{$extension}";

        Storage::disk('public')->put($ruta, $contenido);

        return $ruta;
    }
}
