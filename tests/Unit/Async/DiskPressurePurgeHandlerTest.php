<?php

declare(strict_types=1);

namespace App\Tests\Unit\Async;

use App\Async\DiskPressurePurgeMessage;
use App\Async\Handler\DiskPressurePurgeHandler;
use App\Service\Retention\DiskPressurePurgeServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DiskPressurePurgeHandlerTest extends TestCase
{
    public function testInvokesServiceAndLogsWhenFired(): void
    {
        $service = $this->createMock(DiskPressurePurgeServiceInterface::class);
        $service->expects(self::once())->method('purge')
            ->willReturn(['files' => 3, 'bytes' => 12345, 'freePercent' => 16.0, 'reachedTarget' => true]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new DiskPressurePurgeHandler($service, $logger))(new DiskPressurePurgeMessage());
    }

    public function testStaysSilentWhenValveClosed(): void
    {
        $service = $this->createMock(DiskPressurePurgeServiceInterface::class);
        $service->method('purge')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new DiskPressurePurgeHandler($service, $logger))(new DiskPressurePurgeMessage());
    }
}
