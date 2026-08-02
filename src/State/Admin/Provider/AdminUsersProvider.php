<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminUserPageDto;
use App\Dto\Admin\AdminUserRowDto;
use App\Repository\UserRepository;
use App\Utils\PaginationParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<AdminUserPageDto>
 */
final readonly class AdminUsersProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserPageDto
    {
        $request = $this->requestStack->getCurrentRequest();

        $search = $request?->query->get('search');
        $pagination = PaginationParams::fromRequest($request);

        $sortBy = $request?->query->get('sort');
        $sortDir = $request?->query->get('dir');

        $result = $this->userRepository->adminSearch(
            is_string($search) ? $search : null,
            $this->triState($request, 'isAdmin'),
            $this->triState($request, 'isPendingDeletion'),
            $this->triState($request, 'isBot'),
            $pagination->page,
            $pagination->perPage,
            is_string($sortBy) ? $sortBy : 'id',
            is_string($sortDir) ? $sortDir : 'DESC',
        );

        $dto = new AdminUserPageDto();
        $dto->total = $result['total'];
        $dto->page = $pagination->page;
        $dto->perPage = $pagination->perPage;
        foreach ($result['rows'] as $user) {
            $dto->rows[] = AdminUserRowDto::fromUser($user);
        }

        return $dto;
    }

    private function triState(?Request $request, string $param): ?bool
    {
        $raw = $request?->query->get($param);
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (in_array($raw, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no'], true)) {
            return false;
        }

        throw new BadRequestHttpException(sprintf('Filter "%s" must be true/false.', $param));
    }
}
