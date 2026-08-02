<?php

declare(strict_types=1);

namespace App\State\Conversation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Conversation\CreateConversationDto;
use App\Entity\Conversation;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<CreateConversationDto, Conversation>
 */
final readonly class CreateConversationProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        /** @var CreateConversationDto $data */
        $participants = $this->userService->findByIds($data->memberUserIds);

        return $this->conversationService->createOrFind($participants);
    }
}
