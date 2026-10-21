<?php

namespace App\Console\Commands;

use App\Infrastructure\Fiscal\Xml\Cfdi40XsdRepository;
use CfdiUtils\XmlResolver\XmlResolver;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class CacheCfdi40Xsd extends Command
{
    protected $signature = 'fiscal:cache-cfdi40-xsd';

    protected $description =
        'Prepare local SAT CFDI 4.0 XSD resources';

    private const XSD_URL =
        'http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd';

    public function handle(): int
    {
        $directory = storage_path(
            'app/private/sat-resources'
        );

        try {
            if (
                ! is_dir($directory)
                && ! mkdir($directory, 0700, true)
                && ! is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Cannot create SAT resources directory.'
                );
            }

            $resolver = new XmlResolver($directory);

            /*
             * Descarga el XSD y resuelve
             * sus dependencias.
             */
            $xsd = $resolver->resolve(
                self::XSD_URL,
                XmlResolver::TYPE_XSD
            );

            $root = realpath($directory);
            $xsdPath = realpath($xsd);

            if (
                $root === false
                || $xsdPath === false
            ) {
                throw new RuntimeException(
                    'CFDI 4.0 XSD could not be prepared.'
                );
            }

            $rootPrefix = rtrim(
                str_replace('\\', '/', $root),
                '/'
            ).'/';

            $xsdPath = str_replace(
                '\\',
                '/',
                $xsdPath
            );

            if (! str_starts_with($xsdPath, $rootPrefix)) {
                throw new RuntimeException(
                    'Unexpected XSD resource location.'
                );
            }

            $files = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (
                    ! $file->isFile()
                    || strtolower($file->getExtension()) !== 'xsd'
                ) {
                    continue;
                }

                $path = str_replace(
                    '\\',
                    '/',
                    $file->getRealPath()
                );

                if (! str_starts_with($path, $rootPrefix)) {
                    throw new RuntimeException(
                        'XSD file outside trusted directory.'
                    );
                }

                $relative = substr(
                    $path,
                    strlen($rootPrefix)
                );

                $files[$relative] = hash_file(
                    'sha256',
                    $file->getRealPath()
                );
            }

            ksort($files);

            $manifest = [
                'version' => '4.0',
                'root' => substr(
                    $xsdPath,
                    strlen($rootPrefix)
                ),
                'files' => $files,
            ];

            $written = file_put_contents(
                $directory.'/cfdi40-xsd-manifest.json',
                json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                ),
                LOCK_EX
            );

            if ($written === false) {
                throw new RuntimeException(
                    'Cannot write XSD manifest.'
                );
            }

            /*
             * Validamos inmediatamente
             * el árbol local de dependencias.
             */
            app(
                Cfdi40XsdRepository::class
            )->path();

            $this->info(
                'CFDI 4.0 XSD resources prepared successfully.'
            );

            $this->line(
                'XSD files: '.count($files)
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                'XSD preparation failed: '
                .$exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}
