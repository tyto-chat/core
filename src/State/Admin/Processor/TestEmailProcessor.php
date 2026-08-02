<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\TestEmailDto;
use App\Dto\Admin\TestEmailResultDto;
use App\Service\Admin\TestEmailServiceInterface;

/**
 * @implements ProcessorInterface<TestEmailDto, TestEmailResultDto>
 */
final readonly class TestEmailProcessor implements ProcessorInterface
{
    public function __construct(
        private TestEmailServiceInterface $testEmailService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TestEmailResultDto
    {
        /** @var TestEmailDto $data */
        $error = $this->testEmailService->send($data->to);

        return new TestEmailResultDto(null === $error, $error);
    }
}
