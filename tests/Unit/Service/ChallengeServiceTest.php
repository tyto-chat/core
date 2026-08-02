<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Challenge\CreateChallengeDto;
use App\Entity\Challenge;
use App\Exception\Challenge\InvalidChallengeException;
use App\Repository\ChallengeRepository;
use App\Service\Challenge\ChallengeService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class ChallengeServiceTest extends TestCase
{
    private ChallengeRepository&MockObject $challengeRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MailerInterface&MockObject $mailer;
    private TranslatorInterface&MockObject $translator;
    private ChallengeService $service;
    private bool $validateEmails = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->challengeRepository = $this->createMock(ChallengeRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->method('get')
            ->willReturnCallback(fn ($def) => match ($def->key) {
                Settings::emailChallengeExpiryMinutes()->key => 60,
                Settings::validateEmails()->key => $this->validateEmails,
                default => null,
            });

        $this->mailer = $this->createMock(MailerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->service = new ChallengeService(
            $this->challengeRepository,
            $settings,
            $this->mailer,
            $this->translator,
            new RequestStack(),
        );
        $this->service->setEntityManager($this->entityManager);
    }

    public function testNewCreatesAndSavesChallenge(): void
    {
        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Challenge::class));
        $this->entityManager->expects(self::once())->method('flush');

        $dto = new CreateChallengeDto('user@example.com');
        $challenge = $this->service->new($dto);

        self::assertSame('user@example.com', $challenge->getEmail());
        self::assertGreaterThan(new \DateTimeImmutable(), $challenge->getExpiresAt());
    }

    public function testNewSetsExpiryFromSettings(): void
    {
        $this->entityManager->method('persist');

        $dto = new CreateChallengeDto('user@example.com');
        $challenge = $this->service->new($dto);

        // Settings mock returns 60 minutes; assert the expiry is within [59m, 61m]
        self::assertGreaterThan(new \DateTimeImmutable('+59 minutes'), $challenge->getExpiresAt());
        self::assertLessThan(new \DateTimeImmutable('+61 minutes'), $challenge->getExpiresAt());
    }

    public function testNewSendsChallengeEmailWhenValidationEnabled(): void
    {
        $this->validateEmails = true;
        $this->entityManager->method('persist');
        $this->mailer->expects(self::once())->method('send');

        $this->service->new(new CreateChallengeDto('user@example.com'));
    }

    public function testChallengeEmailSubjectCarriesTheCode(): void
    {
        $this->validateEmails = true;
        $this->entityManager->method('persist');

        $subjectParams = null;
        $this->translator->method('trans')
            ->willReturnCallback(function (string $key, array $params) use (&$subjectParams): string {
                if ('challenge.subject' === $key) {
                    $subjectParams = $params;
                }

                return 'subject';
            });

        $challenge = $this->service->new(new CreateChallengeDto('user@example.com'));

        self::assertNotNull($subjectParams);
        self::assertSame($challenge->getPlainToken(), $subjectParams['%code%'] ?? null);
    }

    public function testNewSkipsEmailWhenValidationDisabled(): void
    {
        $this->entityManager->method('persist');
        $this->mailer->expects(self::never())->method('send');

        $this->service->new(new CreateChallengeDto('user@example.com'));
    }

    public function testGetThrowsWhenChallengeNotFound(): void
    {
        $this->challengeRepository->method('findLatestActiveByEmail')->willReturn(null);

        $this->expectException(InvalidChallengeException::class);
        $this->service->get('user@example.com', '123456');
    }

    public function testGetReturnsChallenge(): void
    {
        $challenge = new Challenge();
        $this->challengeRepository->method('findLatestActiveByEmail')->willReturn($challenge);

        self::assertSame($challenge, $this->service->get('user@example.com', $challenge->getPlainToken()));
    }

    public function testGetRecordsFailedAttemptOnWrongCode(): void
    {
        $challenge = new Challenge();
        $wrongCode = '000000' === $challenge->getPlainToken() ? '000001' : '000000';
        $this->challengeRepository->method('findLatestActiveByEmail')->willReturn($challenge);
        $this->entityManager->expects(self::once())->method('flush');

        try {
            $this->service->get('user@example.com', $wrongCode);
            self::fail('Expected InvalidChallengeException');
        } catch (InvalidChallengeException) {
        }

        self::assertSame(1, $challenge->getAttempts());
    }

    public function testGetThrowsWhenAttemptsExhausted(): void
    {
        $challenge = new Challenge();
        for ($i = 0; $i < 5; ++$i) {
            $challenge->recordFailedAttempt();
        }
        $this->challengeRepository->method('findLatestActiveByEmail')->willReturn($challenge);

        $this->expectException(InvalidChallengeException::class);
        $this->service->get('user@example.com', $challenge->getPlainToken());
    }

    public function testCodeIsSixDigits(): void
    {
        self::assertMatchesRegularExpression('/^\d{6}$/', new Challenge()->getPlainToken());
    }

    public function testConsumeThrowsWhenAlreadyUsed(): void
    {
        $challenge = $this->createMock(Challenge::class);
        $challenge->method('getUsedAt')->willReturn(new \DateTimeImmutable());

        $this->expectException(InvalidChallengeException::class);
        $this->service->consume($challenge);
    }

    public function testConsumeMarksUsedAtAndSaves(): void
    {
        $challenge = $this->createMock(Challenge::class);
        $challenge->method('getUsedAt')->willReturn(null);
        $challenge->expects(self::once())->method('setUsedAt')->with(self::isInstanceOf(\DateTimeImmutable::class));

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->consume($challenge);
    }
}
