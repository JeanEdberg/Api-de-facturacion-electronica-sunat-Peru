<?php

namespace App\Http\Controllers\Traits;

use App\Services\FileService;
use Illuminate\Http\JsonResponse;

/**
 * Descargas de los artefactos de un comprobante (XML, CDR y PDF).
 *
 * Lo usan facturas, boletas, notas y guías para no repetir los tres métodos.
 * Si el archivo no existe se responde 404 con un mensaje claro; si el
 * documento no tiene esa ruta asignada se responde igual (no hay nada que
 * descargar).
 */
trait HandlesDocumentDownloads
{
    protected function downloadDocumentXml($document): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->respondDownload(
            fn () => app(FileService::class)->downloadXml($document),
            'XML'
        );
    }

    protected function downloadDocumentCdr($document): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->respondDownload(
            fn () => app(FileService::class)->downloadCdr($document),
            'CDR'
        );
    }

    protected function downloadDocumentStoredPdf($document, string $format = 'A4'): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->respondDownload(
            fn () => app(FileService::class)->downloadPdf($document),
            'PDF'
        );
    }

    private function respondDownload(callable $resolver, string $etiqueta)
    {
        try {
            $download = $resolver();

            if (! $download) {
                return response()->json([
                    'success' => false,
                    'message' => "{$etiqueta} no encontrado",
                ], 404);
            }

            return $download;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => "Error al descargar {$etiqueta}",
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
