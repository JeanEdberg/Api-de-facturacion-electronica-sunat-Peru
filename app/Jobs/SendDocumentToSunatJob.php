<?php

namespace App\Jobs;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use App\Services\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un comprobante a SUNAT en segundo plano con reintentos.
 *
 * Los envíos síncronos bloquean la petición y, si SUNAT responde lento o con un
 * error transitorio (timeout, 5xx, servicio no disponible), el usuario debe
 * reintentar a mano. Este job aplica una espera creciente entre intentos y solo
 * marca el documento como RECHAZADO cuando se agotan.
 */
class SendDocumentToSunatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Intentos totales antes de darse por vencido. */
    public int $tries = 5;

    /** Tiempo máximo por intento (segundos). */
    public int $timeout = 120;

    /** Espera creciente entre intentos: 30s, 2min, 5min, 10min. */
    public array $backoff = [30, 120, 300, 600];

    private const MODELOS = [
        'invoice' => Invoice::class,
        'boleta' => Boleta::class,
        'credit_note' => CreditNote::class,
        'debit_note' => DebitNote::class,
        'dispatch_guide' => DispatchGuide::class,
    ];

    public function __construct(
        public string $documentType,
        public int $documentId,
    ) {
    }

    public function handle(DocumentService $documentService): void
    {
        $modelClass = self::MODELOS[$this->documentType] ?? null;

        if ($modelClass === null) {
            Log::error('SendDocumentToSunatJob: tipo de documento desconocido', [
                'tipo' => $this->documentType,
            ]);
            return;
        }

        $documento = $modelClass::with('company')->find($this->documentId);

        if ($documento === null) {
            Log::warning('SendDocumentToSunatJob: documento no encontrado', [
                'tipo' => $this->documentType,
                'id' => $this->documentId,
            ]);
            return;
        }

        if ($documento->estado_sunat === 'ACEPTADO') {
            return;
        }

        // Las guías usan un flujo distinto (API GRE) al del resto de comprobantes.
        $result = $this->documentType === 'dispatch_guide'
            ? $documentService->sendDispatchGuideToSunat($documento)
            : $documentService->sendToSunat($documento, $this->documentType);

        if ($result['success']) {
            return;
        }

        // Errores de validación de SUNAT (códigos 2xxx/3xxx) no se arreglan
        // reintentando: se marcan como rechazados de inmediato.
        if ($this->esErrorPermanente($result['error'] ?? null)) {
            Log::warning('SendDocumentToSunatJob: rechazo definitivo de SUNAT, sin reintentos', [
                'tipo' => $this->documentType,
                'id' => $this->documentId,
            ]);
            $this->fail(new \RuntimeException($this->mensajeError($result['error'] ?? null)));
            return;
        }

        // Error transitorio: dejar que la cola aplique el backoff y reintente.
        throw new \RuntimeException(
            'Envío a SUNAT falló (reintentable): ' . $this->mensajeError($result['error'] ?? null)
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendDocumentToSunatJob: se agotaron los reintentos', [
            'tipo' => $this->documentType,
            'id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Códigos de SUNAT que representan un rechazo de negocio (no transitorio).
     */
    private function esErrorPermanente(?object $error): bool
    {
        $code = null;

        if (is_object($error)) {
            if (method_exists($error, 'getCode')) {
                $code = $error->getCode();
            } elseif (property_exists($error, 'code')) {
                $code = $error->code;
            }
        }

        return is_string($code) && preg_match('/^[23]\d{3}$/', $code) === 1;
    }

    private function mensajeError(?object $error): string
    {
        if (! is_object($error)) {
            return 'error desconocido';
        }

        if (method_exists($error, 'getMessage')) {
            return (string) $error->getMessage();
        }

        if (property_exists($error, 'message')) {
            return (string) $error->message;
        }

        return 'error desconocido';
    }
}
