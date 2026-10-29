<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\CommitInputDto;
use App\Dto\E2ee\CommitResultDto;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\E2ee\GroupDeliveryServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @implements ProcessorInterface<CommitInputDto, CommitResultDto>
 */
final readonly class SubmitCommitProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversations,
        private GroupDeliveryServiceInterface $delivery,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommitResultDto
    {
        $conversation = $this->conversations->getByIdentifier((string) $uriVariables['conversation']);
        $result = $this->delivery->submitCommit($conversation, $data);

        $request = $context['request'] ?? null;
        if (!$result['created'] && $request instanceof Request) {
            $request->attributes->set('_api_response_status', Response::HTTP_OK);
        }

        return CommitResultDto::fromEpoch($result['epoch']);
    }
}
