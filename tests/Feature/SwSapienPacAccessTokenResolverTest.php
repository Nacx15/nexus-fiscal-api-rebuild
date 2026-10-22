<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Exceptions\PacCredentialsUnavailable;
use App\Models\Company;
use App\Models\PacAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SwSapienPacAccessTokenResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.sw_sapien.base_url',
            'https://services.test.sw.com.mx'
        );

        config()->set('cache.default', 'array');

        Cache::store('array')->flush();

        Http::preventStrayRequests();
    }

    private function company(): Company
    {
        $company = Company::factory()->create();

        app(CompanyContext::class)->set(
            $company
        );

        return $company;
    }

    private function account(
        Company $company,
        array $attributes = []
    ): PacAccount {
        return PacAccount::query()->create(
            array_replace([
                'company_id' => $company->id,
                'provider' => 'sw_sapien',
                'environment' => 'sandbox',
                'username' => null,
                'password' => null,
                'token' => null,
                'active' => true,
            ], $attributes)
        );
    }

    private function resolve(int $companyId): string
    {
        return app(
            PacAccessTokenResolver::class
        )->resolveForCompany($companyId);
    }

    public function test_permanent_token_is_decrypted_without_http(): void
    {
        $company = $this->company();

        $account = $this->account(
            $company,
            [
                'token' => 'test-permanent-token-A',
            ]
        );

        $token = $this->resolve(
            $company->id
        );

        $this->assertSame(
            'test-permanent-token-A',
            $token
        );

        /*
         * El dato real almacenado en MySQL
         * no debe ser igual al texto original.
         */
        $raw = DB::table('pac_accounts')
            ->where('id', $account->id)
            ->value('token');

        $this->assertNotSame(
            'test-permanent-token-A',
            $raw
        );

        $this->assertIsString($raw);

        Http::assertNothingSent();
    }

    public function test_each_company_uses_its_own_token(): void
    {
        $companyA = $this->company();

        $this->account(
            $companyA,
            ['token' => 'token-company-A']
        );

        $companyB = Company::factory()->create();

        $this->account(
            $companyB,
            ['token' => 'token-company-B']
        );

        $this->assertSame(
            'token-company-A',
            $this->resolve($companyA->id)
        );

        app(CompanyContext::class)->set(
            $companyB
        );

        $this->assertSame(
            'token-company-B',
            $this->resolve($companyB->id)
        );

        Http::assertNothingSent();
    }

    public function test_cannot_request_another_company_token(): void
    {
        $companyA = $this->company();

        $companyB = Company::factory()->create();

        $this->account(
            $companyB,
            ['token' => 'private-company-B']
        );

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        /*
         * El contexto sigue siendo A.
         */
        $this->resolve($companyB->id);
    }

    public function test_inactive_account_is_rejected(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'token' => 'inactive-token',
                'active' => false,
            ]
        );

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        $this->resolve($company->id);
    }

    public function test_production_credentials_are_not_used(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'environment' => 'production',
                'token' => 'production-token',
            ]
        );

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        $this->resolve($company->id);
    }

    public function test_temporary_token_is_cached_and_reused(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'username' => 'test@example.com',
                'password' => 'secret-password',
                'token' => null,
            ]
        );

        Http::fake([
            'services.test.sw.com.mx/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'token' => 'temporary-token-123',
                    'expires_in' => now()->addMinutes(90)->timestamp,
                    'tokeny_type' => 'Bearer',
                ],
            ], 200),
        ]);

        $first = $this->resolve(
            $company->id
        );

        $second = $this->resolve(
            $company->id
        );

        $this->assertSame(
            'temporary-token-123',
            $first
        );

        $this->assertSame(
            $first,
            $second
        );

        /*
         * Sólo una llamada al endpoint
         * de autenticación.
         */
        Http::assertSentCount(1);

        Http::assertSent(
            fn ($request) => $request->method() === 'POST'
                && $request->url()
                    === 'https://services.test.sw.com.mx'
                    .'/v2/security/authenticate'
                && $request['user']
                    === 'test@example.com'
                && $request['password']
                    === 'secret-password'
                && ! $request->hasHeader(
                    'Authorization'
                )
        );
    }

    public function test_expired_or_near_expiry_token_is_rejected(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'username' => 'test@example.com',
                'password' => 'secret-password',
            ]
        );

        /*
         * Sólo quedan 60 segundos.
         * Exigimos un margen de seguridad
         * de 120 segundos.
         */
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'data' => [
                    'token' => 'almost-expired',
                    'expires_in' => now()->addSeconds(60)->timestamp,
                ],
            ], 200),
        ]);

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        $this->resolve($company->id);
    }

    public function test_invalid_authentication_is_rejected(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'username' => 'test@example.com',
                'password' => 'invalid-password',
            ]
        );

        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401),
        ]);

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        $this->resolve($company->id);
    }

    public function test_corrupted_encrypted_token_is_rejected(): void
    {
        $company = $this->company();

        $account = $this->account(
            $company,
            [
                'token' => 'original-secret-token',
            ]
        );

        /*
         * Simulamos corrupción directamente
         * en la base de datos, evitando el cast.
         */
        DB::table('pac_accounts')
            ->where('id', $account->id)
            ->update([
                'token' => 'corrupted-ciphertext',
            ]);

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        $this->resolve($company->id);
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $company = $this->company();

        $this->account($company);

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        try {
            $this->resolve($company->id);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_wrong_api_environment_is_rejected(): void
    {
        $company = $this->company();

        $this->account(
            $company,
            [
                'token' => 'sandbox-token',
            ]
        );

        config()->set(
            'services.sw_sapien.base_url',
            'https://services.sw.com.mx'
        );

        $this->expectException(
            PacCredentialsUnavailable::class
        );

        try {
            $this->resolve($company->id);
        } finally {
            Http::assertNothingSent();
        }
    }
}
