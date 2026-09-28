<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailer;

use App\Entity\Challenge;
use App\Entity\ResetPasswordRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ClientContextEmailLinkTest extends KernelTestCase
{
    private const string BASE_URL = 'https://chat.example.com';

    /** @return iterable<string, array{string, string, bool}> */
    public static function linkMatrix(): iterable
    {
        yield 'setting off, web client' => ['', 'web', false];
        yield 'setting on, web client' => [self::BASE_URL, 'web', true];
        yield 'setting on, desktop client' => [self::BASE_URL, 'desktop', false];
        yield 'setting on, mobile client' => [self::BASE_URL, 'mobile', false];
    }

    #[DataProvider('linkMatrix')]
    public function testChallengeEmailLinksToTheWebAppForWebClientsOnly(string $baseUrl, string $client, bool $linked): void
    {
        $challenge = new Challenge();
        $challenge->setEmail('user@example.com');

        $html = $this->twig()->render('emails/challenge.html.twig', [
            'challenge' => $challenge,
            'expiryInMinutes' => 60,
            'client' => $client,
            'clientBaseUrl' => $baseUrl,
        ]);

        self::assertStringContainsString((string) $challenge->getPlainToken(), $html);
        self::assertSame($linked, str_contains($html, 'href="'.self::BASE_URL.'"'));
        self::assertSame($linked, str_contains($html, '<a '));
    }

    #[DataProvider('linkMatrix')]
    public function testPasswordResetEmailLinksToTheResetPageForWebClientsOnly(string $baseUrl, string $client, bool $linked): void
    {
        $request = new ResetPasswordRequest();
        $request->setEmail('user@example.com');

        $html = $this->twig()->render('emails/password_reset.html.twig', [
            'resetPasswordRequest' => $request,
            'expiryInMinutes' => 15,
            'client' => $client,
            'clientBaseUrl' => $baseUrl,
        ]);

        self::assertStringContainsString((string) $request->getPlainToken(), $html);
        self::assertSame($linked, str_contains($html, 'href="'.self::BASE_URL.'/reset-password?step=confirm"'));
        self::assertSame($linked, str_contains($html, '<a '));
    }

    public function testTemplatesStillRenderWithoutClientContext(): void
    {
        $challenge = new Challenge();
        $challenge->setEmail('user@example.com');

        $html = $this->twig()->render('emails/challenge.html.twig', [
            'challenge' => $challenge,
            'expiryInMinutes' => 60,
        ]);

        self::assertStringNotContainsString('<a ', $html);
    }

    private function twig(): Environment
    {
        return self::getContainer()->get(Environment::class);
    }
}
