<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\OpenApi;
use App\Utils\ApiVersions;

final readonly class VersionedPathsOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $item) {
            $paths->addPath(str_replace('/{version}/', '/'.ApiVersions::CANONICAL.'/', (string) $path), $item);
        }

        return $openApi->withPaths($paths);
    }
}
