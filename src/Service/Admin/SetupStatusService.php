<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\SetupItemDto;
use App\Dto\Admin\SetupStatusDto;

final readonly class SetupStatusService implements SetupStatusServiceInterface
{
    public function __construct(
        private ConfigStatusServiceInterface $configStatus,
    ) {
    }

    public function status(): SetupStatusDto
    {
        $items = [
            new SetupItemDto('smtp', $this->configStatus->isSmtpConfigured(), 'ui', 'email'),
            new SetupItemDto('defaultBot', $this->configStatus->isDefaultBotConfigured(), 'ui', 'advanced'),
            new SetupItemDto('mercure', $this->configStatus->isMercureConfigured(), 'infra', null),
            new SetupItemDto('meilisearch', $this->configStatus->isMeiliConfigured(), 'infra', null),
        ];

        $needsAttention = false;
        foreach ($items as $item) {
            if (!$item->satisfied) {
                $needsAttention = true;
                break;
            }
        }

        return new SetupStatusDto($needsAttention, $items);
    }
}
