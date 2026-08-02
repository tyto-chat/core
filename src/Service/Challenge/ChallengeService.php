<?php

declare(strict_types=1);

namespace App\Service\Challenge;

use App\Dto\Challenge\CreateChallengeDto;
use App\Entity\Challenge;
use App\Exception\Challenge\InvalidChallengeException;
use App\Repository\ChallengeRepository;
use App\Service\AbstractDoctrineService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChallengeService extends AbstractDoctrineService implements ChallengeServiceInterface
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ChallengeRepository $challengeRepository,
        private readonly SettingsServiceInterface $settings,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function new(CreateChallengeDto $createChallengeDto, ?int $expiryInMinutes = null): Challenge
    {
        $expiryInMinutes ??= $this->settings->get(Settings::emailChallengeExpiryMinutes());
        $challenge = new Challenge();
        $challenge->setExpiresAt(new \DateTimeImmutable(sprintf('+%d minutes', $expiryInMinutes)));

        $saved = $this->save($challenge, $createChallengeDto);

        if ($this->settings->get(Settings::validateEmails())) {
            $this->sendChallengeCreatedEmail($saved, $expiryInMinutes);
        }

        return $saved;
    }

    private function sendChallengeCreatedEmail(Challenge $challenge, int $expiryInMinutes): void
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        $email = new TemplatedEmail()
            ->to($challenge->getEmail())
            ->subject($this->translator->trans('challenge.subject', ['%code%' => $challenge->getPlainToken()], 'emails', $locale))
            ->htmlTemplate('emails/challenge.html.twig')
            ->locale($locale)
            ->context([
                'challenge' => $challenge,
                'expiryInMinutes' => $expiryInMinutes,
            ]);

        $this->mailer->send($email);
    }

    /**
     * @throws InvalidChallengeException
     */
    #[\Override]
    public function get(string $email, string $token): Challenge
    {
        $challenge = $this->resolve($email, $token);
        if (!$challenge) {
            throw new InvalidChallengeException('Invalid challenge');
        }

        return $challenge;
    }

    private function resolve(string $email, string $token): ?Challenge
    {
        $challenge = $this->challengeRepository->findLatestActiveByEmail($email);
        if (null === $challenge || $challenge->getAttempts() >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (!hash_equals($challenge->getToken(), hash('sha256', $token))) {
            $this->save($challenge->recordFailedAttempt());

            return null;
        }

        return $challenge;
    }

    /**
     * @throws InvalidChallengeException
     */
    #[\Override]
    public function consume(Challenge $challenge): void
    {
        if (null !== $challenge->getUsedAt()) {
            throw new InvalidChallengeException('Challenge already used');
        }

        $challenge->setUsedAt(new \DateTimeImmutable());
        $this->save($challenge);
    }

    #[\Override]
    public function isValid(string $email, string $token): bool
    {
        return null !== $this->resolve($email, $token);
    }
}
