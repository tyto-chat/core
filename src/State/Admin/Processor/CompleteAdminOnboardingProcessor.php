<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminOnboardingResultDto;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;

/**
 * @implements ProcessorInterface<mixed, AdminOnboardingResultDto>
 */
final readonly class CompleteAdminOnboardingProcessor implements ProcessorInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminOnboardingResultDto
    {
        $this->settings->completeAdminOnboarding();
        $at = $this->settings->get(Settings::adminOnboardedAt());

        return new AdminOnboardingResultDto(true, $at?->format(\DateTimeInterface::ATOM));
    }
}
