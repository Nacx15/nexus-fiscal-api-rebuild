<?php

namespace App\Application\Fiscal;

use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StampInvoice
{
    public function __construct(
        private readonly PrepareSignedStampAttempt $prepare,
        private readonly DispatchPreparedStampAttempt $dispatch,
    ) {}

    public function execute(
        FiscalDocument $document,
        FiscalCertificate $certificate,
        CarbonImmutable $issuedAt
    ): FiscalStampAttempt {
        /*
         * No creamos reservas ni firmamos XML
         * si el despacho está deshabilitado.
         */
        if (! config('fiscal.pac.dispatch_enabled', false)) {
            throw new ConflictHttpException(
                'PAC stamping is not enabled.'
            );
        }

        /*
         * Internamente utiliza:
         *
         * BeginStampAttempt
         * BuildSignedInvoiceCfdi
         * StorePreparedStampXml
         */
        $attempt = $this->prepare->execute(
            $document,
            $certificate,
            $issuedAt
        );

        /*
         * Sólo despachamos después de que
         * toda la preparación haya finalizado.
         */
        return $this->dispatch->execute(
            $attempt
        );
    }
}
