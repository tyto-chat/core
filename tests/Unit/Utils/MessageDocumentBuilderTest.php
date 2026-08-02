<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Entity\Message;
use App\Enum\Message\MessageKind;
use App\Utils\MessageDocumentBuilder;
use PHPUnit\Framework\TestCase;

class MessageDocumentBuilderTest extends TestCase
{
    public function testStandardRootMessageIsIndexable(): void
    {
        self::assertTrue(MessageDocumentBuilder::isIndexable(new Message()));
    }

    public function testDeletedMessageIsNotIndexable(): void
    {
        $message = (new Message())->setDeleted(true);

        self::assertFalse(MessageDocumentBuilder::isIndexable($message));
    }

    public function testThreadReplyIsIndexable(): void
    {
        $message = (new Message())->setParent(new Message());

        self::assertTrue(MessageDocumentBuilder::isIndexable($message));
    }

    public function testSystemMessageIsNotIndexable(): void
    {
        $message = (new Message())->setKind(MessageKind::System);

        self::assertFalse(MessageDocumentBuilder::isIndexable($message));
    }
}
