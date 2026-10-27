<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\Notification\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\Notification\EmailDigestServiceInterface;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class EmailDigestTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function digestService(): EmailDigestServiceInterface
    {
        $svc = static::getContainer()->get(EmailDigestServiceInterface::class);
        \assert($svc instanceof EmailDigestServiceInterface);

        return $svc;
    }

    private function emailedAtCount(User $user): int
    {
        $repo = static::getContainer()->get(NotificationRepository::class);
        \assert($repo instanceof NotificationRepository);
        $rows = $repo->findBy(['recipient' => $user]);

        return count(array_filter($rows, static fn ($n): bool => null !== $n->getEmailedAt()));
    }

    public function testSendsDigestAndStampsEmailedAt(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::Mention, 'isRead' => false]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::DmMessage, 'isRead' => false]);

        $sent = $this->digestService()->dispatchDue();

        self::assertSame(1, $sent);
        self::assertSame(2, $this->emailedAtCount($user));
    }

    public function testSecondRunDoesNotResend(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::Mention, 'isRead' => false]);

        self::assertSame(1, $this->digestService()->dispatchDue());
        // Already stamped emailedAt → nothing pending.
        self::assertSame(0, $this->digestService()->dispatchDue());
    }

    public function testSkipsOptedOutUsers(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => false]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::Mention, 'isRead' => false]);

        self::assertSame(0, $this->digestService()->dispatchDue());
    }

    public function testSkipsReadNotifications(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::Mention, 'isRead' => true]);

        self::assertSame(0, $this->digestService()->dispatchDue());
    }

    public function testExcludesAudienceWideTypes(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::BroadcastMention, 'isRead' => false]);
        NotificationFactory::createOne(['recipient' => $user, 'type' => NotificationType::ChannelActivity, 'isRead' => false]);

        self::assertSame(0, $this->digestService()->dispatchDue());
    }

    public function testDigestSkipsRowsFromBlockedActor(): void
    {
        $a = UserFactory::createOne(['emailNotifications' => true]);
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        $hidden = NotificationFactory::createOne(['recipient' => $a, 'actor' => $b, 'authorName' => 'Blocked Bob', 'type' => NotificationType::Mention]);
        $visible = NotificationFactory::createOne(['recipient' => $a, 'actor' => $c, 'authorName' => 'Carol', 'type' => NotificationType::Mention]);
        $onlyHidden = UserFactory::createOne(['emailNotifications' => true]);
        NotificationFactory::createOne(['recipient' => $onlyHidden, 'actor' => $b, 'authorName' => 'Blocked Bob', 'type' => NotificationType::DmMessage]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist((new UserBlock())->setBlocker($a)->setBlocked($b));
        $em->persist((new UserBlock())->setBlocker($onlyHidden)->setBlocked($b));
        $em->flush();

        self::assertSame(1, $this->digestService()->dispatchDue());

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $email = $messages[0];
        \assert($email instanceof TemplatedEmail);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Carol', $html);
        self::assertStringNotContainsString('Blocked Bob', $html);

        $em->clear();
        $repo = static::getContainer()->get(NotificationRepository::class);
        \assert($repo instanceof NotificationRepository);
        self::assertNull($repo->find($hidden->getId())?->getEmailedAt());
        self::assertNotNull($repo->find($visible->getId())?->getEmailedAt());
        self::assertSame(0, $this->emailedAtCount($onlyHidden));
    }
}
