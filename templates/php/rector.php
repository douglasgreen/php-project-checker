<?php

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withoutParallel()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
    ])
    ->withComposerBased(
        symfony: true,
    )
    ->withSkip([
        // Empty is OK.
        DisallowedEmptyRuleFixerRector::class,
    ])
    ->withRules([
        DeclareStrictTypesRector::class,
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withPhpVersion(PhpVersion::PHP_83);
