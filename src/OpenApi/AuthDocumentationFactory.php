<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\OpenApi;
use App\Enum\ApiKey\ApiKeyScope;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator('api_platform.openapi.factory')]
final readonly class AuthDocumentationFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->decorated->__invoke($context);

        $components = $openApi->getComponents();
        $schemes = $components->getSecuritySchemes() ?? new \ArrayObject();
        $schemes['PAT'] = new SecurityScheme(
            type: 'http',
            description: 'Personal Access Token. Send as `Authorization: Bearer pat_…`. '
                .'A PAT only reaches endpoints that declare a scope, and must hold every scope they require; '
                .'scope-less endpoints are session/JWT-only.',
            scheme: 'bearer',
            bearerFormat: 'PAT',
        );
        $openApi = $openApi->withComponents($components->withSecuritySchemes($schemes));

        return $openApi->withInfo($openApi->getInfo()->withDescription($this->authDescription()));
    }

    private function authDescription(): string
    {
        $scopes = implode("\n", array_map(
            static fn (ApiKeyScope $s): string => '- `'.$s->value.'`',
            ApiKeyScope::cases(),
        ));

        return <<<MD
            REST API for tyto.chat — an open-source, self-hosted community chat platform
            (communities, channels, threads, direct messages, voice, search, moderation).
            All endpoints speak JSON-LD (`application/ld+json`) unless noted; upload
            endpoints accept `multipart/form-data`.

            ## Authentication

            Two bearer transports are accepted on `Authorization`:

            - **Session / JWT** — `Authorization: Bearer <jwt>`. Full-permission, used by the first-party SPA.
            - **Personal Access Token (PAT)** — `Authorization: Bearer pat_…`. Scoped, for third-party
              integrations. A PAT can only call endpoints that declare a scope, and must hold every scope
              the endpoint requires; endpoints with no declared scope are JWT-only (a PAT receives `403`).

            ### PAT scopes

            $scopes
            MD;
    }
}
