<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withConfiguredRule(AddOverrideAttributeToOverriddenMethodsRector::class, [
        AddOverrideAttributeToOverriddenMethodsRector::ALLOW_OVERRIDE_EMPTY_METHOD => true,
    ]);
