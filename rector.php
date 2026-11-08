<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// PHP sets follow the "php" constraint of composer.json; framework sets follow the installed versions.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withCache(__DIR__.'/var/rector')
    ->withPhpSets()
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withImportNames(importShortClasses: false);
