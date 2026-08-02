<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\Dto\Search\SearchResultDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\State\Search\Provider\ChannelSearchProvider;
use App\State\Search\Provider\CommunitySearchProvider;
use App\State\Search\Provider\ConversationSearchProvider;

#[ApiResource(
    shortName: 'Search',
    description: 'Scoped full-text message search over a channel, a whole community, or a conversation.',
    normalizationContext: ['groups' => ['search:read', 'message:read']],
    operations: [
        new Get(
            uriTemplate: '/communities/{identifier}/search',
            uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
            security: "is_granted('ROLE_USER')",
            output: SearchResultDto::class,
            provider: CommunitySearchProvider::class,
            extraProperties: ['scopeResource' => 'messages'],
            openapi: new Model\Operation(
                summary: 'Search a whole community',
                description: 'Searches every channel of the community the caller may view (the visible set is '
                    .'computed per request), so results never include channels hidden from the caller. Requires '
                    .'access to the community. Hits embed the full message plus a `<mark>`-highlighted snippet '
                    .'and carry a `channelIdentifier`; deleted and system messages are never returned.',
                parameters: [
                    new Model\Parameter('q', 'query', 'Search query (min 2 chars; shorter returns an empty result).', true, false, false, ['type' => 'string']),
                    new Model\Parameter('authorId', 'query', 'Filter to a single author id.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('after', 'query', 'Only messages created at/after this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('before', 'query', 'Only messages created at/before this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('limit', 'query', 'Page size (1-50, default 25).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('offset', 'query', 'Result offset (default 0).', false, false, false, ['type' => 'integer']),
                ]),
        ),
        new Get(
            uriTemplate: '/communities/{communityIdentifier}/channels/{channelIdentifier}/search',
            uriVariables: [
                'communityIdentifier' => new Link(fromClass: Community::class, identifiers: ['identifier']),
                'channelIdentifier' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
            ],
            security: "is_granted('ROLE_USER')",
            output: SearchResultDto::class,
            provider: ChannelSearchProvider::class,
            extraProperties: ['scopeResource' => 'messages'],
            openapi: new Model\Operation(
                summary: 'Search a channel',
                description: 'Searches messages of a single channel. The caller must be able to view the '
                    .'channel. Hits embed the full message plus a `<mark>`-highlighted snippet; deleted and '
                    .'system messages are never returned.',
                parameters: [
                    new Model\Parameter('q', 'query', 'Search query (min 2 chars; shorter returns an empty result).', true, false, false, ['type' => 'string']),
                    new Model\Parameter('authorId', 'query', 'Filter to a single author id.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('after', 'query', 'Only messages created at/after this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('before', 'query', 'Only messages created at/before this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('limit', 'query', 'Page size (1-50, default 25).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('offset', 'query', 'Result offset (default 0).', false, false, false, ['type' => 'integer']),
                ]),
        ),
        new Get(
            uriTemplate: '/conversations/{identifier}/search',
            uriVariables: ['identifier' => new Link(fromClass: Conversation::class, identifiers: ['identifier'])],
            security: "is_granted('ROLE_USER')",
            output: SearchResultDto::class,
            provider: ConversationSearchProvider::class,
            extraProperties: ['scopeResource' => 'conversations'],
            openapi: new Model\Operation(
                summary: 'Search a conversation',
                description: 'Searches messages of a single direct-message conversation. Participants only. '
                    .'Hits embed the full message plus a `<mark>`-highlighted snippet; deleted messages are '
                    .'never returned.',
                parameters: [
                    new Model\Parameter('q', 'query', 'Search query (min 2 chars; shorter returns an empty result).', true, false, false, ['type' => 'string']),
                    new Model\Parameter('authorId', 'query', 'Filter to a single author id.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('after', 'query', 'Only messages created at/after this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('before', 'query', 'Only messages created at/before this epoch-seconds timestamp.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('limit', 'query', 'Page size (1-50, default 25).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('offset', 'query', 'Result offset (default 0).', false, false, false, ['type' => 'integer']),
                ]),
        ),
    ],
)]
class Search
{
}
