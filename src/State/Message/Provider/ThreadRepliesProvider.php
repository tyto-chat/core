<?php

declare(strict_types=1);

namespace App\State\Message\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Message;
use App\Exception\Message\MessageNotFoundException;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** @implements ProviderInterface<Message> */
final readonly class ThreadRepliesProvider implements ProviderInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private RequestStack $requestStack,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    /**
     * @return Message[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $root = $this->messageService->getById((string) $uriVariables['id']);
        if (null !== $root->getConversation()) {
            $this->scopeGuard->requireScope('conversations:read');
        }

        $request = $this->requestStack->getCurrentRequest();
        $limit = max(1, min(100, (int) ($request?->query->get('limit', '50') ?? 50)));

        $before = null;
        $beforeUuid = $request?->query->get('before');
        if (\is_string($beforeUuid) && '' !== $beforeUuid) {
            $before = $this->messageService->getById($beforeUuid);
            if ($before->getParent()?->getId() !== $root->getId()) {
                throw new MessageNotFoundException('Cursor is not a reply in this thread.');
            }
        }

        return $this->messageService->findThreadReplies($root, $limit, $before);
    }
}
