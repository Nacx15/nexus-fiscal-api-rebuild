<?php

namespace App\Console\Commands;

use CfdiUtils\XmlResolver\XmlResolver;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class CacheCfdi40Xslt extends Command
{
    protected $signature = 'fiscal:cache-cfdi40-xslt';

    protected $description =
        'Download and cache official CFDI 4.0 XSLT resources';

    public function handle(): int
    {
        $directory = storage_path(
            'app/private/sat-resources'
        );

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0700, true)
            && ! is_dir($directory)
        ) {
            $this->error('Unable to create SAT cache directory.');

            return self::FAILURE;
        }

        try {
            /*
             * CfdiUtils descarga y adapta las
             * dependencias del XSLT oficial.
             */
            $resolver = new XmlResolver($directory);

            $location = $resolver
                ->resolveCadenaOrigenLocation('4.0');

            $root = realpath($directory);
            $file = realpath($location);

            if ($root === false || $file === false) {
                throw new RuntimeException(
                    'SAT XSLT was not cached locally.'
                );
            }

            /*
             * Normalizamos separadores para
             * compatibilidad con Windows.
             */
            $root = rtrim(
                str_replace('\\', '/', $root),
                '/'
            ).'/';

            $file = str_replace('\\', '/', $file);

            if (! str_starts_with($file, $root)) {
                throw new RuntimeException(
                    'SAT XSLT is outside the trusted cache directory.'
                );
            }

            $relativePath = substr(
                $file,
                strlen($root)
            );

            $manifest = json_encode([
                'version' => '4.0',
                'xslt' => $relativePath,
                'sha256' => hash_file('sha256', $file),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

            $written = file_put_contents(
                $directory.'/cfdi40-manifest.json',
                $manifest,
                LOCK_EX
            );

            if ($written === false) {
                throw new RuntimeException(
                    'Unable to write SAT resource manifest.'
                );
            }

            $this->info('CFDI 4.0 XSLT cached successfully.');
            $this->line('Location: '.$file);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                'Unable to prepare CFDI 4.0 resources: '
                .$exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}
