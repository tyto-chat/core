<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\HistoryPageDto;
use App\Dto\E2ee\HistoryRecordDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<HistoryPageDto>
 */
final readonly class HistoryPageProvider implements ProviderInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HistoryPageDto
    {
        $request = $context['request'] ?? null;
        $after = $request instanceof Request ? $request->query->getString('after') : '';
        $limit = $request instanceof Request ? $request->query->getInt('limit', RecoveryBackupServiceInterface::MAX_HISTORY_PAGE) : RecoveryBackupServiceInterface::MAX_HISTORY_PAGE;
        $limit = max(1, min($limit, RecoveryBackupServiceInterface::MAX_HISTORY_PAGE));

        $afterDevice = null;
        $afterCounter = 0;
        if ('' !== $after) {
            if (1 !== preg_match('/^([A-Za-z0-9-]{1,36}):(\d{1,10})$/', $after, $m)) {
                throw new BadRequestHttpException('Invalid history cursor.');
            }
            $afterDevice = $m[1];
            $afterCounter = (int) $m[2];
        }

        $records = $this->backups->listHistory($afterDevice, $afterCounter, $limit);
        $last = end($records);
        $next = \count($records) === $limit && false !== $last ? $last->getDevice()->getId().':'.$last->getCounter() : null;

        return new HistoryPageDto(array_map(HistoryRecordDto::fromRecord(...), $records), $next);
    }
}
