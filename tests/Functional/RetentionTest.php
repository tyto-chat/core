<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessageRevision;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Service\Retention\RetentionServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RetentionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function setSetting(string $key, mixed $value): void
    {
        $em = $this->em();
        $setting = new Setting($key);
        $setting->setValue($value);
        $em->persist($setting);
        $em->flush();
    }

    private function backdate(string $entityClass, string $id, string $modifier): void
    {
        $em = $this->em();
        $when = (new \DateTimeImmutable())->modify($modifier);
        $em->createQuery(sprintf('UPDATE %s e SET e.createdAt = :when WHERE e.id = :id', $entityClass))
            ->setParameter('when', $when)
            ->setParameter('id', $id)
            ->execute();
        $em->clear();
    }

    private function service(): RetentionServiceInterface
    {
        $svc = static::getContainer()->get(RetentionServiceInterface::class);
        \assert($svc instanceof RetentionServiceInterface);

        return $svc;
    }

    public function testTombstonedMessageBodiesAreRedacted(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $tombstone = MessageFactory::new()->inPage($page)->byUser($author)->withText('deleted but retained')->deleted()->create();

        $this->backdate(Message::class, $tombstone->getId(), '-400 days');
        $this->setSetting('messageRetentionDays', 30);

        $this->service()->purge();

        $em = $this->em();
        $em->clear();
        $reloaded = $em->getRepository(Message::class)->find($tombstone->getId());
        \assert($reloaded instanceof Message);
        self::assertTrue($reloaded->isDeleted());
        foreach ($reloaded->getRevisions() as $revision) {
            self::assertSame('', $revision->getText());
        }
    }

    public function testOldMessageIsRedactedAndRecentKept(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $old = MessageFactory::new()->inPage($page)->byUser($author)->withText('secret old content')->create();
        $recent = MessageFactory::new()->inPage($page)->byUser($author)->withText('fresh content')->create();

        $this->backdate(Message::class, $old->getId(), '-400 days');
        $this->setSetting('messageRetentionDays', 30);

        $result = $this->service()->purge();
        self::assertSame(1, $result['messages']);

        $em = $this->em();
        $oldFresh = $em->getRepository(Message::class)->find($old->getId());
        self::assertNotNull($oldFresh);
        self::assertTrue($oldFresh->isDeleted());
        foreach ($oldFresh->getRevisions() as $revision) {
            self::assertSame('', $revision->getText());
        }

        $recentFresh = $em->getRepository(Message::class)->find($recent->getId());
        self::assertNotNull($recentFresh);
        self::assertFalse($recentFresh->isDeleted());
    }

    public function testMessageRetentionDisabledByDefault(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $old = MessageFactory::new()->inPage($page)->byUser($author)->withText('kept content')->create();
        $this->backdate(Message::class, $old->getId(), '-400 days');

        $result = $this->service()->purge();
        self::assertSame(0, $result['messages']);
    }

    private function attach(Message $message, string $name): MediaObject
    {
        $em = $this->em();
        $media = new MediaObject();
        $media->filePath = $name;
        $media->type = 'attachment';
        $media->originalName = $name;
        $media->message = $message;
        $em->persist($media);
        $em->flush();

        return $media;
    }

    public function testOldAttachmentsDeletedWhileMessageBodySurvives(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $old = MessageFactory::new()->inPage($page)->byUser($author)->withText('body worth keeping')->create();
        $recent = MessageFactory::new()->inPage($page)->byUser($author)->withText('recent')->create();

        $oldMessage = $old;
        $oldAttachment = $this->attach($oldMessage, 'old-file.png');
        $recentAttachment = $this->attach($recent, 'recent-file.png');

        $this->backdate(Message::class, $oldMessage->getId(), '-400 days');
        $this->setSetting('defaultAttachmentRetentionDays', 30);

        $result = $this->service()->purge();
        self::assertSame(1, $result['attachments']);

        $em = $this->em();
        $em->clear();
        self::assertNull($em->getRepository(MediaObject::class)->find($oldAttachment->getId()));
        self::assertNotNull($em->getRepository(MediaObject::class)->find($recentAttachment->getId()));

        // Attachment retention must not touch the message itself.
        $reloaded = $em->getRepository(Message::class)->find($oldMessage->getId());
        \assert($reloaded instanceof Message);
        self::assertFalse($reloaded->isDeleted());
        $revision = $reloaded->getRevisions()->last();
        \assert($revision instanceof MessageRevision);
        self::assertNotSame('', $revision->getText());
    }

    public function testAttachmentPurgeStampsPurgedAttachmentCount(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $old = MessageFactory::new()->inPage($page)->byUser($author)->withText('body worth keeping')->create();
        $messageId = $old->getId();

        $this->attach($old, 'old-file-1.png');
        $this->attach($old, 'old-file-2.png');

        $this->backdate(Message::class, $messageId, '-40 days');
        $this->setSetting('defaultAttachmentRetentionDays', 30);

        $this->service()->purge();

        $reloaded = $this->em()->find(Message::class, $messageId);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->getPurgedAttachmentCount());
        self::assertCount(0, $reloaded->getAttachments());
    }

    public function testAttachmentsKeptWhenRetentionUnset(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $old = MessageFactory::new()->inPage($page)->byUser($author)->withText('body')->create();
        $attachment = $this->attach($old, 'kept.png');
        $this->backdate(Message::class, $old->getId(), '-400 days');

        $result = $this->service()->purge();
        self::assertSame(0, $result['attachments']);
        self::assertNotNull($this->em()->getRepository(MediaObject::class)->find($attachment->getId()));
    }

    public function testOldNotificationsDeleted(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $old = NotificationFactory::new()->forRecipient($user)->create();
        $recent = NotificationFactory::new()->forRecipient($user)->create();

        $this->backdate(Notification::class, (string) $old->getId(), '-90 days');
        $this->setSetting('notificationRetentionDays', 30);

        $result = $this->service()->purge();
        self::assertSame(1, $result['notifications']);

        $em = $this->em();
        self::assertNull($em->getRepository(Notification::class)->find($old->getId()));
        self::assertNotNull($em->getRepository(Notification::class)->find($recent->getId()));
    }
}
