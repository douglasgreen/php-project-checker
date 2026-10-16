<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

// PhpVersion::PHP_83 is the literal int 80300; the analyzer widens it to int
// @mago-ignore analysis:possibly-invalid-argument
return RectorConfig::configure()
    ->withoutParallel()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
    ])
    ->withComposerBased(symfony: true)
    ->withPhpVersion(PhpVersion::PHP_83);
