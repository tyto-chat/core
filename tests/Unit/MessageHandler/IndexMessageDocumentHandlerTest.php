<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Async\Handler\IndexMessageDocumentHandler;
use App\Async\IndexMessageDocumentMessage;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Search\SearchServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class IndexMessageDocumentHandlerTest extends TestCase
{
    private MessageRepository&MockObject $messageRepository;
    private SearchServiceInterface&MockObject $searchService;
    private IndexMessageDocumentHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->searchService = $this->createMock(SearchServiceInterface::class);
        $this->handler = new IndexMessageDocumentHandler($this->messageRepository, $this->searchService);
    }

    public function testRemovesIndexWhenEntityIsGone(): void
    {
        $this->messageRepository->method('find')->willReturn(null);
        $this->searchService->expects(self::once())->method('removeMessage')->with('uuid-1');
        $this->searchService->expects(self::never())->method('indexMessage');

        $this->handler->__invoke(new IndexMessageDocumentMessage('uuid-1'));
    }

    public function testDelegatesFoundEntityToIndexMessage(): void
    {
        $entity = $this->createMock(Message::class);

        $this->messageRepository->method('find')->willReturn($entity);
        $this->searchService->expects(self::once())->method('indexMessage')->with($entity);
        $this->searchService->expects(self::never())->method('removeMessage');

        $this->handler->__invoke(new IndexMessageDocumentMessage('uuid-2'));
    }
}
