<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AdminAuditLog;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Enum\Admin\AdminAuditAction;
use App\Enum\Notification\NotificationType;
use App\Service\Retention\DiskPressurePurgeServiceInterface;
use App\Service\Retention\DiskSpaceProbeInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Stub\MutableDiskSpaceProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DiskPressurePurgeTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const int TOTAL_BYTES = 100_000_000_000;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function probe(): MutableDiskSpaceProbe
    {
        $probe = static::getContainer()->get(DiskSpaceProbeInterface::class);
        \assert($probe instanceof MutableDiskSpaceProbe);

        return $probe;
    }

    private function service(): DiskPressurePurgeServiceInterface
    {
        $service = static::getContainer()->get(DiskPressurePurgeServiceInterface::class);
        \assert($service instanceof DiskPressurePurgeServiceInterface);

        return $service;
    }

    private function setSetting(string $key, mixed $value): void
    {
        $em = $this->em();
        $setting = new Setting($key);
        $setting->setValue($value);
        $em->persist($setting);
        $em->flush();
    }

    private function channelPage(): MessagePage
    {
        $channel = ChannelFactory::createOne();

        return MessagePageFactory::new()->forChannel($channel)->create();
    }

    private function attach(Message $message, string $name, int $size): MediaObject
    {
        $em = $this->em();
        $media = new MediaObject();
        $media->filePath = $name;
        $media->type = 'attachment';
        $media->originalName = $name;
        $media->size = $size;
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

    public function testDoesNothingWhenDisabled(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $page = $this->channelPage();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $attachment = $this->attach($message, 'old.png', 1000);
        $this->backdate(MediaObject::class, (string) $attachment->getId(), '-30 days');

        $probe = $this->probe();
        $probe->total = self::TOTAL_BYTES;
        $probe->freeSequence = [1_000_000_000];

        $result = $this->service()->purge();

        self::assertNull($result);

        $em = $this->em();
        $em->clear();
        self::assertNotNull($em->getRepository(MediaObject::class)->find($attachment->getId()));
    }

    public function testDoesNothingAboveTrigger(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $page = $this->channelPage();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $attachment = $this->attach($message, 'old.png', 1000);
        $this->backdate(MediaObject::class, (string) $attachment->getId(), '-30 days');

        $this->setSetting('diskPurgeTriggerPercent', 10);

        $probe = $this->probe();
        $probe->total = self::TOTAL_BYTES;
        $probe->freeSequence = [20_000_000_000];

        $result = $this->service()->purge();

        self::assertNull($result);

        $em = $this->em();
        $em->clear();
        self::assertNotNull($em->getRepository(MediaObject::class)->find($attachment->getId()));
    }

    public function testPurgesUntilTargetReached(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->admin()->create();
        $author = UserFactory::createOne();
        $page = $this->channelPage();

        $oldMessage = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $oldMessageId = $oldMessage->getId();
        $oldAttachment = $this->attach($oldMessage, 'old.png', 1_000_000_000);
        $oldAttachmentId = $oldAttachment->getId();
        $this->backdate(Message::class, $oldMessageId, '-30 days');
        $this->backdate(MediaObject::class, (string) $oldAttachmentId, '-30 days');

        $youngMessage = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $youngAttachment = $this->attach($youngMessage, 'young.png', 500);
        $youngAttachmentId = $youngAttachment->getId();

        $this->setSetting('diskPurgeTriggerPercent', 10);
        $this->setSetting('diskPurgeTargetPercent', 15);
        $this->setSetting('diskPurgeMinAgeDays', 14);

        $probe = $this->probe();
        $probe->total = self::TOTAL_BYTES;
        $probe->freeSequence = [5_000_000_000, 20_000_000_000];

        $result = $this->service()->purge();

        self::assertNotNull($result);
        self::assertSame(1, $result['files']);
        self::assertTrue($result['reachedTarget']);
        self::assertSame(1_000_000_000, $result['bytes']);

        $em = $this->em();
        $em->clear();

        self::assertNull($em->getRepository(MediaObject::class)->find($oldAttachmentId));
        self::assertNotNull($em->getRepository(MediaObject::class)->find($youngAttachmentId));

        $reloaded = $em->getRepository(Message::class)->find($oldMessageId);
        \assert($reloaded instanceof Message);
        self::assertSame(1, $reloaded->getPurgedAttachmentCount());

        $auditLog = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::DiskPressurePurge]);
        self::assertNotNull($auditLog);
        $payload = $auditLog->getPayload();
        self::assertNotNull($payload);
        self::assertSame(1, $payload['files']);
        self::assertSame(1_000_000_000, $payload['bytes']);
        self::assertArrayHasKey('freePercent', $payload);

        $notification = $em->getRepository(Notification::class)->findOneBy([
            'recipient' => $admin,
            'type' => NotificationType::DiskPressurePurge,
        ]);
        self::assertNotNull($notification);
        self::assertNotSame('', $notification->getReason());
        self::assertNotNull($notification->getReason());
    }

    public function testExhaustionStopsAtFloorAndRecordsDistinctAudit(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->admin()->create();
        $author = UserFactory::createOne();
        $page = $this->channelPage();

        $youngMessage = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $youngAttachment = $this->attach($youngMessage, 'young.png', 500);
        $youngAttachmentId = $youngAttachment->getId();

        $this->setSetting('diskPurgeTriggerPercent', 10);
        $this->setSetting('diskPurgeTargetPercent', 15);

        $probe = $this->probe();
        $probe->total = self::TOTAL_BYTES;
        $probe->freeSequence = [5_000_000_000];

        $result = $this->service()->purge();

        self::assertNotNull($result);
        self::assertSame(0, $result['files']);
        self::assertFalse($result['reachedTarget']);

        $em = $this->em();
        $em->clear();
        self::assertNotNull($em->getRepository(MediaObject::class)->find($youngAttachmentId));

        $auditLog = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::DiskPressurePurgeExhausted]);
        self::assertNotNull($auditLog);

        $notification = $em->getRepository(Notification::class)->findOneBy([
            'recipient' => $admin,
            'type' => NotificationType::DiskPressurePurge,
        ]);
        self::assertNotNull($notification);
    }

    public function testRuntimeGuardWhenTargetBelowTrigger(): void
    {
        self::bootKernel();
        $author = UserFactory::createOne();
        $page = $this->channelPage();

        $oldMessage = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $oldMessageId = $oldMessage->getId();
        $oldAttachment = $this->attach($oldMessage, 'old.png', 1_000_000_000);
        $oldAttachmentId = $oldAttachment->getId();
        $this->backdate(Message::class, $oldMessageId, '-30 days');
        $this->backdate(MediaObject::class, (string) $oldAttachmentId, '-30 days');

        $em = $this->em();
        $setting = new Setting('diskPurgeTriggerPercent');
        $setting->setValue(20);
        $em->persist($setting);
        $setting2 = new Setting('diskPurgeTargetPercent');
        $setting2->setValue(10);
        $em->persist($setting2);
        $em->flush();

        $probe = $this->probe();
        $probe->total = self::TOTAL_BYTES;
        $probe->freeSequence = [5_000_000_000, 25_000_000_000];

        $result = $this->service()->purge();

        self::assertNotNull($result);
        self::assertSame(1, $result['files']);
        self::assertTrue($result['reachedTarget']);

        $em->clear();
        self::assertNull($em->getRepository(MediaObject::class)->find($oldAttachmentId));
    }
}
