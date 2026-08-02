<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drift guard for the published API contract. Snapshots the surface — every
 * `METHOD /path` the OpenAPI document exposes — and the security schemes, so any
 * PR that adds, removes or renames an endpoint (or drops an auth transport) has
 * to update the snapshot, making the contract change visible in review.
 *
 * Stable against description / schema edits (only the surface is locked). To
 * intentionally accept a surface change, regenerate:
 *
 *     UPDATE_OPENAPI_SNAPSHOT=1 ddev test tests/Functional/OpenApiContractTest.php
 */
final class OpenApiContractTest extends KernelTestCase
{
    private const string SNAPSHOT = __DIR__.'/../Fixtures/openapi-surface.txt';

    public function testSecuritySchemesAreDocumented(): void
    {
        $openApi = $this->buildOpenApi();
        $schemes = $openApi->getComponents()->getSecuritySchemes();
        self::assertNotNull($schemes);

        $names = array_keys((array) $schemes);
        sort($names);
        // Both auth transports must stay documented for third-party clients.
        self::assertSame(['JWT', 'PAT'], $names);
    }

    public function testApiSurfaceMatchesSnapshot(): void
    {
        $surface = $this->currentSurface();

        if ('1' === getenv('UPDATE_OPENAPI_SNAPSHOT')) {
            file_put_contents(self::SNAPSHOT, implode("\n", $surface)."\n");
            self::markTestSkipped('OpenAPI surface snapshot regenerated.');
        }

        self::assertFileExists(
            self::SNAPSHOT,
            'Missing OpenAPI surface snapshot — run with UPDATE_OPENAPI_SNAPSHOT=1 to create it.',
        );

        $expected = array_values(array_filter(
            explode("\n", (string) file_get_contents(self::SNAPSHOT)),
            static fn (string $line): bool => '' !== $line,
        ));

        self::assertSame(
            $expected,
            $surface,
            'The API surface changed. If intentional, regenerate with '
            .'UPDATE_OPENAPI_SNAPSHOT=1 ddev test tests/Functional/OpenApiContractTest.php',
        );
    }

    /**
     * @return list<string> sorted "METHOD /path" lines
     */
    private function currentSurface(): array
    {
        $methods = [
            'GET' => static fn (PathItem $p): ?Operation => $p->getGet(),
            'PUT' => static fn (PathItem $p): ?Operation => $p->getPut(),
            'POST' => static fn (PathItem $p): ?Operation => $p->getPost(),
            'DELETE' => static fn (PathItem $p): ?Operation => $p->getDelete(),
            'PATCH' => static fn (PathItem $p): ?Operation => $p->getPatch(),
        ];

        $lines = [];
        foreach ($this->buildOpenApi()->getPaths()->getPaths() as $path => $item) {
            foreach ($methods as $verb => $get) {
                if (null !== $get($item)) {
                    $lines[] = $verb.' '.$path;
                }
            }
        }
        sort($lines);

        return $lines;
    }

    private function buildOpenApi(): \ApiPlatform\OpenApi\OpenApi
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        return $factory->__invoke();
    }
}
