<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Notification;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\Notification\EmailDigestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class EmailDigestServiceTest extends TestCase
{
    public function testOneFailingRecipientDoesNotAbortTheRun(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->method('findDigestCandidates')->willReturn([
            [$this->notificationFor('broken@example.com')],
            [$this->notificationFor('fine@example.com')],
        ]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function ($message): void {
            $to = $message->getTo()[0]->getAddress();
            if ('broken@example.com' === $to) {
                throw new TransportException('SMTP refused recipient');
            }
        });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('digest');

        $service = new EmailDigestService(
            $repository,
            $mailer,
            $translator,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        self::assertSame(1, $service->dispatchDue());
    }

    private function notificationFor(string $email): Notification
    {
        $profile = $this->createMock(Profile::class);
        $profile->method('getName')->willReturn('Someone');

        $recipient = $this->createMock(User::class);
        $recipient->method('getEmail')->willReturn($email);
        $recipient->method('getLocale')->willReturn('en');
        $recipient->method('getProfile')->willReturn($profile);

        $notification = $this->createMock(Notification::class);
        $notification->method('getRecipient')->willReturn($recipient);
        $notification->method('getType')->willReturn(NotificationType::Mention);
        $notification->method('getAuthorName')->willReturn('Author');
        $notification->method('getChannelIdentifier')->willReturn('general');

        return $notification;
    }
}
