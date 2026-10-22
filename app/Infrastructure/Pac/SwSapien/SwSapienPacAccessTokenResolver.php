<?php

namespace App\Infrastructure\Pac\SwSapien;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Exceptions\PacCredentialsUnavailable;
use App\Models\PacAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SwSapienPacAccessTokenResolver implements PacAccessTokenResolver
{
    private const BASE_URL =
        'https://services.test.sw.com.mx';

    private const AUTH_PATH =
        '/v2/security/authenticate';

    private const EXPIRY_MARGIN = 120;

    public function __construct(
        private readonly CompanyContext $context
    ) {}

    public function resolveForCompany(
        int $companyId
    ): string {
        /*
         * Defensa multiempresa:
         * no permitimos solicitar tokens
         * para otra empresa del sistema.
         */
        if (
            $companyId <= 0
            || $this->context->id() !== $companyId
        ) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * Exclusivamente sandbox.
         * La integración productiva se
         * habilitará en otro bloque.
         */
        $baseUrl = rtrim(
            (string) config(
                'services.sw_sapien.base_url'
            ),
            '/'
        );

        if ($baseUrl !== self::BASE_URL) {
            throw new PacCredentialsUnavailable;
        }

        $account = PacAccount::query()
            ->where('company_id', $companyId)
            ->where('provider', 'sw_sapien')
            ->where('environment', 'sandbox')
            ->where('active', true)
            ->first();

        if ($account === null) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * Los tres atributos están cifrados
         * mediante casts de Eloquent.
         *
         * No llamamos a Crypt::decryptString()
         * manualmente sobre estos atributos:
         * Eloquent ya los descifra.
         */
        try {
            $storedToken = $account->token;
            $username = $account->username;
            $password = $account->password;
        } catch (DecryptException) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * Modalidad A:
         * token permanente de SW Sapien.
         *
         * Los tokens temporales no deben
         * guardarse en este campo sin fecha
         * de expiración.
         */
        if (
            is_string($storedToken)
            && trim($storedToken) !== ''
        ) {
            return $this->validatedToken(
                $storedToken
            );
        }

        /*
         * Modalidad B:
         * autenticación con usuario
         * y contraseña.
         */
        if (
            ! is_string($username)
            || ! filter_var(
                $username,
                FILTER_VALIDATE_EMAIL
            )
            || ! is_string($password)
            || trim($password) === ''
        ) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * El fingerprint cambia cuando
         * cambian las credenciales.
         *
         * Nunca utilizamos usuario o contraseña
         * en texto plano como clave de caché.
         */
        $fingerprint = hash_hmac(
            'sha256',
            $username."\0".$password,
            (string) config('app.key')
        );

        $cacheKey = implode(':', [
            'sw-sapien',
            'sandbox',
            'token',
            $companyId,
            $account->id,
            $fingerprint,
        ]);

        $cached = $this->cachedToken($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        /*
         * Evita solicitudes de autenticación
         * simultáneas para la misma cuenta.
         *
         * Necesitamos un driver de caché con
         * locks compartidos en producción.
         */
        try {
            return Cache::lock(
                $cacheKey.':lock',
                20
            )->block(5, function () use (
                $cacheKey,
                $username,
                $password
            ) {
                /*
                 * Double-check tras adquirir
                 * el lock: otra petición pudo
                 * renovar el token.
                 */
                $cached = $this->cachedToken(
                    $cacheKey
                );

                if ($cached !== null) {
                    return $cached;
                }

                return $this->authenticate(
                    $username,
                    $password,
                    $cacheKey
                );
            });
        } catch (PacCredentialsUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PacCredentialsUnavailable;
        }
    }

    private function authenticate(
        string $username,
        string $password,
        string $cacheKey
    ): string {
        try {
            /*
             * SW Authentication V2.
             *
             * No utiliza Authorization Bearer:
             * precisamente estamos obteniendo
             * el token.
             */
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(
                    self::BASE_URL.self::AUTH_PATH,
                    [
                        'user' => $username,
                        'password' => $password,
                    ]
                );
        } catch (Throwable) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * No lanzamos una HttpResponseException
         * que pueda arrastrar detalles sensibles
         * del proveedor.
         */
        if ($response->status() !== 200) {
            throw new PacCredentialsUnavailable;
        }

        $payload = $response->json();

        if (
            ! is_array($payload)
            || ($payload['status'] ?? null)
                !== 'success'
            || ! is_array($payload['data'] ?? null)
        ) {
            throw new PacCredentialsUnavailable;
        }

        $data = $payload['data'];

        $token = $data['token'] ?? null;
        $expiration = $data['expires_in'] ?? null;

        if (
            ! is_string($token)
            || (
                ! is_int($expiration)
                && ! (
                    is_string($expiration)
                    && ctype_digit($expiration)
                )
            )
        ) {
            throw new PacCredentialsUnavailable;
        }

        $token = $this->validatedToken($token);
        $expiresAt = (int) $expiration;

        /*
         * SW documenta expires_in como
         * timestamp Unix, no duración
         * relativa en segundos.
         */
        $ttl = min(
            7200,
            $expiresAt
                - now()->timestamp
                - self::EXPIRY_MARGIN
        );

        if ($ttl <= 0) {
            throw new PacCredentialsUnavailable;
        }

        /*
         * Guardamos el token CIFRADO también
         * en el backend de caché.
         */
        Cache::put(
            $cacheKey,
            Crypt::encryptString($token),
            $ttl
        );

        return $token;
    }

    private function cachedToken(
        string $cacheKey
    ): ?string {
        $encrypted = Cache::get($cacheKey);

        if ($encrypted === null) {
            return null;
        }

        if (! is_string($encrypted)) {
            Cache::forget($cacheKey);

            return null;
        }

        try {
            $token = Crypt::decryptString(
                $encrypted
            );

            return $this->validatedToken(
                $token
            );
        } catch (
            DecryptException|PacCredentialsUnavailable
        ) {
            /*
             * No reutilizamos entradas
             * corruptas o inválidas.
             */
            Cache::forget($cacheKey);

            return null;
        }
    }

    private function validatedToken(
        string $token
    ): string {
        $token = trim($token);

        if (
            $token === ''
            || strlen($token) > 8192
            || preg_match('/[\r\n]/', $token)
        ) {
            throw new PacCredentialsUnavailable;
        }

        return $token;
    }
}
