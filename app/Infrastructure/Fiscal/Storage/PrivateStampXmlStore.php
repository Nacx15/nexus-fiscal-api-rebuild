<?php

namespace App\Infrastructure\Fiscal\Storage;

use App\Models\FiscalStampAttempt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PrivateStampXmlStore
{
    public function put(
        FiscalStampAttempt $attempt,
        string $contents
    ): array {
        if (
            $contents === ''
            || strlen($contents) > 8_000_000
        ) {
            throw new RuntimeException(
                'Invalid stamping XML size.'
            );
        }

        $checksum = hash('sha256', $contents);

        $path = implode('/', [
            'fiscal',
            'companies',
            (string) $attempt->company_id,
            'stamp-attempts',
            $attempt->public_id,
            $checksum.'.xml',
        ]);

        $disk = 'local';
        $storage = Storage::disk($disk);

        /*
         * Un archivo existente nunca se
         * sobrescribe con contenido diferente.
         */
        if ($storage->exists($path)) {
            if (
                hash('sha256', $storage->get($path))
                !== $checksum
            ) {
                throw new RuntimeException(
                    'Existing stamping XML was modified.'
                );
            }
        } elseif (! $storage->put($path, $contents)) {
            throw new RuntimeException(
                'Unable to store stamping XML.'
            );
        }

        return [
            'xml_disk' => $disk,
            'xml_path' => $path,
            'xml_sha256' => $checksum,
            'xml_size' => strlen($contents),
        ];
    }

    public function verify(
        FiscalStampAttempt $attempt
    ): bool {
        if (
            ! $attempt->xml_disk
            || ! $attempt->xml_path
            || ! $attempt->xml_sha256
        ) {
            return false;
        }

        $storage = Storage::disk(
            $attempt->xml_disk
        );

        if (! $storage->exists($attempt->xml_path)) {
            return false;
        }

        $contents = $storage->get(
            $attempt->xml_path
        );

        return hash_equals(
            $attempt->xml_sha256,
            hash('sha256', $contents)
        ) && strlen($contents) === $attempt->xml_size;
    }
}
