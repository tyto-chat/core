<?php

declare(strict_types=1);

use App\Tests\Stub\RecordingMercureHub;
use App\Tests\Stub\TestMercureHub;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Decorate the real Mercure hub in the test env. The compiled `test` container
// is shared between PHPUnit and the e2e web server (both APP_ENV=test), so the
// real-vs-noop choice MUST be made at RUNTIME inside TestMercureHub. A
// compile-time conditional (the previous approach) baked whichever process
// compiled the container first — when PHPUnit compiled it, the e2e web server
// inherited a silent no-op hub and all SSE delivery broke.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(TestMercureHub::class)
        ->decorate('mercure.hub.default')
        ->args([service('.inner')]);

    // Outermost decorator (lower priority = decorated later = wraps the
    // TestMercureHub above) that records published updates under PHPUnit so
    // functional tests can inspect broadcast bytes. It must sit OUTSIDE
    // TestMercureHub because that hub drops the publish (returns '' without
    // delegating) under PHPUnit — an inner recorder would never be reached.
    // A pure pass-through under the e2e web server.
    $container->services()
        ->set(RecordingMercureHub::class)
        ->decorate('mercure.hub.default', priority: -10)
        ->args([service('.inner')]);
};
