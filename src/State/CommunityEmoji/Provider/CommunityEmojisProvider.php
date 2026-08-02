<?php

declare(strict_types=1);

namespace App\State\CommunityEmoji\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CommunityEmoji;
use App\Repository\CommunityRepository;
use App\Service\Community\CommunityEmojiServiceInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<CommunityEmoji>
 */
final readonly class CommunityEmojisProvider implements ProviderInterface
{
    public function __construct(
        private CommunityRepository $communityRepository,
        private CommunityEmojiServiceInterface $communityEmojiService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $community = $this->communityRepository->findOneBy(['identifier' => $uriVariables['community']]);
        if (null === $community) {
            throw new NotFoundHttpException(sprintf('Community "%s" not found.', $uriVariables['community']));
        }

        return $this->communityEmojiService->getAllForCommunity($community);
    }
}
