<?php

namespace App\Infrastructure\Fiscal\Certificates;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidFiscalCertificate;
use App\Models\FiscalCertificate;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use PhpCfdi\Credentials\Credential;
use Throwable;

final class CsdCredentialLoader
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function load(
        FiscalCertificate $certificate,
        string $expectedRfc,
        ?DateTimeImmutable $issuedAt = null
    ): Credential {
        $companyId = $this->companyContext->id();

        /*
         * Verificamos nuevamente el tenant
         * antes de acceder a los archivos.
         */
        $certificate = FiscalCertificate::query()
            ->where('company_id', $companyId)
            ->whereKey($certificate->id)
            ->where('status', 'active')
            ->first();

        if ($certificate === null) {
            throw new InvalidFiscalCertificate(
                'Certificate is unavailable for this company.'
            );
        }

        $issuedAt ??= new DateTimeImmutable;

        $disk = Storage::disk('local');

        if (
            ! $disk->exists($certificate->cer_path)
            || ! $disk->exists($certificate->key_path)
        ) {
            throw new InvalidFiscalCertificate(
                'Certificate or private key file is missing.'
            );
        }

        try {
            /*
             * Soporta los archivos DER del SAT.
             * La contraseña se descifra mediante
             * el cast encrypted del modelo.
             */
            $credential = Credential::create(
                $disk->get($certificate->cer_path),
                $disk->get($certificate->key_path),
                (string) $certificate->key_password
            );
        } catch (Throwable $exception) {
            /*
             * Evitamos exponer en respuestas HTTP
             * información interna de credenciales.
             */
            throw new InvalidFiscalCertificate(
                'Unable to load or decrypt fiscal credentials.',
                previous: $exception
            );
        }

        $publicCertificate = $credential->certificate();

        if (! $credential->isCsd()) {
            throw new InvalidFiscalCertificate(
                'The selected certificate is not a CSD.'
            );
        }

        if (
            strtoupper($credential->rfc())
            !== strtoupper($expectedRfc)
        ) {
            throw new InvalidFiscalCertificate(
                'The certificate RFC does not match the issuer.'
            );
        }

        /*
         * Serial SAT: representación en bytes,
         * no número decimal X.509 convencional.
         */
        $serial = $publicCertificate
            ->serialNumber()
            ->bytes();

        if ($serial !== $certificate->certificate_number) {
            throw new InvalidFiscalCertificate(
                'Certificate serial number mismatch.'
            );
        }

        if (! $publicCertificate->validOn($issuedAt)) {
            throw new InvalidFiscalCertificate(
                'Certificate is not valid at the issue date.'
            );
        }

        return $credential;
    }
}
