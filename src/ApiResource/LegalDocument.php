<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Legal\Provider\LegalDocumentProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'LegalDocument',
    description: 'A server legal document (terms of service or privacy policy) rendered as markdown.',
    normalizationContext: ['groups' => ['legal:read']],
    formats: ['json' => ['application/json']],
)]
#[Get(
    uriTemplate: '/legal/{type}',
    provider: LegalDocumentProvider::class,
    cacheHeaders: [
        'max_age' => 0,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Origin'],
    ],
    openapi: new Model\Operation(
        summary: 'Get a legal document',
        description: 'Anonymous. Returns the document\'s markdown content with placeholders interpolated, '
            .'plus a flag telling whether the operator customized it. `type` is `terms` or `privacy` '
            .'(anything else is `404`); pass `?variant=default` to fetch the shipped default text instead '
            .'of the operator\'s version.',
    ),
)]
class LegalDocument
{
    #[Groups(['legal:read'])]
    public string $type = '';

    #[Groups(['legal:read'])]
    public string $content = '';

    #[Groups(['legal:read'])]
    public bool $customized = false;
}
