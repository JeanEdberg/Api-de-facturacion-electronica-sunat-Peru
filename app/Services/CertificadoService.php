<?php

namespace App\Services;

use App\Models\Company;

/**
 * Estado del certificado digital de una empresa.
 *
 * Extrae la fecha de vencimiento del PEM (openssl_x509_parse) para poder
 * avisar en el buzón antes de que caduque. No guarda nada: es solo lectura.
 */
class CertificadoService
{
    /**
     * @return array{tiene: bool, vencido: bool, por_vencer: bool, vence_en: ?string, dias_restantes: ?int, sujeto: ?string, emisor: ?string}
     */
    public function estado(Company $company): array
    {
        $vacio = [
            'tiene' => false,
            'vencido' => false,
            'por_vencer' => false,
            'vence_en' => null,
            'dias_restantes' => null,
            'sujeto' => null,
            'emisor' => null,
        ];

        if (empty($company->certificado_pem)) {
            return $vacio;
        }

        $info = $this->parsear($company->certificado_pem);
        if (! $info || empty($info['validTo_time_t'])) {
            return array_merge($vacio, ['tiene' => true]);
        }

        $venceEn = \Carbon\Carbon::createFromTimestamp($info['validTo_time_t']);
        $dias = (int) now()->startOfDay()->diffInDays($venceEn->copy()->startOfDay(), false);
        $alerta = (int) config('services.buzon.cert_alerta_dias', 30);

        return [
            'tiene' => true,
            'vencido' => $dias < 0,
            'por_vencer' => $dias >= 0 && $dias <= $alerta,
            'vence_en' => $venceEn->toDateString(),
            'dias_restantes' => $dias,
            'sujeto' => $info['subject']['CN'] ?? null,
            'emisor' => $info['issuer']['CN'] ?? null,
        ];
    }

    private function parsear(string $pem): ?array
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
            return null;
        }

        return openssl_x509_parse($m[0]) ?: null;
    }
}
