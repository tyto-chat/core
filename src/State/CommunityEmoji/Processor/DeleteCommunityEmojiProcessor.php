<?php

declare(strict_types=1);

namespace App\State\CommunityEmoji\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CommunityEmoji;
use App\Service\Community\CommunityEmojiServiceInterface;

/**
 * @implements ProcessorInterface<CommunityEmoji, null>
 */
final readonly class DeleteCommunityEmojiProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityEmojiServiceInterface $communityEmojiService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->communityEmojiService->delete($data);

        return null;
    }
}
