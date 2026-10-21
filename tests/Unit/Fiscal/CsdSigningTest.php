<?php

namespace Tests\Unit\Fiscal;

use App\Application\Fiscal\SignFiscalString;
use PhpCfdi\Credentials\Credential;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CsdSigningTest extends TestCase
{
    private function makeTestCredential(): Credential
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new([
            'commonName' => 'Nexus Fiscal Test',
            'organizationName' => 'Testing Only',
            'countryName' => 'MX',
        ], $privateKey, [
            'digest_alg' => 'sha256',
        ]);

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            1,
            ['digest_alg' => 'sha256']
        );

        $this->assertNotFalse($certificate);

        $this->assertTrue(
            openssl_x509_export(
                $certificate,
                $certificatePem
            )
        );

        $this->assertTrue(
            openssl_pkey_export(
                $privateKey,
                $privateKeyPem
            )
        );

        return Credential::create(
            $certificatePem,
            $privateKeyPem,
            ''
        );
    }

    public function test_it_signs_and_verifies_a_string(): void
    {
        $credential = $this->makeTestCredential();

        $signer = new SignFiscalString;

        $message = '||TEST|NEXUS|100.00||';

        $signature = $signer->sign(
            $message,
            $credential
        );

        $this->assertNotEmpty($signature);

        $this->assertTrue(
            $signer->verify(
                $message,
                $signature,
                $credential
            )
        );
    }

    public function test_modified_message_invalidates_signature(): void
    {
        $credential = $this->makeTestCredential();

        $signer = new SignFiscalString;

        $signature = $signer->sign(
            '||TEST|100.00||',
            $credential
        );

        $this->assertFalse(
            $signer->verify(
                '||TEST|900.00||',
                $signature,
                $credential
            )
        );
    }

    public function test_invalid_base64_is_rejected(): void
    {
        $credential = $this->makeTestCredential();

        $this->assertFalse(
            (new SignFiscalString)->verify(
                'test',
                '###INVALID###',
                $credential
            )
        );
    }

    public function test_empty_original_string_is_rejected(): void
    {
        $credential = $this->makeTestCredential();

        $this->expectException(
            RuntimeException::class
        );

        (new SignFiscalString)->sign(
            '',
            $credential
        );
    }

    public function test_wrong_public_key_cannot_verify_signature(): void
    {
        $credentialA = $this->makeTestCredential();
        $credentialB = $this->makeTestCredential();

        $signer = new SignFiscalString;

        $signature = $signer->sign(
            'Nexus Fiscal Test',
            $credentialA
        );

        $this->assertFalse(
            $signer->verify(
                'Nexus Fiscal Test',
                $signature,
                $credentialB
            )
        );
    }
}
