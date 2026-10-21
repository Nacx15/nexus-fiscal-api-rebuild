<?php

namespace App\Infrastructure\Fiscal\Xml;

use CfdiUtils\CadenaOrigen\DOMBuilder;
use RuntimeException;

final class Cfdi40OriginalStringGenerator
{
    public function generate(string $xml): string
    {
        if (trim($xml) === '') {
            throw new RuntimeException(
                'Cannot generate original string from empty XML.'
            );
        }

        $directory = storage_path(
            'app/private/sat-resources'
        );

        $manifestPath = $directory
            .'/cfdi40-manifest.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException(
                'CFDI 4.0 XSLT is not initialized. '
                .'Run fiscal:cache-cfdi40-xslt.'
            );
        }

        $manifestContent = file_get_contents(
            $manifestPath
        );

        if ($manifestContent === false) {
            throw new RuntimeException(
                'Unable to read CFDI 4.0 XSLT manifest.'
            );
        }

        $manifest = json_decode(
            $manifestContent,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            ($manifest['version'] ?? null) !== '4.0'
            || ! is_string($manifest['xslt'] ?? null)
            || ! is_string($manifest['sha256'] ?? null)
        ) {
            throw new RuntimeException(
                'Invalid CFDI 4.0 XSLT manifest.'
            );
        }

        $root = realpath($directory);

        $location = realpath(
            $directory
            .DIRECTORY_SEPARATOR
            .$manifest['xslt']
        );

        if ($root === false || $location === false) {
            throw new RuntimeException(
                'CFDI 4.0 XSLT file is missing.'
            );
        }

        $normalizedRoot = rtrim(
            str_replace('\\', '/', $root),
            '/'
        ).'/';

        $normalizedLocation = str_replace(
            '\\',
            '/',
            $location
        );

        if (
            ! str_starts_with(
                $normalizedLocation,
                $normalizedRoot
            )
        ) {
            throw new RuntimeException(
                'Untrusted CFDI 4.0 XSLT location.'
            );
        }

        $actualHash = hash_file(
            'sha256',
            $location
        );

        if (
            ! hash_equals(
                $manifest['sha256'],
                $actualHash
            )
        ) {
            throw new RuntimeException(
                'CFDI 4.0 XSLT checksum mismatch.'
            );
        }

        /*
         * Transformación oficial.
         * No construimos manualmente
         * la cadena original.
         */
        $builder = new DOMBuilder;

        $originalString = $builder->build(
            $xml,
            $location
        );

        if (trim($originalString) === '') {
            throw new RuntimeException(
                'The generated original string is empty.'
            );
        }

        return $originalString;
    }
}
