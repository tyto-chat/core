<?php

declare(strict_types=1);

namespace App\State\ChannelSection\Provider;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ChannelSection;
use App\Exception\ChannelSection\ChannelSectionNotFoundException;
use App\Service\Channel\ChannelSectionServiceInterface;

/**
 * @implements ProviderInterface<ChannelSection>
 */
final readonly class ChannelSectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly ChannelSectionServiceInterface $channelSectionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?ChannelSection
    {
        if ($operation instanceof Get && isset($uriVariables['id'])) {
            $section = $this->channelSectionService->get($uriVariables['id']);
            if ($section->getCommunity()?->getIdentifier() !== (string) $uriVariables['community']) {
                throw new ChannelSectionNotFoundException(sprintf('Channel section %d not found in community "%s".', (int) $uriVariables['id'], (string) $uriVariables['community']));
            }

            return $section;
        }

        return null;
    }
}
