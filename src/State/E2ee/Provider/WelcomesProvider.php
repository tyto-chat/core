<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\ItemsDto;
use App\Dto\E2ee\WelcomeDto;
use App\Service\E2ee\GroupDeliveryServiceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<ItemsDto>
 */
final readonly class WelcomesProvider implements ProviderInterface
{
    public function __construct(private GroupDeliveryServiceInterface $delivery)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemsDto
    {
        $request = $context['request'] ?? null;
        $deviceId = $request instanceof Request ? $request->query->getString('deviceId') : '';
        $after = $request instanceof Request ? $request->query->getString('after') : '';

        return new ItemsDto(array_map(WelcomeDto::fromWelcome(...), $this->delivery->listWelcomes('' === $deviceId ? null : $deviceId, '' === $after ? null : $after)));
    }
}
