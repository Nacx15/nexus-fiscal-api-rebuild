<?php

namespace App\Infrastructure\Pac\SwSapien;

use App\Domain\Fiscal\Contracts\PacStampingGateway;
use App\Domain\Fiscal\Dto\PacStampResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SwSapienStampingGateway implements PacStampingGateway
{
    private const TEST_URL =
        'https://services.test.sw.com.mx';

    private const STAMP_PATH =
        '/v4/cfdi33/stamp/v4';

    public function __construct(
        private readonly SwSapienResponseMapper $mapper
    ) {}

    public function stamp(
        string $signedXml,
        string $customId,
        string $token
    ): PacStampResult {
        $baseUrl = rtrim(
            (string) config('services.sw_sapien.base_url'),
            '/'
        );

        /*
         * Protección temporal:
         * no permitimos ambiente productivo.
         */
        if ($baseUrl !== self::TEST_URL) {
            throw new RuntimeException(
                'Only the SW Sapien sandbox is enabled.'
            );
        }

        if (
            trim($signedXml) === ''
            || strlen($signedXml) > 8_000_000
        ) {
            throw new InvalidArgumentException(
                'Invalid signed XML.'
            );
        }

        /*
         * Utilizaremos el public_id ULID
         * del FiscalStampAttempt como CustomID.
         */
        if (
            ! preg_match(
                '/^[0-9A-HJKMNP-TV-Z]{26}$/',
                $customId
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid stamping CustomID.'
            );
        }

        if (trim($token) === '') {
            throw new InvalidArgumentException(
                'PAC authentication token is required.'
            );
        }

        try {
            /*
             * attach() configura multipart.
             * No agregamos manualmente Content-Type
             * porque Guzzle genera el boundary.
             *
             * No usamos retry() para impedir
             * reenvíos HTTP automáticos.
             */
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'customid' => $customId,
                ])
                ->connectTimeout(8)
                ->timeout(25)
                ->attach(
                    'xml',
                    $signedXml,
                    'cfdi.xml',
                    ['Content-Type' => 'application/xml']
                )
                ->post(
                    $baseUrl.self::STAMP_PATH
                );
        } catch (ConnectionException) {
            return PacStampResult::unknown(
                'PAC_CONNECTION_UNCERTAIN'
            );
        } catch (Throwable $exception) {
            /*
             * No incluimos el mensaje de la
             * excepción en resultados públicos.
             */
            return PacStampResult::unknown(
                'PAC_TRANSPORT_ERROR'
            );
        }

        /*
         * Un cuerpo no JSON, vacío o inesperado
         * se clasifica como incierto.
         */
        $payload = $response->json();

        return $this->mapper->map(
            $response->status(),
            is_array($payload) ? $payload : null,
            $signedXml
        );
    }
}
