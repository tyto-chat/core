<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminCommunityListDto;
use App\Dto\Admin\AdminCommunityRowDto;
use App\Entity\Community;
use App\Repository\CommunityRepository;
use App\Repository\MessageRepository;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Utils\PaginationParams;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<AdminCommunityListDto>
 */
final readonly class AdminCommunitiesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityRepository $communityRepository,
        private MessageRepository $messageRepository,
        private CommunityMembershipServiceInterface $membershipService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminCommunityListDto
    {
        $request = $this->requestStack->getCurrentRequest();
        $search = $request?->query->get('search');
        $search = is_string($search) ? $search : null;
        $pagination = PaginationParams::fromRequest($request);
        $sortBy = $request?->query->get('sort');
        $sortDir = $request?->query->get('dir');

        $result = $this->communityRepository->findForAdminList(
            $search,
            $pagination->page,
            $pagination->perPage,
            is_string($sortBy) ? $sortBy : 'createdAt',
            is_string($sortDir) ? $sortDir : 'DESC',
        );

        $dto = new AdminCommunityListDto();
        $dto->total = $result['total'];
        $dto->page = $pagination->page;
        $dto->perPage = $pagination->perPage;
        foreach ($result['rows'] as $community) {
            $dto->rows[] = $this->row($community);
        }

        return $dto;
    }

    private function row(Community $community): AdminCommunityRowDto
    {
        $row = new AdminCommunityRowDto();
        $row->id = $community->getId();
        $row->identifier = $community->getIdentifier();
        $row->name = $community->getName();
        $row->description = $community->getDescription();
        $row->isPrivate = $community->isPrivate();
        $row->memberCount = $this->membershipService->countMembers($community);
        $row->messageCount = $this->messageRepository->countForCommunity($community);
        $row->createdAt = $community->getCreatedAt()?->format(\DateTimeInterface::ATOM);

        return $row;
    }
}
