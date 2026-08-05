<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ]);
