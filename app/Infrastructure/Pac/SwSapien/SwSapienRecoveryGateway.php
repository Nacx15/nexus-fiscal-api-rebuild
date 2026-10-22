<?php

namespace App\Infrastructure\Pac\SwSapien;

use App\Domain\Fiscal\Contracts\PacStampRecoveryGateway;
use App\Domain\Fiscal\Dto\PacRecoveryResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SwSapienRecoveryGateway implements PacStampRecoveryGateway
{
    private const API_BASE_URL =
        'https://api.test.sw.com.mx';

    /*
     * Host utilizado por los enlaces
     * de XML del data warehouse de pruebas
     * en la documentación de SW.
     *
     * No aceptamos URLs arbitrarias.
     */
    private const STORAGE_HOST =
        'smarter-datawarehouse-storage-test.s3.amazonaws.com';

    private const MAX_XML_BYTES = 8_000_000;

    public function recoverByUuid(
        string $uuid,
        string $token
    ): PacRecoveryResult {
        if (
            ! $this->validUuid($uuid)
            || trim($token) === ''
        ) {
            return PacRecoveryResult::unknown(
                'INVALID_RECOVERY_INPUT'
            );
        }

        /*
         * Sólo sandbox.
         * Control adicional independiente
         * del interruptor de timbrado.
         */
        if (
            config('services.sw_sapien.base_url')
                !== 'https://services.test.sw.com.mx'
        ) {
            return PacRecoveryResult::unknown(
                'RECOVERY_ENVIRONMENT_DISABLED'
            );
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get(
                    self::API_BASE_URL
                    .'/datawarehouse/v1/live/'
                    .strtolower($uuid)
                );
        } catch (Throwable) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_CONNECTION_FAILED'
            );
        }

        if ($response->status() !== 200) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_HTTP_ERROR'
            );
        }

        $payload = $response->json();

        if (
            ! is_array($payload)
            || ($payload['status'] ?? null) !== 'success'
            || ! is_array(
                $payload['data']['records'] ?? null
            )
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_INVALID_RESPONSE'
            );
        }

        $records = $payload['data']['records'];

        /*
         * No encontrado no significa
         * que sea seguro volver a timbrar.
         */
        if ($records === []) {
            return PacRecoveryResult::notFound();
        }

        /*
         * Esperamos un único registro para
         * el UUID consultado. Si hay
         * ambigüedad, no decidimos.
         */
        if (
            count($records) !== 1
            || ! is_array($records[0])
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_AMBIGUOUS'
            );
        }

        $record = $records[0];

        if (
            ! is_string($record['uuid'] ?? null)
            || strcasecmp(
                $record['uuid'],
                $uuid
            ) !== 0
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_UUID_MISMATCH'
            );
        }

        /*
         * Este flujo aún no gestiona
         * la cancelación de CFDI.
         * Si aparece cancelado u otro
         * estado, exige revisión.
         */
        if (
            ($record['status'] ?? null)
            !== 'Vigente'
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_REQUIRES_STATUS_REVIEW'
            );
        }

        if (
            isset($record['version'])
            && $record['version'] !== '4.0'
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_WRONG_CFDI_VERSION'
            );
        }

        $url = $record['urlXml'] ?? null;

        if (
            ! is_string($url)
            || ! $this->trustedXmlUrl($url)
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_UNTRUSTED_XML_URL'
            );
        }

        /*
         * Descarga SIN Bearer Token.
         * La URL temporal ya contiene
         * los parámetros de autorización
         * del almacenamiento de SW.
         */
        try {
            $download = Http::connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get($url);
        } catch (Throwable) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_XML_DOWNLOAD_FAILED'
            );
        }

        if ($download->status() !== 200) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_XML_HTTP_ERROR'
            );
        }

        $contents = $download->body();

        if (
            $contents === ''
            || strlen($contents) > self::MAX_XML_BYTES
        ) {
            return PacRecoveryResult::unknown(
                'PAC_RECOVERY_INVALID_XML_SIZE'
            );
        }

        return PacRecoveryResult::found(
            $uuid,
            $contents
        );
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD',
            $uuid
        ) === 1;
    }

    private function trustedXmlUrl(
        string $url
    ): bool {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            && strtolower(
                $parts['host'] ?? ''
            ) === self::STORAGE_HOST
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['fragment'])
            && str_ends_with(
                strtolower(
                    $parts['path'] ?? ''
                ),
                '.xml'
            );
    }
}
