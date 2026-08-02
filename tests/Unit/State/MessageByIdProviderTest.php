<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use App\Entity\Message;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;
use App\State\Message\Provider\MessageByIdProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
class MessageByIdProviderTest extends TestCase
{
    private MessageServiceInterface&MockObject $messageService;
    private MessageByIdProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->messageService = $this->createMock(MessageServiceInterface::class);
        $this->provider = new MessageByIdProvider($this->messageService, new ApiKeyScopeGuard(new RequestStack()));
    }

    public function testProvideLoadsAndHydrates(): void
    {
        $message = $this->createMock(Message::class);
        $this->messageService->expects(self::once())->method('getById')->with('uuid-1')->willReturn($message);
        $this->messageService->expects(self::once())->method('hydrateText')->with($message);

        $result = $this->provider->provide(new Get(), ['id' => 'uuid-1']);

        self::assertSame($message, $result);
    }
}
