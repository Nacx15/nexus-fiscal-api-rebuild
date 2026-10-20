<?php

namespace App\Infrastructure\Storage;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalFileType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class StoreFiscalDocumentFile
{
    public function __construct(
        private readonly CompanyContext $companyContext
    ) {}

    public function execute(
        FiscalDocument $document,
        FiscalFileType $type,
        string $contents
    ): FiscalDocumentFile {
        /*
         * El documento debe pertenecer
         * a la empresa activa.
         */
        abort_unless(
            $document->company_id === $this->companyContext->id(),
            404
        );

        if (
            $document->files()
                ->where('file_type', $type->value)
                ->exists()
        ) {
            throw new ConflictHttpException(
                'A file of this type already exists.'
            );
        }

        /*
         * La ruta no proviene del usuario.
         * Se genera internamente.
         */
        $path = implode('/', [
            'fiscal',
            'companies',
            (string) $document->company_id,
            'documents',
            $document->public_id,
            $type->value,
            (string) Str::ulid()
                .'.'
                .$type->extension(),
        ]);

        /*
         * Disco privado.
         */
        $disk = 'local';

        $stored = Storage::disk($disk)->put(
            $path,
            $contents
        );

        if (! $stored) {
            throw new RuntimeException(
                'Unable to store fiscal document file.'
            );
        }

        try {
            return $document->files()->create([
                'file_type' => $type->value,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $type->mimeType(),
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
            ]);
        } catch (Throwable $exception) {
            /*
             * Si falla la persistencia del
             * registro, eliminamos el archivo
             * recién creado como compensación.
             */
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
