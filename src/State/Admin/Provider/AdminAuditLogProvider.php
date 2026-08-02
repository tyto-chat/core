<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminAuditActorDto;
use App\Dto\Admin\AdminAuditPageDto;
use App\Dto\Admin\AdminAuditRowDto;
use App\Entity\AdminAuditLog;
use App\Enum\Admin\AdminAuditAction;
use App\Repository\AdminAuditLogRepository;
use App\Utils\PaginationParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<AdminAuditPageDto>
 */
final readonly class AdminAuditLogProvider implements ProviderInterface
{
    public function __construct(
        private AdminAuditLogRepository $repository,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminAuditPageDto
    {
        $request = $this->requestStack->getCurrentRequest();

        $pagination = PaginationParams::fromRequest($request);

        $action = null;
        $actionParam = $request?->query->get('action');
        if (is_string($actionParam) && '' !== $actionParam) {
            $action = AdminAuditAction::tryFrom($actionParam);
            if (null === $action) {
                throw new BadRequestHttpException(sprintf('Unknown audit action "%s".', $actionParam));
            }
        }

        $targetTypeParam = $request?->query->get('targetType');
        $targetType = is_string($targetTypeParam) && '' !== $targetTypeParam ? $targetTypeParam : null;

        $result = $this->repository->findPaginated(
            $action,
            $this->intOrNull($request, 'actorId'),
            $targetType,
            $this->intOrNull($request, 'targetId'),
            $pagination->page,
            $pagination->perPage,
        );

        $dto = new AdminAuditPageDto();
        $dto->total = $result['total'];
        $dto->page = $pagination->page;
        $dto->perPage = $pagination->perPage;
        foreach ($result['rows'] as $log) {
            $dto->rows[] = $this->row($log);
        }

        return $dto;
    }

    private function row(AdminAuditLog $log): AdminAuditRowDto
    {
        $row = new AdminAuditRowDto();
        $row->id = (int) $log->getId();
        $row->action = $log->getAction()->value;
        $row->targetType = $log->getTargetType();
        $row->targetId = $log->getTargetId();
        $row->payload = $log->getPayload();
        $row->createdAt = $log->getCreatedAt()->format(\DateTimeInterface::ATOM);

        $actor = $log->getActor();
        if (null !== $actor) {
            $row->actor = new AdminAuditActorDto((int) $actor->getId(), $actor->getProfile()?->getName(), $actor->getEmail());
        }

        return $row;
    }

    private function intOrNull(?Request $request, string $key): ?int
    {
        $value = $request?->query->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
