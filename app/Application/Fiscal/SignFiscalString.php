<?php

namespace App\Application\Fiscal;

use PhpCfdi\Credentials\Credential;
use RuntimeException;

final class SignFiscalString
{
    public function sign(
        string $originalString,
        Credential $credential
    ): string {
        if ($originalString === '') {
            throw new RuntimeException(
                'The original string cannot be empty.'
            );
        }

        /*
         * La biblioteca utiliza RSA-SHA256
         * y devuelve la firma binaria.
         */
        $binarySignature = $credential->sign(
            $originalString,
            OPENSSL_ALGO_SHA256
        );

        /*
         * Verificamos la firma antes
         * de devolverla.
         */
        if (! $credential->verify(
            $originalString,
            $binarySignature,
            OPENSSL_ALGO_SHA256
        )) {
            throw new RuntimeException(
                'The generated signature could not be verified.'
            );
        }

        return base64_encode($binarySignature);
    }

    public function verify(
        string $originalString,
        string $base64Signature,
        Credential $credential
    ): bool {
        $binarySignature = base64_decode(
            $base64Signature,
            true
        );

        if ($binarySignature === false) {
            return false;
        }

        return $credential->verify(
            $originalString,
            $binarySignature,
            OPENSSL_ALGO_SHA256
        );
    }
}
