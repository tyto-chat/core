<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Repository\MediaObjectRepository;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DiskPurgeEligibilityTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function repository(): MediaObjectRepository
    {
        $repository = static::getContainer()->get(MediaObjectRepository::class);
        \assert($repository instanceof MediaObjectRepository);

        return $repository;
    }

    private function service(): MediaObjectServiceInterface
    {
        $service = static::getContainer()->get(MediaObjectServiceInterface::class);
        \assert($service instanceof MediaObjectServiceInterface);

        return $service;
    }

    private function attach(Message $message, string $name, string $type = 'attachment'): MediaObject
    {
        $em = $this->em();
        $media = new MediaObject();
        $media->filePath = $name;
        $media->type = $type;
        $media->originalName = $name;
        $media->message = $message;
        $em->persist($media);
        $em->flush();

        return $media;
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

    private function cutoff(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('-30 days');
    }

    private function channelPage(): MessagePage
    {
        $channel = ChannelFactory::createOne();

        return MessagePageFactory::new()->forChannel($channel)->create();
    }

    private function conversationPage(): MessagePage
    {
        $conversation = ConversationFactory::createOne();

        return MessagePageFactory::new()->forConversation($conversation)->create();
    }

    public function testExcludesNonAttachmentTypesPinnedYoungAndDms(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channelPage = $this->channelPage();
        $conversationPage = $this->conversationPage();

        $eligibleMessage = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $eligibleAttachment = $this->attach($eligibleMessage, 'eligible.png');
        $this->backdate(MediaObject::class, (string) $eligibleAttachment->getId(), '-400 days');

        $avatarMessage = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $avatarAttachment = $this->attach($avatarMessage, 'avatar.png', 'avatar');
        $this->backdate(MediaObject::class, (string) $avatarAttachment->getId(), '-400 days');

        $pinnedMessage = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $pinnedMessage->setPinnedAt(new \DateTimeImmutable());
        $this->em()->flush();
        $pinnedAttachment = $this->attach($pinnedMessage, 'pinned.png');
        $this->backdate(MediaObject::class, (string) $pinnedAttachment->getId(), '-400 days');

        $youngMessage = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $youngAttachment = $this->attach($youngMessage, 'young.png');

        $dmMessage = MessageFactory::new()->inPage($conversationPage)->byUser($author)->create();
        $dmAttachment = $this->attach($dmMessage, 'dm.png');
        $this->backdate(MediaObject::class, (string) $dmAttachment->getId(), '-400 days');

        $result = $this->repository()->findEligibleForDiskPurge($this->cutoff(), false, 100);

        self::assertCount(1, $result);
        self::assertSame($eligibleAttachment->getId(), $result[0]->getId());
    }

    public function testIncludeDmsOptsConversationAttachmentsIn(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $conversationPage = $this->conversationPage();

        $dmMessage = MessageFactory::new()->inPage($conversationPage)->byUser($author)->create();
        $dmAttachment = $this->attach($dmMessage, 'dm.png');
        $this->backdate(MediaObject::class, (string) $dmAttachment->getId(), '-400 days');

        $result = $this->repository()->findEligibleForDiskPurge($this->cutoff(), true, 100);

        self::assertCount(1, $result);
        self::assertSame($dmAttachment->getId(), $result[0]->getId());
    }

    public function testPinnedButSoftDeletedMessageIsEligible(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channelPage = $this->channelPage();

        $message = MessageFactory::new()->inPage($channelPage)->byUser($author)->deleted()->create();
        $message->setPinnedAt(new \DateTimeImmutable());
        $this->em()->flush();
        $attachment = $this->attach($message, 'pinned-deleted.png');
        $this->backdate(MediaObject::class, (string) $attachment->getId(), '-400 days');

        $result = $this->repository()->findEligibleForDiskPurge($this->cutoff(), false, 100);

        self::assertCount(1, $result);
        self::assertSame($attachment->getId(), $result[0]->getId());
    }

    public function testOrdersSoftDeletedFirstThenOldest(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channelPage = $this->channelPage();

        $messageA = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $attachmentA = $this->attach($messageA, 'a.png');
        $this->backdate(MediaObject::class, (string) $attachmentA->getId(), '-100 days');

        $messageB = MessageFactory::new()->inPage($channelPage)->byUser($author)->deleted()->create();
        $attachmentB = $this->attach($messageB, 'b.png');
        $this->backdate(MediaObject::class, (string) $attachmentB->getId(), '-40 days');

        $messageC = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $attachmentC = $this->attach($messageC, 'c.png');
        $this->backdate(MediaObject::class, (string) $attachmentC->getId(), '-60 days');

        $result = $this->repository()->findEligibleForDiskPurge($this->cutoff(), false, 100);

        self::assertCount(3, $result);
        self::assertSame($attachmentB->getId(), $result[0]->getId());
        self::assertSame($attachmentA->getId(), $result[1]->getId());
        self::assertSame($attachmentC->getId(), $result[2]->getId());
    }

    public function testPurgeAttachmentStampsCounterAndRemovesRow(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $channelPage = $this->channelPage();

        $message = MessageFactory::new()->inPage($channelPage)->byUser($author)->create();
        $messageId = $message->getId();
        $keep = $this->attach($message, 'keep.png');
        $purge = $this->attach($message, 'purge.png');
        $keepId = $keep->getId();
        $purgeId = $purge->getId();

        $em = $this->em();
        $message = $em->find(Message::class, $messageId);
        \assert($message instanceof Message);
        $toPurge = $em->find(MediaObject::class, $purgeId);
        \assert($toPurge instanceof MediaObject);

        $this->service()->purgeAttachment($toPurge);
        $em->flush();

        $em->clear();
        self::assertNull($em->getRepository(MediaObject::class)->find($purgeId));
        self::assertNotNull($em->getRepository(MediaObject::class)->find($keepId));

        $reloaded = $em->find(Message::class, $messageId);
        \assert($reloaded instanceof Message);
        self::assertSame(1, $reloaded->getPurgedAttachmentCount());
        self::assertCount(1, $reloaded->getAttachments());
    }
}
