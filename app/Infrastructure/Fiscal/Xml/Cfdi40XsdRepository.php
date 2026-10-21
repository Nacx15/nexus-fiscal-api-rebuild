<?php

namespace App\Infrastructure\Fiscal\Xml;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class Cfdi40XsdRepository
{
    public function path(): string
    {
        $directory = realpath(
            storage_path('app/private/sat-resources')
        );

        if ($directory === false) {
            throw new RuntimeException(
                'SAT resources directory does not exist.'
            );
        }

        $manifestPath = $directory
            .'/cfdi40-xsd-manifest.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException(
                'XSD resources are not initialized. '
                .'Run fiscal:cache-cfdi40-xsd.'
            );
        }

        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            ($manifest['version'] ?? null) !== '4.0'
            || ! is_string($manifest['root'] ?? null)
            || ! is_array($manifest['files'] ?? null)
            || $manifest['files'] === []
        ) {
            throw new RuntimeException(
                'Invalid XSD resource manifest.'
            );
        }

        $verified = [];

        foreach ($manifest['files'] as $relative => $hash) {
            if (! is_string($hash)) {
                throw new RuntimeException(
                    'Invalid XSD checksum entry.'
                );
            }

            $path = $this->resolveInside(
                $directory,
                $relative
            );

            $actual = hash_file('sha256', $path);

            if (
                $actual === false
                || ! hash_equals($hash, $actual)
            ) {
                throw new RuntimeException(
                    'SAT XSD resource integrity check failed.'
                );
            }

            $verified[$path] = true;
        }

        $root = $this->resolveInside(
            $directory,
            $manifest['root']
        );

        $visited = [];

        $this->verifyDependencies(
            $root,
            $verified,
            $directory,
            $visited
        );

        return $root;
    }

    private function resolveInside(
        string $directory,
        string $relative
    ): string {
        if (
            $relative === ''
            || str_contains($relative, '\\')
            || preg_match(
                '~(?:^|/)\.\.(?:/|$)~',
                $relative
            )
            || str_starts_with($relative, '/')
            || preg_match(
                '~^[a-z][a-z0-9+.-]*:~i',
                $relative
            )
        ) {
            throw new RuntimeException(
                'Unsafe XSD resource path.'
            );
        }

        $path = realpath(
            $directory.'/'.$relative
        );

        $base = rtrim(
            str_replace('\\', '/', $directory),
            '/'
        ).'/';

        $normalized = $path === false
            ? ''
            : str_replace('\\', '/', $path);

        if (
            $path === false
            || ! str_starts_with($normalized, $base)
            || ! is_file($path)
        ) {
            throw new RuntimeException(
                'Missing or untrusted XSD resource.'
            );
        }

        return $path;
    }

    private function verifyDependencies(
        string $path,
        array $verified,
        string $directory,
        array &$visited
    ): void {
        if (isset($visited[$path])) {
            return;
        }

        if (! isset($verified[$path])) {
            throw new RuntimeException(
                'An XSD dependency is absent from the manifest.'
            );
        }

        $visited[$path] = true;

        $xml = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (
                ! $xml->load($path, LIBXML_NONET)
                || $xml->doctype !== null
            ) {
                throw new RuntimeException(
                    'Invalid local XSD resource.'
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($xml);

        $xpath->registerNamespace(
            'xs',
            'http://www.w3.org/2001/XMLSchema'
        );

        $imports = $xpath->query(
            '//xs:import[@schemaLocation]'
            .' | //xs:include[@schemaLocation]'
            .' | //xs:redefine[@schemaLocation]'
            .' | //xs:override[@schemaLocation]'
        );

        foreach ($imports as $import) {
            $location = $import->getAttribute(
                'schemaLocation'
            );

            /*
             * Sólo permitimos rutas relativas
             * resueltas previamente por CfdiUtils.
             */
            if (
                $location === ''
                || str_contains($location, '\\')
                || str_starts_with($location, '/')
                || preg_match(
                    '~^[a-z][a-z0-9+.-]*:~i',
                    $location
                )
            ) {
                throw new RuntimeException(
                    'Remote or unsafe XSD dependency.'
                );
            }

            $dependency = realpath(
                dirname($path).'/'.$location
            );

            $base = rtrim(
                str_replace('\\', '/', $directory),
                '/'
            ).'/';

            if (
                $dependency === false
                || ! is_file($dependency)
                || ! str_starts_with(
                    str_replace('\\', '/', $dependency),
                    $base
                )
            ) {
                throw new RuntimeException(
                    'Missing or untrusted XSD dependency.'
                );
            }

            $this->verifyDependencies(
                $dependency,
                $verified,
                $directory,
                $visited
            );
        }
    }
}
