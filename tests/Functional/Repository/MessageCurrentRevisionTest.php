<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\MessageRevision;
use App\Repository\MessageRepository;
use App\Service\Message\MessageServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessageCurrentRevisionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private MessageRepository $repository;
    private MessageServiceInterface $messageService;
    private MessagePage $page;
    private string $rootId;
    private string $replyId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(MessageRepository::class);
        $this->messageService = static::getContainer()->get(MessageServiceInterface::class);

        $this->page = MessagePageFactory::new()->forChannel(ChannelFactory::createOne())->create();
        $root = MessageFactory::new()->inPage($this->page)->withText('first')->create(['pinnedAt' => new \DateTimeImmutable()]);
        $reply = MessageFactory::new()->inPage($this->page)->withText('reply first')->create(['parent' => $root]);
        $this->rootId = $root->getId();
        $this->replyId = $reply->getId();

        $this->edit($this->rootId, 'second');
        $this->edit($this->rootId, 'third');
        $this->em->clear();
    }

    public function testPageReadLoadsOnlyTheCurrentRevision(): void
    {
        $page = $this->em->find(MessagePage::class, $this->page->getId());
        self::assertNotNull($page);

        $roots = $this->repository->findRootsByPage($page);

        self::assertCount(1, $roots);
        $this->assertCurrentOnly($roots[0], 'third', true);
    }

    public function testThreadReadLoadsOnlyTheCurrentRevision(): void
    {
        $root = $this->em->find(Message::class, $this->rootId);
        self::assertNotNull($root);

        $replies = $this->repository->findChildren($root);

        self::assertCount(1, $replies);
        self::assertSame($this->replyId, $replies[0]->getId());
        $this->assertCurrentOnly($replies[0], 'reply first', false);
    }

    public function testPinnedReadLoadsOnlyTheCurrentRevision(): void
    {
        $channel = $this->page->getChannel();
        self::assertNotNull($channel);

        $pinned = $this->repository->findPinnedForChannel($channel);

        self::assertCount(1, $pinned);
        $this->assertCurrentOnly($pinned[0], 'third', true);
    }

    public function testHistoryStillReturnsEveryRevision(): void
    {
        $root = $this->em->find(Message::class, $this->rootId);
        self::assertNotNull($root);

        $texts = array_map(static fn (MessageRevision $r): string => $r->getText(), $root->getRevisions()->toArray());

        self::assertSame(['first', 'second', 'third'], $texts);
    }

    private function assertCurrentOnly(Message $message, string $expectedText, bool $expectedEdited): void
    {
        $this->messageService->hydrateText($message);

        self::assertSame($expectedText, $message->getText());
        self::assertSame($expectedEdited, $message->isEdited());
        $revisions = $message->getRevisions();
        self::assertInstanceOf(PersistentCollection::class, $revisions);
        self::assertFalse($revisions->isInitialized());
    }

    private function edit(string $messageId, string $text): void
    {
        $message = $this->em->find(Message::class, $messageId);
        self::assertNotNull($message);
        $revision = new MessageRevision();
        $revision->setText($text);
        $this->em->persist($revision);
        $message->addRevision($revision);
        $this->em->flush();
    }
}
