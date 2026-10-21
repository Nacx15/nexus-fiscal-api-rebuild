<?php

namespace Tests\Feature;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidFiscalCertificate;
use App\Infrastructure\Fiscal\Certificates\CsdCredentialLoader;
use App\Models\Company;
use App\Models\FiscalCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalCertificateSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCertificate(
        Company $company
    ): FiscalCertificate {
        return FiscalCertificate::create([
            'company_id' => $company->id,
            'certificate_number' => '30001000000500000001',
            'cer_path' => 'certificates/test.cer',
            'key_path' => 'certificates/test.key',
            'key_password' => 'test-secret',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'status' => 'active',
        ]);
    }

    public function test_other_company_cannot_load_certificate(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $certificate = $this->makeCertificate($companyA);

        app(CompanyContext::class)->set($companyB);

        $this->expectException(
            InvalidFiscalCertificate::class
        );

        app(CsdCredentialLoader::class)->load(
            $certificate,
            $companyA->rfc
        );
    }

    public function test_missing_certificate_files_are_rejected(): void
    {
        Storage::fake('local');

        $company = Company::factory()->create();

        $certificate = $this->makeCertificate($company);

        app(CompanyContext::class)->set($company);

        $this->expectException(
            InvalidFiscalCertificate::class
        );

        app(CsdCredentialLoader::class)->load(
            $certificate,
            $company->rfc
        );
    }

    public function test_private_key_password_is_encrypted_in_database(): void
    {
        $company = Company::factory()->create();

        $certificate = $this->makeCertificate($company);

        $rawPassword = DB::table('fiscal_certificates')
            ->where('id', $certificate->id)
            ->value('key_password');

        $this->assertNotSame(
            'test-secret',
            $rawPassword
        );

        $this->assertSame(
            'test-secret',
            $certificate->key_password
        );
    }
}
