<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Async\PublishConversationPolicyMessage;
use App\Dto\Admin\ServerConfigDto;
use App\Dto\Admin\ServerConfigPatchDto;
use App\Service\HttpCache\HttpCacheSwitchInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<ServerConfigPatchDto, ServerConfigDto>
 */
final readonly class SaveServerConfigProcessor implements ProcessorInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
        private HttpCacheSwitchInterface $httpCacheSwitch,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ServerConfigDto
    {
        $modeBefore = $this->settings->get(Settings::encryptedDmsMode());
        $this->settings->applyPatch($data);
        $this->httpCacheSwitch->sync();
        if ($modeBefore !== $this->settings->get(Settings::encryptedDmsMode())) {
            $this->bus->dispatch(new PublishConversationPolicyMessage());
        }

        return ServerConfigDto::fromSettings($this->settings);
    }
}
