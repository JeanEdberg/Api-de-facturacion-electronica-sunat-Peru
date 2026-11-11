<?php

namespace App\Http\Controllers\Traits;

use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;

/**
 * Envío de un comprobante a SUNAT.
 *
 * Devuelve siempre la misma forma de respuesta para que el frontend pueda
 * tratarla igual en facturas, boletas, notas y guías: `success`, `data`,
 * `message` y, cuando falla, `error_code`.
 */
trait SendsToSunat
{
    /**
     * @param  callable(\App\Services\DocumentService, mixed): array  $sender
     */
    protected function sendDocumentToSunat($document, string $etiqueta, callable $sender): JsonResponse
    {
        if (($document->estado_sunat ?? null) === 'ACEPTADO') {
            return response()->json([
                'success' => false,
                'message' => "El documento ya fue enviado y aceptado por SUNAT",
            ], 400);
        }

        $result = $sender(app(DocumentService::class), $document);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => $result['document'],
                'message' => "{$etiqueta} enviada correctamente a SUNAT",
            ]);
        }

        [$code, $message] = $this->normalizeSendError($result['error'] ?? null);

        return response()->json([
            'success' => false,
            'data' => $result['document'] ?? null,
            'message' => "Error al enviar a SUNAT: {$message}",
            'error_code' => $code,
        ], 400);
    }

    /**
     * Normaliza el error de Greenter, que puede venir como objeto con
     * getCode()/getMessage() o como objeto plano con code/message.
     *
     * @return array{0:string,1:string}
     */
    private function normalizeSendError($error): array
    {
        if (! is_object($error)) {
            return ['UNKNOWN', is_string($error) ? $error : 'Error desconocido'];
        }

        $code = match (true) {
            method_exists($error, 'getCode') => $error->getCode(),
            property_exists($error, 'code') => $error->code,
            default => 'UNKNOWN',
        };

        $message = match (true) {
            method_exists($error, 'getMessage') => $error->getMessage(),
            property_exists($error, 'message') => $error->message,
            default => 'Error desconocido',
        };

        return [(string) $code, (string) $message];
    }
}
