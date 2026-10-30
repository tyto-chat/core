<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\HistoryRecordInputDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @implements ProcessorInterface<HistoryRecordInputDto, void>
 */
final readonly class AppendHistoryRecordProcessor implements ProcessorInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $created = $this->backups->appendHistoryRecord((string) $uriVariables['deviceId'], (int) $data->counter, (string) $data->blob, (string) $data->signature);

        $request = $context['request'] ?? null;
        if (!$created && $request instanceof Request) {
            $request->attributes->set('_api_response_status', Response::HTTP_OK);
        }
    }
}
